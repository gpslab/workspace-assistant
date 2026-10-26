<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\EditStatus;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * Epic status card: one silent message in the topic that the bot edits
 * in place. Built only from what the poll has already gathered ({@see TaskState}),
 * the SQLite rows and the journal in `REPO_PATH`; it does not go to GitHub.
 */
final class Card
{
    /** The poll time in the text is refreshed no more often than this (seconds). */
    public const int POLL_TIME_REFRESH_SEC = 300;

    private const int JOURNAL_MAX = 300;

    /** @var array<string, array<string, TaskState>> last task states per epic */
    private array $states = [];
    /** @var array<string, int> poll time shown in the card */
    private array $shownPollAt = [];
    /** @var array<string, string> last sent text */
    private array $lastText = [];

    public function __construct(
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly string $repoPath,
        private readonly string $epicsDir,
        private readonly int $tickInterval,
        private readonly Locale $locale = new Locale(),
    ) {
    }

    public static function formatTime(int $at, Locale $locale = new Locale()): string
    {
        return $at > 0 ? date('d.m H:i', $at) : $locale->t('card.never');
    }

    /**
     * Update the epic card: send it (if there is none yet) or edit it. A Telegram failure
     * does not interrupt the epic.
     *
     * @param array<string, TaskState>|null $states fresh states from the poll; null — take the last known
     * @param bool                          $polled the GitHub poll has just succeeded
     */
    public function update(string $slug, ?array $states = null, bool $polled = false, ?int $now = null): void
    {
        try {
            $this->doUpdate($slug, $states, $polled, $now ?? time());
        } catch (\Throwable $e) {
            Log::line("epic {$slug}: status card not updated — ".$e->getMessage());
        }
    }

    /**
     * @param array<string, TaskState>|null $states
     */
    private function doUpdate(string $slug, ?array $states, bool $polled, int $now): void
    {
        $epic = $this->db->epicGet($slug);
        if ($epic === null) {
            return;
        }
        if ($states !== null) {
            $this->states[$slug] = $states;
        }
        $this->shownPollAt[$slug] ??= $epic['last_poll_at'];
        if ($polled && $epic['last_poll_at'] - $this->shownPollAt[$slug] >= self::POLL_TIME_REFRESH_SEC) {
            $this->shownPollAt[$slug] = $epic['last_poll_at'];
        }

        $text = mb_substr($this->render($epic, $this->states[$slug] ?? [], $this->shownPollAt[$slug]), 0, 4000);
        if ($epic['card_message_id'] > 0 && ($this->lastText[$slug] ?? null) === $text) {
            return;
        }
        if ($epic['card_message_id'] > 0) {
            $status = $this->tg->editMessageText($epic['chat_id'], $epic['card_message_id'], $text);
            if ($status === EditStatus::Ok) {
                $this->lastText[$slug] = $text;
            }
            if ($status !== EditStatus::Gone) {
                return;
            }
        }
        $this->send($slug, $epic['chat_id'], $epic['thread_id'], $text);
    }

    private function send(string $slug, int $chatId, int $threadId, string $text): void
    {
        $id = $this->tg->sendPlain($chatId, $text, $threadId, true);
        if ($id <= 0) {
            return;
        }
        $this->db->epicSetCard($slug, $id);
        $this->lastText[$slug] = $text;
        // Pin if possible: without rights the card simply stays unpinned.
        try {
            $this->tg->pinChatMessage($chatId, $id);
        } catch (\Throwable $e) {
            Log::line("epic {$slug}: cannot pin the card — ".$e->getMessage());
        }
    }

