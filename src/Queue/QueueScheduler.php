<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Queue;

use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Command\LocaleResolver;
use GpsLab\WorkspaceAssistant\Command\QueueControl;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\EditStatus;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\RunPrompt;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;
use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Inbox;
use GpsLab\WorkspaceAssistant\JobLauncher;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\PmRunbook;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\RunKind;
use GpsLab\WorkspaceAssistant\RunState;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * The request queue: enqueueing, the start of the queue head, retries and the pauses (Claude limit,
 * dirty tree). A run is started through {@see JobLauncher}; the epic run prompt comes from {@see RunPrompt}.
 */
final class QueueScheduler implements QueueControl
{
    /** Queue item kinds (queue.kind column): a regular request and the /new, /compact barriers. */
    private const string ITEM_CHAT = 'chat';
    private const string ITEM_NEW = QueueTexts::ITEM_NEW;
    private const string ITEM_COMPACT = QueueTexts::ITEM_COMPACT;
    private const string ITEM_EPIC = QueueTexts::ITEM_EPIC;

    private const string EPIC_RUNNING = 'running';

    /** How many seconds to wait before retrying the head start after a transient failure. */
    private const int RETRY_DELAY_SEC = 60;

    private const int MAX_START_RETRIES = 10;

    /** Bot API getFile download limit. */
    private const int MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    /** The item whose placeholder was already edited to "Thinking…" (do not edit on every pause/retry tick). */
    private ?int $editedItemId = null;
    private ?int $retryItemId = null;
    private int $retryCount = 0;

