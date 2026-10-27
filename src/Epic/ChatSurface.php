<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * The /epic chat surface: the command, its menu and inline keyboard, and the callbacks.
 */
final class ChatSurface
{
    private const int CALLBACK_DATA_MAX_BYTES = 64;
    private const string EPIC_RUNNING = 'running';
    private const string EPIC_PAUSED = 'paused';
    private const string EPIC_DONE = 'done';

    /** Allowed epic slug: it doubles as the plan file name and the signal directory name, so no `/` and no leading dot. */
    private const string EPIC_SLUG_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** How many start candidates the /epic list shows at most. */
    private const int EPIC_CANDIDATES_MAX = 10;

    public function __construct(
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly Config $config,
        private readonly GitManager $git,
        private readonly Card $card,
        private readonly Locale $locale,
    ) {
    }

    public function cmdEpic(int $chatId, int $threadId, string $argument): void
    {
        $words = preg_split('/\s+/', trim($argument), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sub = strtolower($words[0] ?? '');
        $epic = $this->db->epicByThread($chatId, $threadId);

        if ($sub === '') {
            $this->sendEpicMenu($chatId, $threadId, $epic);

            return;
        }

        $reply = match ($sub) {
            'start' => $this->epicStart($chatId, $threadId, $words[1] ?? '', $epic),
            'pause' => $this->epicPause($epic),
            'resume' => $this->epicResume($epic),
            'status' => $this->epicStatus($epic),
            default => $this->t('epic.usage'),
        };
        $this->tg->sendText($chatId, $reply, null, $threadId);
    }

    /**
     * /epic without an argument: a keyboard of the actions that would do something right now
     * (see epicButtons()); with no such action — "nothing to do" and the usage.
     *
     * @param array{slug:string, status:string}|null $epic
     */
    private function sendEpicMenu(int $chatId, int $threadId, ?array $epic): void
    {
        $buttons = $this->epicButtons($threadId, $epic);
        if ($buttons === []) {
            $this->tg->sendText($chatId, $this->t('epic.nothing_to_do')."\n".$this->t('epic.usage'), null, $threadId);

            return;
        }

        $header = $epic !== null
            ? $this->t('epic.status_header', ['slug' => $epic['slug'], 'status' => $epic['status']])
            : $this->t('epic.none');
        $this->tg->sendText($chatId, $header, ['inline_keyboard' => [$buttons]], $threadId);
    }

    /**
     * Buttons of the actions of the topic's epic that would not answer "nothing to do". The
     * conditions mirror the guards of epicPause(), epicResume() and epicStart(). A button whose
     * callback_data does not fit the Telegram limit is left out (the text form stays available).
     *
     * @param array{slug:string, status:string}|null $epic
     *
     * @return list<array{text: string, callback_data: string}>
     */
    private function epicButtons(int $threadId, ?array $epic): array
    {
        $actions = [];
        if ($epic === null || $epic['status'] === self::EPIC_DONE) {
            if ($this->epicCandidates($threadId)['slugs'] !== []) {
                $actions[] = ['🚀 start', "epic:list:{$threadId}"];
            }
            if ($epic !== null) {
                $actions[] = ['📊 status', "epic:status:{$threadId}:{$epic['slug']}"];
            }
        } else {
            $slug = $epic['slug'];
            $running = $epic['status'] === self::EPIC_RUNNING;
            if ($epic['status'] !== self::EPIC_PAUSED) {
                $actions[] = ['⏸ pause', "epic:pause:{$threadId}:{$slug}"];
            }
            if (!$running || $this->epicHasBlockedTasks($slug)) {
                $actions[] = ['▶️ resume', "epic:resume:{$threadId}:{$slug}"];
            }
            $actions[] = ['📊 status', "epic:status:{$threadId}:{$slug}"];
        }

        $buttons = [];
        foreach ($actions as [$text, $data]) {
            if (strlen($data) <= self::CALLBACK_DATA_MAX_BYTES) {
                $buttons[] = ['text' => $text, 'callback_data' => $data];
            }
        }

        return $buttons;
    }

    private function epicHasBlockedTasks(string $slug): bool
    {
        return array_filter($this->db->epicTasks($slug), static fn (array $t): bool => $t['status'] === 'blocked') !== [];
    }

    /**
     * Plans that can be started: `<EPICS_DIR>/*.md` without journals, with a valid slug and no
     * epic record, newest file first (ties by slug), at most EPIC_CANDIDATES_MAX. A plan whose
     * callback_data would not fit the Telegram limit is not listed; `skipped` tells about it.
     *
     * @return array{slugs: list<string>, skipped: bool}
     */
    private function epicCandidates(int $threadId): array
    {
        $found = [];
        foreach (glob($this->config->repoPath.'/'.$this->config->epicsDir.'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if (str_ends_with($file, '.journal.md') || preg_match(self::EPIC_SLUG_RE, $name) !== 1 || !is_file($file)) {
                continue;
            }
            if ($this->db->epicGet($name) !== null) {
                continue;
            }
            $found[] = ['slug' => $name, 'mtime' => (int) filemtime($file)];
        }
        usort($found, static fn (array $a, array $b): int => [$b['mtime'], $a['slug']] <=> [$a['mtime'], $b['slug']]);

        $slugs = [];
        $skipped = false;
        foreach (array_column($found, 'slug') as $slug) {
            // `pick` is the longest form a candidate appears in.
            if (strlen("epic:pick:{$threadId}:{$slug}") > self::CALLBACK_DATA_MAX_BYTES) {
                $skipped = true;

                continue;
            }
            if (count($slugs) < self::EPIC_CANDIDATES_MAX) {
                $slugs[] = $slug;
            }
        }

        return ['slugs' => $slugs, 'skipped' => $skipped];
    }

    /**
     * @param array{slug:string, status:string}|null $existing epic of this topic
     */
    private function epicStart(int $chatId, int $threadId, string $slug, ?array $existing): string
    {
        if ($slug === '' || preg_match(self::EPIC_SLUG_RE, $slug) !== 1) {
            return $this->t('epic.slug_required');
        }
        if ($existing !== null && $existing['status'] !== self::EPIC_DONE) {
            return $this->t('epic.topic_has_epic', ['slug' => $existing['slug'], 'status' => $existing['status']]);
        }
        if ($this->db->epicGet($slug) !== null) {
            return $this->t('epic.exists', ['slug' => $slug]);
        }

        $planPath = Run::planPath($this->config->repoPath, $this->config->epicsDir, $slug);
        try {
            $plan = Plan::load($planPath, $this->config->githubOwners);
        } catch (\InvalidArgumentException $e) {
            return $this->t('epic.plan_invalid', ['error' => $e->getMessage()]);
        }

        $this->db->epicCreate($slug, $chatId, $threadId, $plan->tasks, $plan->releases);
        $this->card->update($slug);

        return $this->t('epic.started', [
            'slug' => $slug,
            'tasks' => count($plan->tasks),
            'releases' => count($plan->releases),
            'plan' => $this->repoFileLink("{$this->config->epicsDir}/{$slug}.md"),
        ]);
    }

    /**
     * @param array{slug:string, status:string}|null $epic
     */
    private function epicPause(?array $epic): string
    {
        if ($epic === null) {
            return $this->t('epic.none');
        }
        if ($epic['status'] === self::EPIC_DONE) {
            return $this->t('epic.already_done', ['slug' => $epic['slug']]);
        }
        if ($epic['status'] === self::EPIC_PAUSED) {
            return $this->t('epic.already_paused', ['slug' => $epic['slug']]);
        }
        $this->db->epicSetStatus($epic['slug'], self::EPIC_PAUSED);
        $this->card->update($epic['slug']);

        return $this->t('epic.paused', ['slug' => $epic['slug']]);
    }

    /**
     * @param array{slug:string, status:string}|null $epic
     */
    private function epicResume(?array $epic): string
    {
        if ($epic === null) {
            return $this->t('epic.none');
        }
        if ($epic['status'] === self::EPIC_DONE) {
            return $this->t('epic.already_done', ['slug' => $epic['slug']]);
        }
        $running = $epic['status'] === self::EPIC_RUNNING;
        if ($running && array_filter($this->db->epicTasks($epic['slug']), static fn (array $t): bool => $t['status'] === 'blocked') === []) {
            return $this->t('epic.not_paused', ['slug' => $epic['slug'], 'status' => $epic['status']]);
        }
        $unblocked = $this->db->epicResume($epic['slug']);
        $this->card->update($epic['slug']);
        if ($running) {
            return $this->t('epic.running_unblocked', ['slug' => $epic['slug'], 'tasks' => implode(', ', $unblocked)]);
        }

        return $this->t('epic.resumed', ['slug' => $epic['slug']]);
    }

    /**
     * @param array{slug:string, status:string, blocked_reason:?string, done:bool, fingerprint:?string, last_poll_at:int}|null $epic
     */
    private function epicStatus(?array $epic): string
    {
        if ($epic === null) {
            return $this->t('epic.none');
        }

        $tasks = $this->db->epicTasks($epic['slug']);
        $lines = [
            $this->t('epic.status_header', ['slug' => $epic['slug'], 'status' => $epic['status']]),
            $this->t('epic.status_counts', [
                'tasks' => count($tasks),
                'plan_tasks' => count(array_filter($tasks, static fn (array $t): bool => $t['source'] === 'plan')),
                'releases' => count($this->db->epicReleases($epic['slug'])),
            ]),
        ];
        $hold = array_column(array_filter($tasks, static fn (array $t): bool => $t['hold']), 'task');
        if ($hold !== []) {
            $lines[] = $this->t('epic.status_hold', ['tasks' => implode(', ', $hold)]);
        }
        $blocked = array_column(array_filter($tasks, static fn (array $t): bool => $t['status'] === 'blocked'), 'task');
        if ($blocked !== []) {
            $lines[] = $this->t('epic.status_blocked', ['tasks' => implode(', ', $blocked)]);
        }
        if ($epic['blocked_reason'] !== null) {
            $lines[] = $this->t('epic.status_reason', ['reason' => $epic['blocked_reason']]);
        }
        if ($epic['done']) {
            $lines[] = $this->t('epic.status_final_done');
        }
        $lines[] = $this->t('epic.status_last_poll', ['time' => Card::formatTime($epic['last_poll_at'], $this->locale)]);
        $lines[] = $this->t('epic.status_plan', ['plan' => $this->repoFileLink($this->config->epicsDir.'/'.$epic['slug'].'.md')]);
        $journal = $this->config->epicsDir.'/'.$epic['slug'].'.journal.md';
        // The journal is created by the epic's first run; before that the link would 404.
        // Under AUTO_COMMIT=0 nothing is ever pushed, so the journal stays a local file and
        // the GitHub link would always 404: show the plain path. Otherwise best effort: the
        // local copy is checked - until a run has pushed the journal, the link may still 404.
        $journalText = $this->t('epic.journal_missing', ['path' => $journal]);
        if (is_file($this->config->repoPath.'/'.$journal)) {
            $journalText = $this->config->autoCommit ? $this->repoFileLink($journal) : $journal;
        }
        $lines[] = $this->t('epic.status_journal', ['journal' => $journalText]);

        return implode("\n", $lines);
    }

    /**
     * A file path in REPO_PATH as a GitHub link (Markdown), so that it is
     * clickable in Telegram; without a GitHub remote - just the path.
     */
    private function repoFileLink(string $path): string
    {
        $url = $this->git->githubFileUrl($path);

        return $url !== null ? "[{$path}]({$url})" : $path;
    }

    /**
     * The /epic keyboard branch. Forms of callback_data: `epic:<pause|resume|status>:<thread>:<slug>`,
     * `epic:list:<thread>`, `epic:pick:<thread>:<slug>`, `epic:go:<thread>:<slug>`, `epic:cancel:<thread>`.
     * The topic comes from callback_data (like for /mode), the chat from the update. The state is
     * checked afresh on every tap; an action on the topic's epic needs its slug to match the
     * topic's current epic, otherwise the keyboard is stale and nothing is done.
     *
     * @param array<string, mixed> $message
     */
    public function handleEpicCallback(string $callbackId, int $chatId, array $message, string $data): void
    {
        $parts = explode(':', $data);
        $action = $parts[1] ?? '';
        $withSlug = in_array($action, ['pause', 'resume', 'status', 'pick', 'go'], true);
        $valid = count($parts) === ($withSlug ? 4 : 3)
            && ($withSlug || in_array($action, ['list', 'cancel'], true))
            && ctype_digit($parts[2])
            && (!$withSlug || preg_match(self::EPIC_SLUG_RE, $parts[3]) === 1);
        if (!$valid) {
            $this->tg->answerCallback($callbackId, $this->t('epic.keyboard_stale'));

            return;
        }

        $threadId = (int) $parts[2];
        $slug = $parts[3] ?? '';
        $messageId = (int) ($message['message_id'] ?? 0);
        $epic = $this->db->epicByThread($chatId, $threadId);
        $canStart = $epic === null || $epic['status'] === self::EPIC_DONE;

        switch ($action) {
            case 'cancel':
                $this->tg->answerCallback($callbackId);
                $this->tg->editMessageText($chatId, $messageId, $this->t('epic.start_cancelled'), ['inline_keyboard' => []]);

                return;

            case 'list':
                if (!$canStart) {
                    $this->tg->answerCallback($callbackId, $this->t('epic.keyboard_stale'));

                    return;
                }
                $this->tg->answerCallback($callbackId);
                $this->editEpicCandidates($chatId, $messageId, $threadId);

                return;

            case 'pick':
                if (!$canStart) {
                    $this->tg->answerCallback($callbackId, $this->t('epic.keyboard_stale'));

                    return;
                }
                $this->tg->answerCallback($callbackId);
                $this->editEpicConfirmation($chatId, $messageId, $threadId, $slug);

                return;

            case 'go':
                $reply = $this->epicStart($chatId, $threadId, $slug, $epic);

                break;

            default:
                if ($epic === null || $epic['slug'] !== $slug) {
                    $this->tg->answerCallback($callbackId, $this->t('epic.keyboard_stale'));

                    return;
                }
                $reply = match ($action) {
                    'pause' => $this->epicPause($epic),
                    'resume' => $this->epicResume($epic),
                    default => $this->epicStatus($epic),
                };
        }

        $this->tg->answerCallback($callbackId);
        $this->tg->sendText($chatId, $reply, null, $threadId);
        // The buttons are spent: the same text, no keyboard.
        $shown = (string) ($message['text'] ?? '');
        $this->tg->editMessageText($chatId, $messageId, $shown !== '' ? $shown : $reply, ['inline_keyboard' => []]);
    }

    /** The "pick a plan" step: plain text, one button per candidate. */
    private function editEpicCandidates(int $chatId, int $messageId, int $threadId): void
    {
        $candidates = $this->epicCandidates($threadId);
        if ($candidates['slugs'] === []) {
            $this->tg->editMessageText($chatId, $messageId, $this->t('epic.no_candidates'), ['inline_keyboard' => []]);

            return;
        }

        $rows = [];
        foreach ($candidates['slugs'] as $slug) {
            $rows[] = [['text' => $slug, 'callback_data' => "epic:pick:{$threadId}:{$slug}"]];
        }
        $rows[] = [['text' => $this->t('epic.cancel_button'), 'callback_data' => "epic:cancel:{$threadId}"]];

        $text = $this->t('epic.pick_header');
        if ($candidates['skipped']) {
            $text .= "\n".$this->t('epic.long_names_hint');
        }
        $this->tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $rows]);
    }

    /** The confirmation step: plain text with a bare plan URL; an invalid plan gets the error and no start button. */
    private function editEpicConfirmation(int $chatId, int $messageId, int $threadId, string $slug): void
    {
        if ($this->db->epicGet($slug) !== null) {
            $this->tg->editMessageText($chatId, $messageId, $this->t('epic.exists', ['slug' => $slug]), ['inline_keyboard' => []]);

            return;
        }

        $path = "{$this->config->epicsDir}/{$slug}.md";
        try {
            $plan = Plan::load(Run::planPath($this->config->repoPath, $this->config->epicsDir, $slug), $this->config->githubOwners);
        } catch (\InvalidArgumentException $e) {
            $this->tg->editMessageText($chatId, $messageId, $this->t('epic.plan_invalid', ['error' => $e->getMessage()]), ['inline_keyboard' => []]);

            return;
        }

        $text = $this->t('epic.confirm_start', [
            'slug' => $slug,
            'tasks' => count($plan->tasks),
            'releases' => count($plan->releases),
            'plan' => $this->git->githubFileUrl($path) ?? $path,
        ]);
        $this->tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => [[
            ['text' => $this->t('epic.start_button'), 'callback_data' => "epic:go:{$threadId}:{$slug}"],
            ['text' => $this->t('epic.cancel_button'), 'callback_data' => "epic:cancel:{$threadId}"],
        ]]]);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->locale->t($key, $params);
    }
}