    /**
     * @param array{slug:string, status:string, blocked_reason:?string, last_tick_at:int} $epic
     * @param array<string, TaskState>                                                    $states
     */
    private function render(array $epic, array $states, int $pollAt): string
    {
        $slug = $epic['slug'];
        $lines = [
            $this->locale->t('card.header', ['slug' => $slug, 'status' => $epic['status']]),
            $this->locale->t('card.last_poll', ['time' => self::formatTime($pollAt, $this->locale)]),
        ];
        if ($epic['status'] === 'blocked' && $epic['blocked_reason'] !== null) {
            $lines[] = $this->locale->t('card.blocked_reason', ['reason' => $epic['blocked_reason']]);
        }
        if ($epic['status'] === 'running') {
            $inProgress = array_any($states, static fn (TaskState $s): bool => $s->hasLabel(Orchestrator::LABEL_IN_PROGRESS));
            $lines[] = $this->locale->t('card.next_tick', ['when' => match (true) {
                $inProgress => $this->locale->t('card.tick_not_scheduled'),
                $epic['last_tick_at'] <= 0 => $this->locale->t('card.tick_next_poll'),
                default => $this->locale->t('card.tick_at', ['time' => self::formatTime($epic['last_tick_at'] + $this->tickInterval, $this->locale)]),
            }]);
        }
        $lines[] = '';
        $lines[] = $this->locale->t('card.tasks');
        foreach ($this->db->epicTasks($slug) as $task) {
            $lines[] = '• '.$task['task'].' — '.$this->stage($task, $states);
        }
        $lines[] = '';
        $lines[] = $this->locale->t('card.journal', ['entry' => $this->lastJournalEntry($slug) ?? $this->locale->t('card.journal_empty')]);

        return implode("\n", $lines);
    }

    /**
     * @param array{task:string, after:list<string>, hold:bool, status:string} $task
     * @param array<string, TaskState>                                         $states
     */
    private function stage(array $task, array $states): string
    {
        if ($task['status'] === 'blocked') {
            return $this->locale->t('card.stage.blocked');
        }
        $s = $states[$task['task']] ?? null;
        if ($s === null) {
            return $task['hold'] ? $this->locale->t('card.stage.hold') : $this->locale->t('card.stage.waiting_poll');
        }

        return match (true) {
            $s->prState === TaskState::MERGED => $this->locale->t('card.stage.merged'),
            $s->prState === TaskState::CLOSED => $this->locale->t('card.stage.closed'),
            $s->hasLabel(Orchestrator::LABEL_IN_PROGRESS) => $this->locale->t('card.stage.in_progress'),
            $s->hasLabel(Orchestrator::LABEL_AWAITING_ANSWER) => $this->locale->t('card.stage.awaiting_answer'),
            $s->hasLabel(Orchestrator::LABEL_AWAITING_ACTION) => $this->locale->t('card.stage.awaiting_action'),
            $s->prState === TaskState::DRAFT => $this->locale->t('card.stage.draft'),
            $s->prState === TaskState::OPEN => $this->prStage($s),
            !$s->issueOpen => $this->locale->t('card.stage.issue_closed'),
            $task['hold'] => $this->locale->t('card.stage.hold'),
            ($waiting = $this->unmetAfter($task['after'], $states)) !== [] => $this->locale->t('card.stage.after', ['tasks' => implode(', ', $waiting)]),
            $s->hasLabel(Orchestrator::LABEL_DO_IT) => $this->locale->t('card.stage.handed_over'),
            default => $this->locale->t('card.stage.waiting_start'),
        };
    }

    private function prStage(TaskState $s): string
    {
        return match (true) {
            $s->epicVerdict === 'APPROVED' => $this->locale->t('card.stage.approved'),
            $s->epicVerdict === 'CHANGES_REQUESTED' => $this->locale->t('card.stage.changes_requested'),
            $s->checks === TaskState::CHECKS_PENDING => $this->locale->t('card.stage.ci_pending'),
            $s->checks === TaskState::CHECKS_FAILED => $this->locale->t('card.stage.ci_failed'),
            default => $this->locale->t('card.stage.review'),
        };
    }

    /**
     * @param list<string>             $after
     * @param array<string, TaskState> $states
     *
     * @return list<string>
     */
    private function unmetAfter(array $after, array $states): array
    {
        return array_values(array_filter($after, static fn (string $ref): bool => ($states[$ref]->prState ?? null) !== TaskState::MERGED));
    }

    /** The last item of the "Timeline" section (the legacy Russian heading is also recognised) of the epic journal, truncated to ~300 characters. */
    private function lastJournalEntry(string $slug): ?string
    {
        $raw = @file_get_contents(Run::journalPath($this->repoPath, $this->epicsDir, $slug));
        if ($raw === false) {
            return null;
        }
        $inSection = false;
        $last = null;
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(.*)$/u', $line, $m) === 1) {
                $inSection = in_array(mb_strtolower(trim($m[1])), ['хронология', 'timeline'], true);

                continue;
            }
            if ($inSection && preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*\S)\s*$/u', $line, $m) === 1) {
                $last = $m[1];
            }
        }

        return $last === null ? null : (mb_strlen($last) > self::JOURNAL_MAX ? mb_substr($last, 0, self::JOURNAL_MAX - 1).'…' : $last);
    }
}