    /** Result of the last check in startQueueHead(): tree is dirty → queue is paused (only for the tick timeout calculation). */
    private bool $treePaused = false;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly GitManager $git,
        private readonly SessionFiles $sessionFiles,
        private readonly Delivery $delivery,
        private readonly Locale $locale,
        private readonly LocaleResolver $locales,
        private readonly QueueTexts $queueTexts,
        private readonly DirtyTreeDecision $dirtyTree,
        private readonly RunState $runState,
        private readonly RunPrompt $runPrompt,
        private readonly Inbox $inbox,
        private readonly SubRepoSync $subRepoSync,
        private readonly JobLauncher $launcher,
    ) {
    }

    /** Result of the last check in {@see startQueueHead()}: the tree is dirty, so the queue is paused. */
    public function isTreePaused(): bool
    {
        return $this->treePaused;
    }

    /**
     * Interface text of the selected locale by key, with named `{placeholders}` substituted.
     *
     * @param array<string, scalar> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->locale->t($key, $params);
    }

    public function resetTopicSession(int $chatId, int $threadId): void
    {
        // The transcript of the session being reset is no longer needed on disk (it could be returned to
        // only via `claude --resume` from the console) — delete it.
        $old = $this->db->getSessionId($chatId, $threadId);
        $this->db->resetSession($chatId, $threadId);
        if ($old !== null) {
            $this->sessionFiles->delete($old);
        }
        $this->tg->sendText($chatId, $this->t('session.new_started'), null, $threadId);
    }

    /**
     * The queue pause reason or null. A pause on a dirty tree is DERIVED from
     * state (AUTO_COMMIT=1, queue is non-empty, no job, tree is dirty), not stored as
     * an in-memory flag.
     */
    public function pauseReason(): ?string
    {
        $head = $this->db->queueHead();
        if ($head === null) {
            return null;
        }

        $limitUntil = $this->db->getLimitPauseUntil();
        if ($limitUntil > time()) {
            return $this->t('pause.limit', ['until' => date('H:i d.m', $limitUntil)]);
        }

        $statusError = null;
        $dirtyPause = $this->isPausedByDirtyTree($statusError);
        if ($statusError !== null) {
            return $this->t('pause.status_failed', ['error' => $statusError]);
        }
        if ($dirtyPause) {
            $decision = $this->db->getDecision();

            return $decision !== null
                ? $this->t('pause.dirty_tree_in_topic', ['topic' => $this->queueTexts->topicLabel($decision['chat_id'], $decision['thread_id'])])
                : $this->t('pause.dirty_tree');
        }

        $retry = $this->db->getRetry();
        if ($retry !== null && $retry['item'] === $head['id'] && $retry['at'] > time()) {
            return $this->t('pause.start_retry', ['seconds' => $retry['at'] - time()]);
        }

        return null;
    }

    /**
     * AUTO_COMMIT=1, queue is non-empty, no job, tree is dirty. A tree whose status cannot be read is
     * not a dirty-tree pause: false, and $statusError carries the reason.
     */
    private function isPausedByDirtyTree(?string &$statusError = null): bool
    {
        if ($this->runState->activeJob !== null || ($head = $this->db->queueHead()) === null) {
            return false;
        }

        try {
            return $this->config->autoCommit && $this->git->hasChanges();
        } catch (GitStatusException $e) {
            $statusError = $e->getMessage();

            return false;
        }
    }

    /**
     * Enqueues a message (text or files). Merging: consecutive pending
     * messages of the SAME topic with the same pinned mode and language are
     * appended to the topic's last pending item (until the group is closed by
     * /mode, /new or /compact). A message to a topic whose job is already running
     * is not merged with it - it becomes a new item at the end of the shared queue.
     *
     * @param list<array<string, mixed>> $parts
     */
    public function enqueueMessage(int $chatId, int $threadId, int $messageId, array $parts, int $userId = 0): void
    {
        // Update redelivery: any message_id of the message (including an album
        // frame or a previously merged message) already belongs to an item.
        foreach ($parts as $part) {
            $ids = [(int) ($part['message_id'] ?? 0), ...array_map('intval', (array) ($part['message_ids'] ?? []))];
            foreach ($ids as $id) {
                if ($id > 0 && $this->db->queueHasMessage($chatId, $id)) {
                    return;
                }
            }
        }

        $mode = $this->db->getMode($chatId, $threadId);
        $localeCode = $this->locales->userLocale($userId);
        $last = $this->db->queueLastWaiting($chatId, $threadId);
        if ($last !== null && $last['kind'] === self::ITEM_CHAT && !$last['closed'] && $last['mode'] === $mode
            && ($last['locale'] ?? $this->locale->code()) === $localeCode) {
            $added = false;
            foreach ($parts as $part) {
                $added = $this->db->queueAppendPart($last['id'], $part) || $added;
            }
            if ($added) {
                $this->tg->sendText($chatId, $this->t('queue.appended'), null, $threadId);
            }

            return;
        }

        $this->enqueueItem($chatId, $threadId, $messageId, self::ITEM_CHAT, $mode, $parts, $localeCode);
    }

    /**
     * A new item at the end of the queue. On enqueue only access and
     * size limits are checked; everything else happens when the head starts. The mode is pinned
     * here and does not change afterwards. The "queued: Nth" placeholder is written once,
     * without later renumbering (/queue gives the current picture).
     *
     * @param list<array<string, mixed>> $parts
     */
    private function enqueueItem(int $chatId, int $threadId, int $messageId, string $kind, string $mode, array $parts, ?string $localeCode = null): void
    {
        if ($messageId <= 0) {
            // Command without a message_id (tests/synthetic): the key must be unique.
            $messageId = -(int) (microtime(true) * 1_000_000);
        }
        if ($this->db->queueHasMessage($chatId, $messageId)) {
            return;
        }

        $position = count($this->db->queueWaiting()) + 1 + ($this->runState->activeJob !== null ? 1 : 0);
        $idle = $position === 1 && $this->db->getLimitPauseUntil() <= time();
        $text = $idle ? $this->startText($kind, $mode) : $this->t('queue.waiting', ['position' => $this->t('queue.position', ['position' => $position])]);

        $placeholderId = $this->tg->sendText($chatId, $text, null, $threadId);
        if ($this->db->queueAdd($chatId, $threadId, $messageId, $kind, $mode, $parts, $placeholderId, $localeCode) === null) {
            $this->tg->deleteMessage($chatId, $placeholderId);
        }
    }

    /**
     * Enqueues an isolated epic run (tick, hot PR review or final
     * check) into the shared FIFO queue. No "queued" placeholder - a service
     * run does not make noise in the topic; it is not merged with the author's messages (a separate
     * kind). Mode by run kind: tick/final - pm, review - review.
     *
     * @param string|null $pr PR of the form repo#N for a hot review
     *
     * @return bool false if there is no epic, it is not running or the run kind is unknown
     */
    public function enqueueEpicRun(string $slug, string $run, ?string $pr = null): bool
    {
        $epic = $this->db->epicGet($slug);
        if ($epic === null || $epic['status'] !== self::EPIC_RUNNING || !Run::isRun($run)) {
            return false;
        }

        $part = ['slug' => $slug, 'run' => $run];
        if ($pr !== null) {
            $part['pr'] = $pr;
        }

        return $this->db->queueAdd(
            $epic['chat_id'],
            $epic['thread_id'],
            -(int) (microtime(true) * 1_000_000),
            self::ITEM_EPIC,
            Run::modeFor($run),
            [$part],
            locale: $this->locale->code(),
        ) !== null;
    }

    private function startText(string $kind, string $mode): string
    {
        return match ($kind) {
            self::ITEM_COMPACT => $this->t('start.compact'),
            self::ITEM_EPIC => $this->t('start.epic'),
            default => $this->queueTexts->thinkingText($mode),
        };
    }

    /**
     * Enqueues files (a single file is an album of one item; a matured
     * album - all frames as one message part). The size limit is checked against
     * all items before enqueueing: one oversized item fails the whole album
     * (the caption applies to all files, an incomplete album must not go to
     * Claude). Files are downloaded when the run STARTS, not here - otherwise the inbox
     * accumulates the queue tail. Downloaded files are deleted after the run in
     * Bot::pollJob()/cancelTopic().
     *
     * @param list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}> $files
     * @param list<int>                                                                                    $messageIds
     */
    public function enqueueFiles(int $chatId, int $threadId, int $messageId, ?string $caption, array $files, array $messageIds = [], int $userId = 0): void
    {
        foreach ($files as $file) {
            if ($file['file_size'] !== null && $file['file_size'] > self::MAX_DOWNLOAD_BYTES) {
                $this->tg->sendText(
                    $chatId,
                    $this->t('file.too_big_named', ['name' => $file['name'] ?? $this->t('file.unnamed')]),
                    null,
                    $threadId,
                );

                return;
            }
        }

        $this->enqueueMessage($chatId, $threadId, $messageId, [[
            'message_id' => $messageId,
            'message_ids' => $messageIds,
            'text' => $caption,
            'files' => $files,
        ]], $userId);
    }

    public function waiting(): array
    {
        return $this->db->queueWaiting();
    }

    public function enqueueNew(int $chatId, int $threadId, int $messageId, string $mode, string $localeCode): void
    {
        $this->enqueueItem($chatId, $threadId, $messageId, self::ITEM_NEW, $mode, [], $localeCode);
    }

    public function enqueueCompact(int $chatId, int $threadId, int $messageId, string $localeCode): void
    {
        $this->enqueueItem($chatId, $threadId, $messageId, self::ITEM_COMPACT, SystemPrompt::MODE_ARCH, [], $localeCode);
    }

    /** The topic has an active or pending item. */
    public function topicBusy(int $chatId, int $threadId): bool
    {
        return $this->runState->hasActiveJob($chatId, $threadId) || $this->db->queueLastWaiting($chatId, $threadId) !== null;
    }

    /**
     * Unix time until which the head does not start because of a timer (pause on Claude
     * limit or retry after a temporary failure); 0 - no timers.
     */
    public function queueBlockedUntil(): int
    {
        $until = $this->db->getLimitPauseUntil();
        $head = $this->db->queueHead();
        $retry = $this->db->getRetry();
        if ($retry !== null && $head !== null && $retry['item'] === $head['id']) {
            $until = max($until, $retry['at']);
        }

        return $until > time() ? $until : 0;
    }

    /**
     * Start of the queue head. The order of checks is strict: placeholder edit ->
     * tree cleanliness (dirty -> pause) -> sync -> session_id (read HERE, not
     * at enqueue) -> "is there anything to compact" -> file download -> claude launch.
     *
     * Failure policy: temporary (pull/fetch, launch, network download) -
     * the item stays the head, retry in 60 s, one message to the topic;
     * final (/compact without a session, file unavailable, topic deleted) -
     * the item is dropped.
     */
    public function startQueueHead(): void
    {
        if ($this->runState->activeJob !== null) {
            return;
        }
        $this->releaseStaleDirtyState();
        $head = $this->db->queueHead();
        if ($head === null) {
            $this->treePaused = false;

            return;
        }

        try {
            $this->tryStartQueueHead($head);
        } catch (\Throwable $e) {
            // Any start exception is a temporary failure: otherwise the tick repeats
            // every second, and the head blocks the queue forever. We work
            // only with the item that started ($head taken BEFORE side
            // effects): if it is already dropped (/new barrier, dropItem) or a job
            // is running - schedule no retry for anyone, do not touch the next item.
            Log::line('queue: exception while starting head '.$head['id'].': '.$e->getMessage());
            if ($this->runState->activeJob !== null) {
                return;
            }
            $current = $this->db->queueGet($head['id']);
            if ($current === null) {
                return;
            }
            if ($current['running']) {
                $this->db->queueRelease($current['id']);
            }
            $this->scheduleRetry($current, $this->t('queue.start_failed', ['error' => $e->getMessage()]));
        }
    }

    /**
     * The tree is clean, but the dirt owner/decision are still recorded (manual cleanup with
     * an empty queue or on pause): reset them, otherwise the next external dirt
     * would go with buttons into the old topic with someone else's commit message.
     */
    private function releaseStaleDirtyState(): void
    {
        if (!$this->config->autoCommit || ($this->db->getDirtyOwner() === null && $this->db->getDecision() === null)) {
            return;
        }
        try {
            $dirty = $this->git->hasChanges();
        } catch (GitStatusException $e) {
            // Unknown state: neither clean nor dirty, so nothing is released.
            Log::line('git status failed, dirty state kept: '.$e->getMessage());

            return;
        }
        if (!$dirty) {
            $this->db->clearDecision();
            $this->db->clearDirtyOwner();
        }
    }

    /**
     * @param array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool} $head
     */
    private function tryStartQueueHead(array $head): void
    {
        $this->treePaused = false;
        if ($this->queueBlockedUntil() > 0) {
            return;
        }

        $chatId = $head['chat_id'];
        $threadId = $head['thread_id'];

        // 1. Placeholder: "no message/topic" -> the item is dropped, claude is not
        //    started and no quota is spent. The /new barrier without claude is not edited.
        if ($head['kind'] !== self::ITEM_NEW && $head['placeholder_id'] > 0 && $this->editedItemId !== $head['id']) {
            $status = $this->tg->editMessageText($chatId, $head['placeholder_id'], $this->startText($head['kind'], $head['mode']));
            if ($status === EditStatus::Gone) {
                Log::line("queue: placeholder/topic of item {$head['id']} deleted, item dropped");
                $this->dropItem($head, $this->t('queue.placeholder_gone'));

                return;
            }
            if ($status === EditStatus::Failed) {
                $this->scheduleRetry($head, $this->t('queue.telegram_unreachable'));

                return;
            }
            $this->editedItemId = $head['id'];
        }

        // 2. Tree cleanliness: dirty -> pause (derived from state). The commit/discard
        //    buttons are issued on entering the pause; manual cleanup lifts it.
        //    With AUTO_COMMIT=0 the tree is the operator's: no check and no pause.
        if ($this->config->autoCommit) {
            try {
                $dirty = $this->git->hasChanges();
            } catch (GitStatusException $e) {
                $this->scheduleRetry($head, $this->t('queue.status_failed', ['error' => $e->getMessage()]));

                return;
            }
            if ($dirty) {
                $this->treePaused = true;
                $this->dirtyTree->ensureDecision($chatId, $threadId);

                return;
            }
            $this->db->clearDecision();
            $this->db->clearDirtyOwner();
        }

        if ($head['kind'] === self::ITEM_NEW) {
            $this->db->queueRemove($head['id']);
            $this->tg->deleteMessage($chatId, $head['placeholder_id']);
            $this->resetTopicSession($chatId, $threadId);

            return;
        }

        // 3. Sync.
        $syncError = $this->syncManagedRepo($chatId, $threadId);
        if ($syncError !== null) {
            $this->scheduleRetry($head, $this->t('queue.sync_failed', ['error' => $syncError]));

            return;
        }

        // 3a. Isolated epic run: no session_id and --resume, does not touch the topic's session.
        if ($head['kind'] === self::ITEM_EPIC) {
            $this->startEpicItem($head);

            return;
        }

        // 4. session_id - only now: while waiting it could have been changed by
        //    a run of the same topic.
        $sessionId = $this->db->getSessionId($chatId, $threadId);

        // 5. "Is there anything to compact".
        if ($head['kind'] === self::ITEM_COMPACT) {
            if ($sessionId === null) {
                $this->dropItem($head, $this->t('compact.no_session'));

                return;
            }
            try {
                $summarizePrompt = PromptFiles::load('compact_summarize_prompt');
            } catch (\RuntimeException $e) {
                $this->dropItem($head, $this->t('queue.claude_start_failed', ['error' => $e->getMessage()]));

                return;
            }
            $this->startJobOfItem($head, RunKind::KIND_COMPACT_SUMMARIZE, $summarizePrompt, $sessionId, [], $sessionId);

            return;
        }

        // 6. File download.
        $paths = [];
        $pathsByPart = [];
        $names = [];
        $hasFiles = false;
        foreach ($head['parts'] as $part) {
            $hasFiles = $hasFiles || ($part['files'] ?? []) !== [];
        }
        if ($hasFiles) {
            // Safeguard against orphan files left after a crash in the middle of the previous run.
            $this->inbox->sweepInbox();
        }
        try {
            foreach ($head['parts'] as $i => $part) {
                $pathsByPart[$i] = [];
                foreach (Inbox::partFiles($part) as $file) {
                    $path = $this->inbox->downloadIncomingFile($file);
                    $paths[] = $path;
                    $pathsByPart[$i][] = $path;
                    $names[] = $file['name'] ?? basename($path);
                }
            }
        } catch (\Throwable $e) {
            $this->inbox->deleteInboxFiles($paths);
            $failure = $this->inbox->formatDownloadError($e);
            if (Inbox::isPermanentDownloadError($e)) {
                // File permanently unavailable: drop the item WHOLE (a merged one - with all its files).
                $this->dropItem($head, $failure."\n".($hasFiles
                    ? $this->t('queue.dropped_whole_files', ['files' => implode(', ', $this->inbox->fileNames($head['parts']))])
                    : $this->t('queue.dropped_whole')));
            } else {
                $this->scheduleRetry($head, $failure, true);
            }

            return;
        }

        // 7. Launch claude.
        try {
            $prompt = Inbox::buildQueuePrompt($head['parts'], $pathsByPart);
            if ($head['mode'] === SystemPrompt::MODE_PM) {
                $prompt = PmRunbook::line($this->config->absolutePromptsDir())."\n\n".$prompt;
            }
        } catch (\RuntimeException $e) {
            $this->inbox->deleteInboxFiles($paths);
            $this->dropItem($head, $this->t('queue.claude_start_failed', ['error' => $e->getMessage()]));

            return;
        }
        $idsPath = null;
        if ($head['mode'] === SystemPrompt::MODE_REVIEW) {
            [$extra, $idsPath] = $this->runPrompt->manualReviewFindings($head['parts']);
            if ($extra !== []) {
                $prompt .= "\n\n".implode("\n", $extra);
            }
        }
        $this->startJobOfItem($head, RunKind::KIND_CHAT, $prompt, $sessionId, $paths, idsPath: $idsPath);
    }

    /**
     * @param array{id:int, chat_id:int, thread_id:int, mode:string, locale:?string, placeholder_id:int, parts:list<array<string, mixed>>} $head
     */
    private function startEpicItem(array $head): void
    {
        $slug = (string) ($head['parts'][0]['slug'] ?? '');
        $run = (string) ($head['parts'][0]['run'] ?? '');
        $pr = isset($head['parts'][0]['pr']) ? (string) $head['parts'][0]['pr'] : null;

        // The epic was paused in the meantime or the run is stale: the item is dropped silently.
        $epic = $this->db->epicGet($slug);
        if ($epic === null || $epic['status'] !== self::EPIC_RUNNING || !Run::isRun($run)) {
            Log::line("queue: epic run {$slug} ({$run}) dropped - epic is not running");
            $this->dropItem($head, null);

            return;
        }

        $prompt = $this->runPrompt->forEpicRun($slug, $run, $pr);
        $this->startJobOfItem($head, RunKind::KIND_EPIC, $prompt, null, [], null, $slug, ['run' => $run, 'pr' => $pr]);
    }

    /**
     * Launch of an item's run. A launch failure is temporary: the item stays the head.
     *
     * @param array{id:int, chat_id:int, thread_id:int, mode:string, locale:?string, placeholder_id:int} $head
     * @param list<string>                                                                               $inboxFiles
     * @param array{run:string, pr:?string}|null                                                         $epicRun
     */
    private function startJobOfItem(array $head, string $kind, string $prompt, ?string $sessionId, array $inboxFiles, ?string $compactOld = null, ?string $epicSlug = null, ?array $epicRun = null, ?string $idsPath = null): void
    {
        $chatId = $head['chat_id'];
        $threadId = $head['thread_id'];

        // "Topic deleted" check - right before EVERY claude launch:
        // since step 1 the topic could have been deleted (tree pause, retry, limit pause).
        // An item without a placeholder (after a limit) gets a new one - in ITS OWN topic,
        // without a fallback to General: a Telegram refusal drops the item.
        $text = $this->startText(match ($kind) {
            RunKind::KIND_CHAT => self::ITEM_CHAT,
            RunKind::KIND_EPIC => self::ITEM_EPIC,
            default => self::ITEM_COMPACT,
        }, $head['mode']);
        $thinkingId = $head['placeholder_id'];
        if ($thinkingId > 0) {
            $status = $this->tg->editMessageText($chatId, $thinkingId, $text);
            if ($status === EditStatus::Gone) {
                $this->inbox->deleteInboxFiles($inboxFiles);
                $this->dropItem($head, $this->t('queue.placeholder_gone'));

                return;
            }
            if ($status === EditStatus::Failed) {
                $this->inbox->deleteInboxFiles($inboxFiles);
                $this->scheduleRetry($head, $this->t('queue.telegram_unreachable'));

                return;
            }
        } else {
            try {
                // The service message of an epic run disappears when it ends - no push.
                $thinkingId = $this->tg->sendText($chatId, $text, null, $threadId, null, $kind === RunKind::KIND_EPIC);
            } catch (TelegramApiException) {
                $this->inbox->deleteInboxFiles($inboxFiles);
                $this->dropItem($head, null);
                Log::line("queue: topic of item {$head['id']} unavailable, item dropped");

                return;
            }
            $this->db->queueSetPlaceholder($head['id'], $thinkingId);
        }

        $this->db->queueMarkRunning($head['id']);
        try {
            $error = $this->launcher->launchJob($chatId, $threadId, $kind, $prompt, $sessionId, $thinkingId, $compactOld, $head['mode'], $head['id'], $epicSlug, $epicRun, $idsPath, $head['locale'] ?? null);
        } catch (\Throwable $e) {
            // The process is already started and tracked (activeJob is assigned right after
            // start()) - the job goes on as usual, pollJob() will clean up inbox files.
            if ($this->runState->jobContext !== null) {
                $this->runState->jobContext['inbox_files'] = $inboxFiles;
            }
            Log::line('queue: exception after claude launch: '.$e->getMessage());

            return;
        }
        if ($error !== null) {
            $this->db->queueRelease($head['id']);
            $this->db->queueSetPlaceholder($head['id'], $thinkingId);
            $this->inbox->deleteInboxFiles($inboxFiles);
            $this->scheduleRetry($head, $this->t('queue.claude_start_failed', ['error' => $error]));

            return;
        }

        $this->db->clearRetry();
        $this->retryItemId = null;
        $this->retryCount = 0;
        $this->editedItemId = null;
        if ($this->runState->jobContext !== null) {
            $this->runState->jobContext['inbox_files'] = $inboxFiles;
        }
    }

    /**
     * Final failure: drop the item and notify its topic (if there is anything to say).
     *
     * @param array{id:int, chat_id:int, thread_id:int, placeholder_id:int} $item
     */
    private function dropItem(array $item, ?string $message): void
    {
        $this->db->queueRemove($item['id']);
        $this->editedItemId = null;
        $this->db->clearRetry();
        $this->retryItemId = null;
        $this->retryCount = 0;
        if ($message === null) {
            return;
        }
        try {
            $this->tg->deleteMessage($item['chat_id'], $item['placeholder_id']);
        } catch (\Throwable) {
        }
        $this->delivery->safeDeliver($item['chat_id'], $item['thread_id'], $message);
    }

    /**
     * Temporary failure: the item stays the head, retry after RETRY_DELAY_SEC,
     * one message to the topic per item, not per retry.
     *
     * @param array{id:int, chat_id:int, thread_id:int, placeholder_id:int} $head
     * @param bool                                                          $itemSpecific the failure is tied to the item itself (not to the shared tree) - only then is the retry limit applied
     */
    private function scheduleRetry(array $head, string $message, bool $itemSpecific = false): void
    {
        if ($this->retryItemId !== $head['id']) {
            $this->retryItemId = $head['id'];
            $this->retryCount = 0;
        }
        // Retry limit - only for item-specific failures (its
        // files). Sync and claude launch failures are shared by the whole tree: when
        // GitHub is unavailable, heads must not be dropped one after another, the item
        // stays the head with no limit on retries.
        if ($itemSpecific && ++$this->retryCount > self::MAX_START_RETRIES) {
            // Retry limit: one topic must not hold the shared queue forever.
            $this->dropItem($head, $message."\n".$this->t('queue.dropped_after_retries', ['count' => self::MAX_START_RETRIES]));

            return;
        }

        $retry = $this->db->getRetry();
        $notified = $retry !== null && $retry['item'] === $head['id'] && $retry['notified'];
        $this->db->setRetry($head['id'], time() + self::RETRY_DELAY_SEC, true);

        if (!$notified) {
            $this->delivery->safeDeliver($head['chat_id'], $head['thread_id'], $message."\n".$this->t('queue.retry_notice', ['seconds' => self::RETRY_DELAY_SEC]));
        }
    }

    /**
     * Bot start: pending items survive /restart; a job killed
     * by the restart (the item remained marked as running) is dropped with a request to
     * retry and is not restarted. A message goes to every topic with a queue.
     * With AUTO_COMMIT=1 the dirty-tree decision is issued again (entering the pause), but only if the
     * queue is not empty - otherwise the old buttons are still alive.
     */
    public function recoverQueueOnStart(): void
    {
        foreach ($this->db->queueRunning() as $item) {
            if ($item['kind'] === self::ITEM_EPIC) {
                // An epic run is idempotent (the runbook is reconciled with GitHub, the
                // journal is written to disk immediately - and committed immediately under
                // AUTO_COMMIT=1, otherwise it just stays on disk): it goes back to the
                // queue and restarts.
                $this->db->queueRelease($item['id']);

                continue;
            }
            $this->db->queueRemove($item['id']);
            $this->delivery->safeDeliver($item['chat_id'], $item['thread_id'], $this->locales->localeFor($item['locale'])->t('restart.job_interrupted'));
        }

        $this->db->clearRetry();
        $waiting = $this->db->queueWaiting();
        if ($waiting === []) {
            return;
        }
        $this->db->clearDecision();

        $counts = [];
        foreach ($waiting as $item) {
            if ($item['kind'] === self::ITEM_EPIC) {
                continue;
            }
            $key = $item['chat_id'].':'.$item['thread_id'];
            $counts[$key] = ($counts[$key] ?? ['chat' => $item['chat_id'], 'thread' => $item['thread_id'], 'n' => 0]);
            ++$counts[$key]['n'];
        }
        foreach ($counts as $c) {
            $this->delivery->safeDeliver($c['chat'], $c['thread'], $this->t('restart.queue_kept', ['count' => $c['n']]));
        }
    }

    /**
     * Common synchronization before starting Claude: `git pull --rebase` of the
     * managed repository + best-effort pull of the docs/* components + a profile to the
     * log/verbose chat. Called when the queue head starts: Claude runs in the
     * same REPO_PATH and must work on fresh code. Returns the error text if the
     * managed repository pull fails (the caller picks the retry policy) or null.
     */
    private function syncManagedRepo(int $chatId, int $threadId): ?string
    {
        $managedStart = microtime(true);
        $pullError = '';
        // AUTO_COMMIT=0: the managed repository is not touched; the sub-repositories below still are.
        if ($this->config->autoCommit && !$this->git->pullRebase($pullError)) {
            return $pullError;
        }
        $managedSec = microtime(true) - $managedStart;

        // Components are developed on GitHub and the local docs/* clones often lag
        // behind, so we pull them to let Claude work on fresh code. A failure in a
        // single clone does not cancel the job (the managed repository above is already
        // pulled), it is only added to the warning. $timings is a per-repository
        // profile: this whole synchronization happens BEFORE the "thinking"
        // placeholder, so it is the main suspect for the delay between sending a
        // request and the placeholder appearing.
        $timings = [];
        $subReposStart = microtime(true);
        $subRepoNotes = $this->subRepoSync->pullSubRepos($timings);
        $subReposSec = microtime(true) - $subReposStart;

        if ($subRepoNotes !== []) {
            $this->tg->sendText(
                $chatId,
                $this->t('sync.notes_header')."\n".implode("\n", $subRepoNotes),
                null,
                $threadId,
            );
        }

        // Sync profile: always to the log (diagnostics from the server without
        // reproduction), to the chat only when verbose.
        $profile = $this->subRepoSync->formatSyncProfile($managedSec, $subReposSec, $timings);
        Log::line($profile['log']);
        if ($this->db->isVerbose($chatId, $threadId)) {
            $this->tg->sendText($chatId, $profile['message'], null, $threadId);
        }

        return null;
    }
}
