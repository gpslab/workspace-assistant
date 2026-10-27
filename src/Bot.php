<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\Card;
use GpsLab\WorkspaceAssistant\Epic\ChatSurface;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\GitHub;
use GpsLab\WorkspaceAssistant\Epic\MasterRecord;
use GpsLab\WorkspaceAssistant\Epic\Orchestrator;
use GpsLab\WorkspaceAssistant\Epic\PollSchedule;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\Signal;
use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;

/**
 * Main loop: Telegram polling, Claude launch, git operations.
 *
 * One task at a time. While Claude is working, the loop keeps accepting updates
 * (short poll) so that /cancel can interrupt the process. A request that arrives
 * while the bot is busy is not rejected but joins the single FIFO queue (SQLite, see
 * Database::queue*); the queue head is started in {@see startQueueHead()}.
 *
 * Multi-session (forum topics). A session is keyed by the pair (chat_id, thread_id):
 * thread_id = 0 — a 1:1 chat and the General topic of a group (the chat's default session),
 * thread_id > 0 — a separate forum topic = an isolated Claude session. Isolation is
 * at the Claude context level only; the git tree and the task queue are shared by the whole
 * bot — at most one task is active at a time (see $activeJob), which
 * serializes everything by construction.
 */
final class Bot
{
    private const string KIND_CHAT = 'chat';
    private const string KIND_COMPACT_SUMMARIZE = 'compact_summarize';
    private const string KIND_COMPACT_RESEED = 'compact_reseed';
    private const string KIND_EPIC = 'epic';

    /** Queue item kinds (queue.kind column): a regular request and the /new, /compact barriers. */
    private const string ITEM_CHAT = 'chat';
    private const string ITEM_NEW = 'new';
    private const string ITEM_COMPACT = 'compact';
    private const string ITEM_EPIC = 'epic';

    private const string EPIC_RUNNING = 'running';
    private const string EPIC_PAUSED = 'paused';

    /** How many seconds to wait before retrying the head start after a transient failure. */
    private const int RETRY_DELAY_SEC = 60;

    private const int MAX_START_RETRIES = 10;
    private const int LIMIT_PAUSE_MAX_SEC = 7 * 86400;

    /** Pause on a Claude limit when the reset time could not be parsed from the error text. */
    private const int LIMIT_PAUSE_FALLBACK_SEC = 3600;

    /** Album debounce: how many seconds without new frames of the group to wait before starting the task. */
    private const float ALBUM_DEBOUNCE_SEC = 1.5;

    /** Bot API getFile download limit. */
    private const int MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    /** Commands registered with Telegram; descriptions are the `command.<name>` texts. */
    private const array BOT_COMMANDS = ['new', 'compact', 'cancel', 'queue', 'stats', 'verbose', 'mode', 'lang', 'epic', 'restart', 'help'];

    private int $offset = 0;
    private ?Orchestrator $orchestrator = null;
    private readonly Card $epicCard;
    private int $lastEpicPollAt = 0;
    private ?ClaudeRunner $activeJob = null;
    private bool $running = true;

    /**
     * The run mode (`mode`) is fixed when the item is queued and
     * read ONLY from here (launchJob/ReviewStatusHook/thinkingText/
     * overflowCaption) — not from sessions: /mode may have changed while the run was in progress.
     * The language (`locale`) is fixed the same way and read from the item, not from the /lang setting.
     *
     * @var array{kind:string, chat_id:int, thread_id:int, thinking_id:int, prompt:string, last_progress_at:int, shown_count:int, compact_old_session_id:?string, session_saved:bool, inbox_files:list<string>, mode:string, locale:string, queue_id:int, epic_slug:?string, epic_run:array{run:string, pr:?string}|null, pm_gate_run_id:?string, prompt_hash:?string}|null
     */
    private ?array $jobContext = null;

    /** The item whose placeholder was already edited to "Thinking…" (do not edit on every pause/retry tick). */
    private ?int $editedItemId = null;
    private ?int $retryItemId = null;
    private int $retryCount = 0;

    /** Result of the last check in startQueueHead(): tree is dirty → queue is paused (only for the tick timeout calculation). */
    private bool $treePaused = false;

    /** Album frame buffer between maturations (see ALBUM_DEBOUNCE_SEC). */
    private readonly AlbumBuffer $albumBuffer;

    /**
     * Snapshot of HEAD of all docs/* repositories before Claude is launched.
     * Afterwards we compare — new commits get links in the reply.
     *
     * @var array<string, string|null>|null repoPath => headHash|null
     */
    private ?array $repoSnapshot = null;

    /**
     * With AUTO_COMMIT=0: the working tree as it was when the current run started, to tell what the run
     * changed in a tree that is normally dirty. It outlives finishJobState() and is dropped when the
     * run's outcome has been handled.
     *
     * @var array<string, string>|null
     */
    private ?array $runTreeSnapshot = null;

    private readonly Locale $locale;
    private readonly ClaudeErrorText $claudeErrorText;
    private readonly SubRepoSync $subRepoSync;
    private readonly Delivery $delivery;
    private readonly Inbox $inbox;
    private readonly ChatSurface $epicChat;
    private readonly ModeAndLang $modeAndLang;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly GitManager $git,
        private readonly SessionFiles $sessionFiles,
        private readonly ?GitHub $github = null,
        ?Locale $locale = null,
    ) {
        $this->locale = $locale ?? Locale::fromConfig($config);
        $this->claudeErrorText = new ClaudeErrorText($this->locale);
        $this->subRepoSync = new SubRepoSync($config, $this->locale);
        $this->delivery = new Delivery($tg, $config, $this->locale);
        $this->inbox = new Inbox($tg, $config, $this->locale);
        $this->albumBuffer = new AlbumBuffer();
        $this->epicCard = new Card($db, $tg, $config->repoPath, $config->epicsDir, $config->epicTickMinutes * 60, $this->locale);
        $this->epicChat = new ChatSurface($db, $tg, $config, $git, $this->epicCard, $this->locale);
        $this->modeAndLang = new ModeAndLang(
            $db,
            $tg,
            $this->locale,
            $this->topicHasActiveJob(...),
            $this->localeFor(...),
            $this->userLocale(...),
        );
        if ($github !== null) {
            $this->orchestrator = new Orchestrator(
                $db,
                $github,
                $this->enqueueEpicRun(...),
                $this->blockEpic(...),
                $config->epicTickMinutes * 60,
                $config->epicStallTicks,
                $config->epicQuietMinutes * 60,
                $config->epicAnswerTimeoutMinutes * 60,
                notify: $this->notifyEpic(...),
                card: $this->epicCard,
                stateDir: $config->stateDir,
                masterRedHours: $config->epicMasterRedHours,
                owners: $config->githubOwners,
                locale: $this->locale,
            );
        }
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

    /** Catalog with the locale fixed for a queue item or a user selected (`null` means the bot locale). */
    private function localeFor(?string $localeCode): Locale
    {
        return $localeCode === null ? $this->locale : $this->locale->with($localeCode);
    }

    /** Chat language of a user: chosen with /lang, otherwise `BOT_LOCALE` (never guessed from `language_code`). */
    private function userLocale(int $userId): string
    {
        $chosen = $userId !== 0 ? $this->db->getUserLocale($userId) : null;

        return $chosen !== null && in_array($chosen, $this->locale->codes(), true) ? $chosen : $this->locale->code();
    }

    public function run(): void
    {
        $this->db->clearStalePids();
        if (!$this->config->autoCommit) {
            // A button issued before the switch to AUTO_COMMIT=0 must never reset or commit the tree.
            $this->db->clearDecision();
            $this->db->clearDirtyOwner();
        }
        foreach ($this->locale->warnings() as $warning) {
            Log::line('locale: '.$warning);
        }
        ClaudeRunner::purgeRunFiles($this->config->stateDir);
        $this->registerCommands();
        $this->recoverQueueOnStart();
        Log::line('started, listening to Telegram...');

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            Log::line('SIGTERM received, shutting down...');
            $this->running = false;
        });

        while ($this->running) {
            try {
                $this->tick();
            } catch (TelegramTransportException $e) {
                // The network dropped (DNS, connection reset, request timeout). The error is not ours
                // and heals itself — kept separate from the rest so that an hours-long
                // network outage does not look like a bot failure in the log.
                Log::line('network unavailable: '.$e->getMessage());
                sleep(1);
            } catch (\Throwable $e) {
                Log::line('loop error: '.$e->getMessage());
                // Do not bring the bot down over a single failure.
                sleep(1);
            }
        }

        // Telegram considers an update handled only when its offset is
        // passed in the NEXT getUpdates call. $this->offset is already advanced
        // (see tick()), but if the process exits right now, that call
        // never happens — on the next start the offset is 0 again, and Telegram
        // resends the same update (including /restart, which caused
        // the stop) → an endless restart loop. We send a final
        // getUpdates with a zero timeout to explicitly acknowledge the offset.
        try {
            $this->tg->getUpdates($this->offset, 0);
        } catch (\Throwable) {
            // best-effort on shutdown
        }

        // If a task was running at stop time (for example, Claude itself ran
        // `ctl.sh restart`) — there is no one left to deliver the answer to. We do not leave a dangling
        // "Thinking…" but warn: the session is saved, the request can be repeated.
        if ($this->activeJob !== null && $this->jobContext !== null) {
            // A job killed by the restart is removed from the queue and not
            // started again: a rerun would duplicate the prompt in the transcript.
            if ($this->jobContext['queue_id'] > 0) {
                $this->db->queueRemove($this->jobContext['queue_id']);
            }
            try {
                $this->tg->deleteMessage($this->jobContext['chat_id'], $this->jobContext['thinking_id']);
                $this->delivery->deliver(
                    $this->jobContext['chat_id'],
                    $this->jobContext['thread_id'],
                    $this->localeFor($this->jobContext['locale'])->t('restart.job_interrupted_now'),
                );
            } catch (\Throwable) {
                // best-effort on shutdown
            }
        }

        $this->activeJob?->kill();
        Log::line('stopped.');
    }

    /**
     * Registers the command descriptions for every available locale (with its `language_code`)
     * and once without a code, in the `BOT_LOCALE` language.
     */
    private function registerCommands(): void
    {
        foreach ($this->locale->codes() as $code) {
            try {
                $this->tg->setMyCommands($this->commandList($this->locale->with($code)), $code);
            } catch (\Throwable $e) {
                // A locale code Telegram does not accept must not stop the bot.
                Log::line("setMyCommands for locale {$code} failed: ".$e->getMessage());
            }
        }
        $this->tg->setMyCommands($this->commandList($this->locale));
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    private function commandList(Locale $locale): array
    {
        $commands = [];
        foreach (self::BOT_COMMANDS as $command) {
            $commands[] = ['command' => $command, 'description' => $locale->t("command.{$command}")];
        }

        return $commands;
    }

    private function tick(): void
    {
        $timeout = $this->pollTimeoutNow();

        foreach ($this->tg->getUpdates($this->offset, $timeout) as $update) {
            $this->offset = (int) $update['update_id'] + 1;
            $this->dispatch($update);
        }

        $this->flushReadyAlbums();
        $this->pollEpics();

        if ($this->activeJob !== null) {
            $this->pollJob();
        } elseif ($this->running) {
            // After /restart from this same batch of updates we do not start the head: it
            // would be killed right away by the bot shutting down. Transition points: job end/cancel/button decision/pause
            // expiry — all of them lead here on the nearest tick. While the tree
            // is paused the check repeats once per long-poll cycle (≤30 s), so
            // a manual cleanup lifts the pause without the bot's involvement.
            $this->startQueueHead();
        }

        // Branches with $timeout = 0 (active task, immature album, queue head ready to
        // start) do not block in getUpdates — without a sleep here the
        // loop turns into a busy-loop flooding the Telegram API with requests.
        if ($timeout === 0) {
            usleep(200000);
        }
    }

    /** The epic orchestrator polls GitHub — EPIC_POLL_SECONDS after the previous pass ends, errors do not bring the loop down. */
    private function pollEpics(): void
    {
        if ($this->orchestrator === null || !$this->running || time() < $this->epicPollDueAt()) {
            return;
        }
        $started = microtime(true);
        try {
            $polled = $this->orchestrator->poll();
        } finally {
            // Also on a failure: otherwise the pass would repeat on every tick and starve the job and the queue.
            $this->lastEpicPollAt = time();
        }
        $message = PollSchedule::slowPassMessage(microtime(true) - $started, $polled);
        if ($message !== null) {
            Log::line($message);
        }
    }

    /**
     * getUpdates timeout: 0 if there is something to do without waiting (active job,
     * immature album, queue head not on pause); otherwise a long-poll,
     * shortened to the nearest timer (limit pause / retry).
     */
    private function pollTimeoutNow(): int
    {
        $busy = $this->activeJob !== null || !$this->albumBuffer->isEmpty();
        $queueEmpty = !$busy && $this->db->queueHead() === null;
        $epicDue = !$busy && $this->orchestrator !== null && $this->running && $this->db->epicsRunningExist()
            ? $this->epicPollDueAt()
            : null;

        return PollSchedule::longPollTimeout(
            $busy,
            $queueEmpty,
            $busy || $queueEmpty ? 0 : $this->queueBlockedUntil(),
            $this->treePaused,
            $this->config->pollTimeout,
            $epicDue,
            time(),
        );
    }

    private function epicPollDueAt(): int
    {
        return $this->lastEpicPollAt + $this->config->epicPollSeconds;
    }

    /**
     * Hands matured albums (see AlbumBuffer) to the queue. Called on
     * every tick — a single-threaded bot has no separate timer.
     */
    private function flushReadyAlbums(): void
    {
        foreach ($this->albumBuffer->collectReady(time(), self::ALBUM_DEBOUNCE_SEC) as $group) {
            $this->enqueueFiles($group['chat_id'], $group['thread_id'], $group['message_ids'][0] ?? 0, $group['caption'], $group['files'], $group['message_ids'], $group['user_id']);
        }
    }

    /**
     * The forum topic of an incoming message. A real topic is flagged is_topic_message
     * AND carries message_thread_id. The General topic and a 1:1 chat arrive without that flag
     * → session 0 (the chat's default session). Replies outside topics may also carry
     * message_thread_id, so we check precisely is_topic_message.
     *
     * @param array<string, mixed> $message
     */
    private function threadIdOf(array $message): int
    {
        if (($message['is_topic_message'] ?? false) === true && isset($message['message_thread_id'])) {
            return (int) $message['message_thread_id'];
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function dispatch(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        if (!isset($update['message']) || !is_array($update['message'])) {
            return;
        }

        $message = $update['message'];

        if ($this->dispatchFileMessage($message)) {
            return;
        }

        if (!isset($message['text'])) {
            return;
        }

        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $threadId = $this->threadIdOf($message);

        if (!$this->config->isAllowed($userId)) {
            $this->tg->sendText($chatId, $this->t('access.denied'), null, $threadId);

            return;
        }

        $text = trim((string) $message['text']);
        if (str_starts_with($text, '/')) {
            $this->handleCommand($chatId, $threadId, $text, (int) ($message['message_id'] ?? 0), $userId);

            return;
        }

        $messageId = (int) ($message['message_id'] ?? 0);
        $this->enqueueMessage($chatId, $threadId, $messageId, [['message_id' => $messageId, 'text' => $text, 'files' => []]], $userId);
    }

    /**
     * Routing of an incoming file (photo/document/audio/video/voice/
     * video_note/animation, single or an album item) and of a sticker.
     * Returns true if the message belongs to this branch (handled or
     * rejected with a message to the user) — then dispatch() goes no further
     * to text/command parsing. Returns false for messages without a file and without a
     * sticker (text and the like).
     *
     * @param array<string, mixed> $message
     */
    private function dispatchFileMessage(array $message): bool
    {
        $file = IncomingFile::extractFrom($message);
        if ($file === null && !IncomingFile::isSticker($message)) {
            return false;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $threadId = $this->threadIdOf($message);
        $userId = (int) ($message['from']['id'] ?? 0);

        if (!$this->config->isAllowed($userId)) {
            $this->tg->sendText($chatId, $this->t('access.denied'), null, $threadId);

            return true;
        }

        if ($file === null) {
            $this->tg->sendText($chatId, $this->t('sticker.unsupported'), null, $threadId);

            return true;
        }

        $caption = IncomingFile::captionOf($message);
        $mediaGroupId = IncomingFile::mediaGroupId($message);

        if ($mediaGroupId !== null) {
            // Album: we accumulate items, flushReadyAlbums() queues them after the debounce.
            $this->albumBuffer->add($mediaGroupId, $chatId, $threadId, $caption, $file, time(), (int) ($message['message_id'] ?? 0), $userId);

            return true;
        }

        $messageId = (int) ($message['message_id'] ?? 0);
        $this->enqueueFiles($chatId, $threadId, $messageId, $caption, [$file], [$messageId], $userId);

        return true;
    }

    private function handleCommand(int $chatId, int $threadId, string $text, int $messageId = 0, int $userId = 0): void
    {
        // /mode is the first command with an argument: we parse the tail separately from
        // the command itself (lowercasing and trimming are done inside
        // cmdMode — here we only split "/mode" from "review").
        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower($parts[0]);
        $argument = $parts[1] ?? '';
        // In groups/forums the Telegram client appends the suffix
        // @botusername to a command (/new@YourBot); in a 1:1 chat there is none. Without cutting it off,
        // match fails and any command in a topic falls into default. Only our
        // bot receives these updates, so we do not check the name — we cut any @-tail.
        if (($at = strpos($command, '@')) !== false) {
            $command = substr($command, 0, $at);
        }

        match ($command) {
            '/start', '/help' => $this->tg->sendText($chatId, $this->helpText(), null, $threadId),
            '/new' => $this->cmdNew($chatId, $threadId, $messageId, $userId),
            '/compact' => $this->cmdCompact($chatId, $threadId, $messageId, $userId),
            '/cancel' => $this->cmdCancel($chatId, $threadId),
            '/queue' => $this->cmdQueue($chatId, $threadId),
            '/stats' => $this->cmdStats($chatId, $threadId),
            '/verbose' => $this->cmdVerbose($chatId, $threadId),
            '/mode' => $this->modeAndLang->cmdMode($chatId, $threadId, $argument),
            '/lang' => $this->modeAndLang->cmdLang($chatId, $threadId, $userId, $argument),
            '/epic' => $this->epicChat->cmdEpic($chatId, $threadId, $argument),
            '/restart' => $this->cmdRestart($chatId, $threadId),
            default => $this->tg->sendText($chatId, $this->t('command.unknown', ['command' => $command]), null, $threadId),
        };
    }

    /**
     * /new: if the topic has an active or pending item — it joins the queue as a
     * barrier (a request sent before /new runs in the old session); if the topic
     * is idle — an instant reset, as before.
     */
    private function cmdNew(int $chatId, int $threadId, int $messageId = 0, int $userId = 0): void
    {
        if ($this->topicBusy($chatId, $threadId)) {
            $this->enqueueItem($chatId, $threadId, $messageId, self::ITEM_NEW, $this->db->getMode($chatId, $threadId), [], $this->userLocale($userId));

            return;
        }

        $this->resetTopicSession($chatId, $threadId);
    }

    private function resetTopicSession(int $chatId, int $threadId): void
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
     * Compact the session instead of a full reset: ask Claude to write a
     * summary of the current session, then open a new session with that summary in
     * the first message. A two-phase task (see finishCompactSummarize /
     * finishCompactReseed) — the old session's counters are zeroed only after
     * the new session is actually created, otherwise a failure of the second phase would
     * lose the context with no replacement. /compact is a claude run: it takes the
     * queue like a regular item; "is there anything to compact" is checked when
     * the item starts (session_id is read then, not at queueing).
     */
    private function cmdCompact(int $chatId, int $threadId, int $messageId = 0, int $userId = 0): void
    {
        $this->enqueueItem($chatId, $threadId, $messageId, self::ITEM_COMPACT, SystemPrompt::MODE_ARCH, [], $this->userLocale($userId));
    }

    /**
     * /cancel from topic X: kills X's active job and removes ALL pending
     * items of X (otherwise the topic's next request would start in the context the
     * person just rejected). Other topics and chats are
     * never affected. There is no auto-commit/auto-rollback: the decision on a dirty
     * tree is made by the person with buttons.
     */
    private function cmdCancel(int $chatId, int $threadId): void
    {
        $killed = false;
        if ($this->activeJob !== null && $this->jobContext !== null
            && $this->jobContext['chat_id'] === $chatId && $this->jobContext['thread_id'] === $threadId) {
            $inboxFiles = $this->jobContext['inbox_files'];
            $queueId = $this->jobContext['queue_id'];

            $this->activeJob->kill();
            $this->finishJobState($chatId, $threadId);
            $this->inbox->deleteInboxFiles($inboxFiles);
            if ($queueId > 0) {
                $this->db->queueRemove($queueId);
            }
            $killed = true;
        }

        $removed = $this->db->queueCancelThread($chatId, $threadId);

        // The topic's epic goes on pause: the epic's queue items were removed above (they are part of
        // the topic's queue), and new ones will not appear without /epic resume.
        $epic = $this->db->epicByThread($chatId, $threadId);
        $pausedEpic = null;
        if ($epic !== null && $epic['status'] === self::EPIC_RUNNING) {
            $this->db->epicSetStatus($epic['slug'], self::EPIC_PAUSED);
            $this->epicCard->update($epic['slug']);
            $pausedEpic = $epic['slug'];
        }

        // Manual exit from the limit pause: /cancel clears it if anything of the topic
        // was removed (otherwise the pause is shared and someone else's /cancel does not touch it).
        $limitReset = false;
        if (($killed || $removed !== []) && $this->db->getLimitPauseUntil() > time()) {
            $this->db->setLimitPauseUntil(0);
            $limitReset = true;
        }

        if (!$killed && $removed === []) {
            $this->tg->sendText(
                $chatId,
                $pausedEpic !== null
                    ? $this->t('cancel.epic_paused_queue_empty', ['slug' => $pausedEpic])
                    : ($this->activeJob !== null
                        ? $this->t('cancel.other_session_busy')
                        : $this->t('cancel.nothing')),
                null,
                $threadId,
            );

            return;
        }

        // Files the cancelled run managed to put into the outbox must not
        // go out with the next run's reply (possibly to another topic). Another topic's
        // running job's outbox is not touched — those are its files.
        if ($killed || $this->activeJob === null) {
            $this->delivery->clearOutbox();
        }

        $lines = [];
        foreach ($removed as $item) {
            $this->tg->editMessageText($item['chat_id'], $item['placeholder_id'], $this->t('cancel.item_removed'));
            $lines[] = '• '.$this->describeItem($item);
        }

        $text = $killed ? $this->t('cancel.process_stopped') : '';
        if ($limitReset) {
            $text .= ($text !== '' ? "\n" : '').$this->t('cancel.limit_pause_reset');
        }
        if ($lines !== []) {
            $text .= ($text !== '' ? "\n" : '').$this->t('cancel.removed_from_queue', ['count' => count($lines)])."\n".implode("\n", $lines);
        }
        if ($pausedEpic !== null) {
            $text .= ($text !== '' ? "\n" : '').$this->t('cancel.epic_paused', ['slug' => $pausedEpic]);
        }
        $this->delivery->deliver($chatId, $threadId, $text);

        if ($killed) {
            $this->db->clearDecision();
            $this->offerDecisionIfDirty($chatId, $threadId);
        }
    }

    /**
     * /queue: queue items, their topics and the pause reason (if the queue is paused).
     */
    private function cmdQueue(int $chatId, int $threadId): void
    {
        $lines = [];
        if ($this->activeJob !== null && $this->jobContext !== null) {
            $lines[] = $this->t('queue.running', ['topic' => $this->topicLabel($this->jobContext['chat_id'], $this->jobContext['thread_id'])]);
        }

        $waiting = $this->db->queueWaiting();
        foreach ($waiting as $i => $item) {
            $lines[] = sprintf('%d. %s — %s [%s]', $i + 1, $this->topicLabel($item['chat_id'], $item['thread_id']), $this->describeItem($item), $item['mode']);
        }

        if ($lines === []) {
            $this->tg->sendText($chatId, $this->t('queue.empty'), null, $threadId);

            return;
        }

        $text = $this->t('queue.header', ['count' => count($waiting)])."\n".implode("\n", $lines);
        $reason = $this->pauseReason();
        if ($reason !== null) {
            $text .= "\n\n".$this->t('queue.paused', ['reason' => $reason]);
        }

        $this->tg->sendText($chatId, $text, null, $threadId);
    }

    /**
     * The queue pause reason or null. A pause on a dirty tree is DERIVED from
     * state (queue is non-empty, no job, tree is dirty), not stored as
     * an in-memory flag.
     */
    private function pauseReason(): ?string
    {
        $head = $this->db->queueHead();
        if ($head === null) {
            return null;
        }

        $limitUntil = $this->db->getLimitPauseUntil();
        if ($limitUntil > time()) {
            return $this->t('pause.limit', ['until' => date('H:i d.m', $limitUntil)]);
        }

        if ($this->isPausedByDirtyTree()) {
            $decision = $this->db->getDecision();

            return $decision !== null
                ? $this->t('pause.dirty_tree_in_topic', ['topic' => $this->topicLabel($decision['chat_id'], $decision['thread_id'])])
                : $this->t('pause.dirty_tree');
        }

        $retry = $this->db->getRetry();
        if ($retry !== null && $retry['item'] === $head['id'] && $retry['at'] > time()) {
            return $this->t('pause.start_retry', ['seconds' => $retry['at'] - time()]);
        }

        return null;
    }

    /** Queue is non-empty, no job, tree is dirty. */
    private function isPausedByDirtyTree(): bool
    {
        if ($this->activeJob !== null || ($head = $this->db->queueHead()) === null) {
            return false;
        }

        return $this->config->autoCommit && $this->git->hasChanges();
    }

    private function topicLabel(int $chatId, int $threadId): string
    {
        return $threadId === 0
            ? $this->t('topic.general', ['chat' => $chatId])
            : $this->t('topic.thread', ['thread' => $threadId, 'chat' => $chatId]);
    }

    /**
     * @param array{kind:string, parts:list<array<string, mixed>>} $item
     */
    private function describeItem(array $item): string
    {
        if ($item['kind'] === self::ITEM_NEW) {
            return '/new';
        }
        if ($item['kind'] === self::ITEM_COMPACT) {
            return '/compact';
        }
        if ($item['kind'] === self::ITEM_EPIC) {
            return $this->t('queue.epic_run', ['slug' => (string) ($item['parts'][0]['slug'] ?? ''), 'run' => (string) ($item['parts'][0]['run'] ?? '')]);
        }

        foreach ($item['parts'] as $part) {
            $text = $part['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return '«'.mb_substr(trim(strtok($text, "\n") ?: $text), 0, 40).'»'.(count($item['parts']) > 1 ? ' (+'.(count($item['parts']) - 1).')' : '');
            }
        }

        return $this->t('queue.files');
    }

    /** Topic's queue position: 1 — running now or first in the queue; null — the topic has no items. */
    private function queuePosition(int $chatId, int $threadId): ?int
    {
        if ($this->jobContext !== null && $this->jobContext['chat_id'] === $chatId && $this->jobContext['thread_id'] === $threadId) {
            return 1;
        }

        $offset = $this->activeJob !== null ? 1 : 0;
        foreach ($this->db->queueWaiting() as $i => $item) {
            if ($item['chat_id'] === $chatId && $item['thread_id'] === $threadId) {
                return $i + 1 + $offset;
            }
        }

        return null;
    }

    private function cmdStats(int $chatId, int $threadId): void
    {
        // The mode line goes first regardless of the outcome below — a forgotten mode
        // silently trims replies, and /stats is often checked right after /new, when there is
        // no active session yet. One message in both cases.
        $modeLine = $this->t('stats.mode', ['mode' => $this->db->getMode($chatId, $threadId)]);
        $position = $this->queuePosition($chatId, $threadId);
        $modeLine .= "\n".$this->t('stats.queue', ['state' => $position === null
            ? $this->t('stats.queue_none')
            : ($position === 1 && $this->jobContext !== null && $this->jobContext['thread_id'] === $threadId && $this->jobContext['chat_id'] === $chatId
                ? $this->t('stats.queue_running')
                : $this->t('queue.position', ['position' => $position]))]);

        $row = $this->db->getSession($chatId, $threadId);
        if ($row === null || ($row['session_id'] ?? null) === null) {
            $this->tg->sendText($chatId, $modeLine."\n".$this->t('stats.no_session'), null, $threadId);

            return;
        }

        $sessionId = (string) $row['session_id'];
        $lines = [
            $modeLine,
            $this->t('stats.session', ['id' => substr($sessionId, 0, 8)]),
            $this->t('stats.messages', ['count' => (int) $row['message_count']]),
            $this->t('stats.tokens_in', ['count' => number_format((int) $row['input_tokens'], 0, '.', ' ')]),
            $this->t('stats.tokens_out', ['count' => number_format((int) $row['output_tokens'], 0, '.', ' ')]),
            $this->t('stats.verbose', ['state' => ((int) $row['verbose']) === 1 ? $this->t('common.on') : $this->t('common.off')]),
        ];

        $this->tg->sendText($chatId, implode("\n", $lines), null, $threadId);
    }

    private function cmdVerbose(int $chatId, int $threadId): void
    {
        $on = $this->db->toggleVerbose($chatId, $threadId);
        $this->tg->sendText($chatId, $on ? $this->t('verbose.on') : $this->t('verbose.off'), null, $threadId);
    }

    /**
     * Pulls fresh bot code (git pull in its own repository) and
     * ends the polling loop. The systemd unit (Restart=always) brings the process
     * back up - the same stop path as on SIGTERM, including the final
     * getUpdates flush in run() (otherwise Telegram would redeliver this same /restart
     * after the restart - see gotchas.md).
     */
    private function cmdRestart(int $chatId, int $threadId): void
    {
        $selfGit = GitManager::forPath(dirname(__DIR__), $this->config->gitTimeout);
        $pullNote = '';

        if ($selfGit === null) {
            $pullNote = "\n".$this->t('restart.no_repo');
        } elseif ($selfGit->hasChanges()) {
            $pullNote = "\n".$this->t('restart.dirty');
        } else {
            $error = '';
            $pullNote = $selfGit->pullRebase($error)
                ? "\n".$this->t('restart.updated', ['hash' => $selfGit->headHash()])
                : "\n".$this->t('restart.pull_failed', ['error' => $error]);
        }

        $this->tg->sendText($chatId, $this->t('restart.restarting').$pullNote, null, $threadId);
        Log::line('restart requested via /restart (chat '.$chatId.', thread '.$threadId.')');
        $this->running = false;
    }

    // ---- Request queue ----------------------------------------------------

    /**
     * Enqueues a message (text or files). Merging: consecutive pending
     * messages of the SAME topic with the same pinned mode and language are
     * appended to the topic's last pending item (until the group is closed by
     * /mode, /new or /compact). A message to a topic whose job is already running
     * is not merged with it - it becomes a new item at the end of the shared queue.
     *
     * @param list<array<string, mixed>> $parts
     */
    private function enqueueMessage(int $chatId, int $threadId, int $messageId, array $parts, int $userId = 0): void
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
        $localeCode = $this->userLocale($userId);
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

        $position = count($this->db->queueWaiting()) + 1 + ($this->activeJob !== null ? 1 : 0);
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
            default => $this->thinkingText($mode),
        };
    }

    /**
     * Enqueues files (a single file is an album of one item; a matured
     * album - all frames as one message part). The size limit is checked against
     * all items before enqueueing: one oversized item fails the whole album
     * (the caption applies to all files, an incomplete album must not go to
     * Claude). Files are downloaded when the run STARTS, not here - otherwise the inbox
     * accumulates the queue tail. Downloaded files are deleted after the run in
     * pollJob()/cmdCancel().
     *
     * @param list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}> $files
     * @param list<int>                                                                                    $messageIds
     */
    private function enqueueFiles(int $chatId, int $threadId, int $messageId, ?string $caption, array $files, array $messageIds = [], int $userId = 0): void
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

    /**
     * Text of the "Thinking..." placeholder - with a mode label when it differs from
     * the default (a forgotten mode would otherwise change behaviour silently). The mode is taken
     * from the item/jobContext, not from sessions. The placeholder is not part of the
     * final answer, so the output size cap is irrelevant here.
     */
    private function thinkingText(string $mode): string
    {
        return $mode === SystemPrompt::DEFAULT_MODE ? $this->t('thinking') : $this->t('thinking_mode', ['mode' => $mode]);
    }

    /** Whether the topic has an active job. */
    private function topicHasActiveJob(int $chatId, int $threadId): bool
    {
        return $this->activeJob !== null && $this->jobContext !== null
            && $this->jobContext['chat_id'] === $chatId && $this->jobContext['thread_id'] === $threadId;
    }

    /** The topic has an active or pending item. */
    private function topicBusy(int $chatId, int $threadId): bool
    {
        return $this->topicHasActiveJob($chatId, $threadId) || $this->db->queueLastWaiting($chatId, $threadId) !== null;
    }

    /**
     * Unix time until which the head does not start because of a timer (pause on Claude
     * limit or retry after a temporary failure); 0 - no timers.
     */
    private function queueBlockedUntil(): int
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
    private function startQueueHead(): void
    {
        if ($this->activeJob !== null) {
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
            if ($this->activeJob !== null) {
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
        if (!$this->git->hasChanges()) {
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
            if ($this->git->hasChanges()) {
                $this->treePaused = true;
                $this->ensureDecision($chatId, $threadId);

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
            $this->startJobOfItem($head, self::KIND_COMPACT_SUMMARIZE, $summarizePrompt, $sessionId, [], $sessionId);

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
                $prompt = PmRunbook::line($this->absolutePromptsDir())."\n\n".$prompt;
            }
        } catch (\RuntimeException $e) {
            $this->inbox->deleteInboxFiles($paths);
            $this->dropItem($head, $this->t('queue.claude_start_failed', ['error' => $e->getMessage()]));

            return;
        }
        $idsPath = null;
        if ($head['mode'] === SystemPrompt::MODE_REVIEW) {
            [$extra, $idsPath] = $this->manualReviewFindings($head['parts']);
            if ($extra !== []) {
                $prompt .= "\n\n".implode("\n", $extra);
            }
        }
        $this->startJobOfItem($head, self::KIND_CHAT, $prompt, $sessionId, $paths, idsPath: $idsPath);
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

        $cold = $run === Run::REVIEW && $pr !== null
            ? ColdFindings::load(ColdFindings::path($this->config->stateDir, $slug, $pr))
            : null;
        $prompt = Run::prompt(
            $slug,
            $run,
            Run::planPath($this->config->repoPath, $this->config->epicsDir, $slug),
            Run::journalPath($this->config->repoPath, $this->config->epicsDir, $slug),
            Run::signalPath($this->config->stateDir, $slug),
            Run::failuresPath($this->config->stateDir, $slug),
            $pr,
            $cold['findings'] ?? null,
            $run === Run::TICK ? $this->masterEvents($slug) : [],
            $this->config->outboxDir,
            Run::addition($this->absolutePromptsDir()),
            PmRunbook::line($this->absolutePromptsDir()),
            $this->config->autoCommit,
        );
        $this->startJobOfItem($head, self::KIND_EPIC, $prompt, null, [], null, $slug, ['run' => $run, 'pr' => $pr]);
    }

    private function absolutePromptsDir(): ?string
    {
        return $this->config->promptsDir !== '' ? $this->config->repoPath.'/'.$this->config->promptsDir : null;
    }

    /**
     * Master CI events for the tick prompt: a confirmed breakage - while it persists,
     * flakiness - once (the tick got it in the prompt).
     *
     * @return list<array{repo: string, sha: string, kind: string, runs: list<array{id: int, url: string, workflow: string}>}>
     */
    private function masterEvents(string $slug): array
    {
        $events = [];
        foreach ($this->db->epicMasters($slug) as $rec) {
            if ($rec->kind === MasterRecord::BROKEN || ($rec->kind === MasterRecord::FLAKY && !$rec->prompted)) {
                $events[] = ['repo' => $rec->repo, 'sha' => $rec->sha, 'kind' => $rec->kind, 'runs' => $rec->runs];
            }
            if ($rec->kind === MasterRecord::FLAKY && !$rec->prompted) {
                $rec->prompted = true;
                $this->db->epicMasterSet($slug, $rec);
            }
        }

        return $events;
    }

    /**
     * Manual `review`: findings of the cold reviewer for PRs of GITHUB_OWNERS owners from the request.
     * Returns lines for the prompt and the path to the id file for the hook (null - ids not needed).
     * A collection failure for one PR (not a PR, no access, network) does not fail the request: the PR is skipped.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return array{list<string>, ?string}
     */
    private function manualReviewFindings(array $parts): array
    {
        if ($this->github === null) {
            return [[], null];
        }
        try {
            $prs = ColdFindings::extractPrs($parts, $this->config->githubOwners);
        } catch (\RuntimeException $e) {
            Log::line('manual review: '.$e->getMessage());

            return [['Cold reviewer (claude[bot]) findings were NOT collected by the bot: '.$e->getMessage().' — check PRs for cold reviewer findings manually (`gh api`).'], null];
        }
        $unavailable = array_slice($prs, ColdFindings::MAX_MANUAL_PRS);
        $prs = array_slice($prs, 0, ColdFindings::MAX_MANUAL_PRS);
        if ($unavailable !== []) {
            Log::line('manual review: PRs over the limit skipped: '.implode(', ', $unavailable));
        }
        // Bare numbers are looked up only when there are no explicit PRs: next to `a#7` a bare `#3` usually
        // means an issue/PR of the same repo, not a PR of another org repository.
        $notes = [];
        $bare = $prs === [] && $unavailable === []
            ? ColdFindings::extractBareNumbers($parts, ColdFindings::MAX_MANUAL_PRS, $this->config->githubOwners)
            : [];
        if ($bare !== []) {
            try {
                $repos = $this->github->openPrRepos($bare);
                foreach ($bare as $n) {
                    $found = $repos[$n] ?? [];
                    if (count($found) === 1) {
                        $prs[] = $found[0].'#'.$n;
                    } elseif ($found === []) {
                        $notes[] = "PR #{$n} was not found among open PRs of the owners in GITHUB_OWNERS";
                    } else {
                        $notes[] = "PR #{$n} is ambiguous: ".implode(', ', array_map(static fn (string $r): string => "{$r}#{$n}", $found)).' — specify the repository';
                    }
                }
            } catch (\Throwable $e) {
                Log::line('manual review: bare numbers not resolved: '.$e->getMessage());
                foreach ($bare as $n) {
                    $unavailable[] = "#{$n}";
                }
            }
            $prs = array_values(array_unique($prs));
        }
        $byPr = [];
        foreach ($prs as $pr) {
            try {
                $byPr[$pr] = $this->github->coldFindings($pr);
            } catch (\Throwable $e) {
                Log::line("manual review: findings of {$pr} not collected: ".$e->getMessage());
                $unavailable[] = $pr;
            }
        }
        if ($byPr === [] && $unavailable === []) {
            return [$notes, null];
        }
        $findings = ColdFindings::qualify($byPr);
        $idsPath = null;
        if ($findings !== []) {
            $path = ColdFindings::idsPath($this->config->stateDir);
            $json = json_encode(array_column($findings, 'id'), JSON_THROW_ON_ERROR);
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                Log::line('manual review: cannot create directory '.dirname($path));
            } elseif (file_put_contents($path, $json) === false) {
                Log::line("manual review: cannot write {$path}");
            } else {
                $idsPath = $path;
            }
        }

        return [ColdFindings::manualPromptLines($findings, $unavailable, $notes), $idsPath];
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
            self::KIND_CHAT => self::ITEM_CHAT,
            self::KIND_EPIC => self::ITEM_EPIC,
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
                $thinkingId = $this->tg->sendText($chatId, $text, null, $threadId, null, $kind === self::KIND_EPIC);
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
            $error = $this->launchJob($chatId, $threadId, $kind, $prompt, $sessionId, $thinkingId, $compactOld, $head['mode'], $head['id'], $epicSlug, $epicRun, $idsPath, $head['locale'] ?? null);
        } catch (\Throwable $e) {
            // The process is already started and tracked (activeJob is assigned right after
            // start()) - the job goes on as usual, pollJob() will clean up inbox files.
            if ($this->jobContext !== null) {
                $this->jobContext['inbox_files'] = $inboxFiles;
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
        if ($this->jobContext !== null) {
            $this->jobContext['inbox_files'] = $inboxFiles;
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
     * The dirty-tree decision is issued again (entering the pause), but only if the
     * queue is not empty - otherwise the old buttons are still alive.
     */
    private function recoverQueueOnStart(): void
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
            $this->delivery->safeDeliver($item['chat_id'], $item['thread_id'], $this->localeFor($item['locale'])->t('restart.job_interrupted'));
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

    /**
     * Whether a run of this kind gets the built-in sub-agents: the two `/compact` phases do not.
     */
    public static function runsWithAgents(string $kind): bool
    {
        return !in_array($kind, [self::KIND_COMPACT_SUMMARIZE, self::KIND_COMPACT_RESEED], true);
    }

    /**
     * Starts claude. The mode comes from the queue item (fixed when it was
     * enqueued) and lives on in jobContext; it is never read from sessions.
     * Returns the start error text or null.
     *
     * @param array{run:string, pr:?string}|null $epicRun
     */
    private function launchJob(int $chatId, int $threadId, string $kind, string $prompt, ?string $sessionId, int $thinkingId, ?string $compactOldSessionId, string $mode, int $queueId, ?string $epicSlug = null, ?array $epicRun = null, ?string $idsPath = null, ?string $localeCode = null): ?string
    {
        // Compact summarize runs in arch regardless of the topic mode: the summary must
        // stay detailed, otherwise after compression in review/pm we would lose the key
        // decisions (see prompts/compact_summarize_prompt.md.dist). Reseed creates the
        // session the topic lives on, so it runs in the topic mode (passed by the caller).
        if ($kind === self::KIND_COMPACT_SUMMARIZE) {
            $mode = SystemPrompt::MODE_ARCH;
        }

        $localeCode ??= $this->locale->code();

        // Files in the outbox at this point belong to someone else (a cancelled or
        // failed run): otherwise they would go out with this run's reply, possibly
        // to another topic.
        $this->delivery->clearOutbox();

        // Epic run signal: the previous file is stale. The path is passed to the process via the environment.
        $env = [];
        if ($epicSlug !== null) {
            $signalPath = Run::signalPath($this->config->stateDir, $epicSlug);
            if (!is_dir(dirname($signalPath)) && !mkdir(dirname($signalPath), 0775, true) && !is_dir(dirname($signalPath))) {
                return $this->t('launch.signal_dir_failed', ['dir' => dirname($signalPath)]);
            }
            if (is_file($signalPath) && !unlink($signalPath)) {
                return $this->t('launch.signal_remove_failed', ['path' => $signalPath]);
            }
            $env[Run::SIGNAL_ENV] = $signalPath;
        }
        if ($idsPath !== null) {
            $env[ColdFindings::IDS_ENV] = $idsPath;
        }

        // Issue-creation gate: the kind of run comes from the run (an epic run named `review` is not
        // the review chat), the config is snapshotted now so edits during the run do not affect it.
        $gateKind = $epicRun !== null ? PmRunbook::skipKeyForEpicRun($epicRun['run']) : PmRunbook::skipKeyForMode($mode);
        $gateRunId = null;
        $settingsJson = $mode === SystemPrompt::MODE_REVIEW ? ReviewStatusHook::settingsJson($this->config->stateDir) : '';
        if ($gateKind !== null) {
            $gateRunId = $queueId.'-'.bin2hex(random_bytes(4));
            try {
                $gateConfig = PmRunbook::config($this->absolutePromptsDir());
                foreach ($gateConfig['warnings'] as $warning) {
                    Log::line('pm gate: '.$warning);
                }
                PmGateHook::writeSnapshot($this->config->stateDir, $gateRunId, $gateConfig);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $env[PmGateHook::SKIP_ENV] = PmGateHook::skipPath($this->config->stateDir, $gateRunId);
            $settingsJson = PmGateHook::settingsJson($this->config->stateDir, $gateKind, $gateRunId, $mode === SystemPrompt::MODE_REVIEW);
        }

        // AUTO_COMMIT=0: the state of the tree before the process can touch it.
        $this->runTreeSnapshot = null;
        if (!$this->config->autoCommit) {
            try {
                $this->runTreeSnapshot = $this->git->treeSnapshot();
            } catch (\RuntimeException $e) {
                Log::line('tree snapshot failed: '.$e->getMessage());
            }
        }

        try {
            $runner = new ClaudeRunner(
                $this->config->claudeBin,
                $this->config->repoPath,
                $this->config->claudeTimeout,
                $this->config->claudeExtraArgs,
                $this->config->claudeStallTimeout,
                $this->config->claudeAppendSystemPrompt,
                $mode,
                settingsJson: $settingsJson,
                extraEnv: $env,
                promptsDir: $this->config->promptsDir,
                locale: $this->locale->with($localeCode),
                stateDir: $this->config->stateDir,
                withAgents: self::runsWithAgents($kind),
                homeDir: (string) getenv('HOME'),
                updateOnResume: $kind === self::KIND_CHAT,
                savedPromptHash: $kind === self::KIND_CHAT && $sessionId !== null ? $this->savedPromptHash($chatId, $threadId) : null,
            );
            $runner->start($prompt, $sessionId);
        } catch (\RuntimeException $e) {
            if ($gateRunId !== null) {
                PmGateHook::cleanup($this->config->stateDir, $gateRunId);
            }

            return $e->getMessage();
        }

        // The process is already alive: activeJob/jobContext are assigned
        // IMMEDIATELY, before any side effects, otherwise an exception below would
        // leave an untracked claude and the next job would start while the process
        // is still alive.
        $this->activeJob = $runner;
        $this->jobContext = [
            'kind' => $kind,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'thinking_id' => $thinkingId,
            'prompt' => $prompt,
            'last_progress_at' => time(),
            'shown_count' => 0,
            'compact_old_session_id' => $compactOldSessionId,
            'session_saved' => false,
            'inbox_files' => [],
            'mode' => $mode,
            'locale' => $localeCode,
            'queue_id' => $queueId,
            'epic_slug' => $epicSlug,
            'epic_run' => $epicRun,
            'pm_gate_run_id' => $gateRunId,
            'prompt_hash' => $runner->promptHash(),
        ];

        // The topic of the last job that touched the tree owns the commit/discard decision.
        if ($this->config->autoCommit) {
            $this->db->setDirtyOwner($chatId, $threadId);
        }
        $this->repoSnapshot = $this->subRepoSync->snapshotSubRepos();
        $this->db->setPid($chatId, $threadId, $runner->pid());

        return null;
    }

    /** Hash of the system prompt the stored session was last run with; null if unknown. */
    private function savedPromptHash(int $chatId, int $threadId): ?string
    {
        $hash = $this->db->getSession($chatId, $threadId)['prompt_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * As soon as Claude emits session_id (the stream init event), we save it to
     * the DB while the run is in progress; otherwise /cancel or a timeout would
     * orphan the new transcript holding the request just sent, while the DB would
     * keep the previous session (without it). Only for KIND_CHAT: for the compact
     * phases session_id is managed specially (finishCompactReseed after
     * resetSession), an early write would break the accounting.
     */
    private function persistSessionEarly(): void
    {
        if ($this->activeJob === null || $this->jobContext === null) {
            return;
        }
        if ($this->jobContext['session_saved'] || $this->jobContext['kind'] !== self::KIND_CHAT) {
            return;
        }
        $sid = $this->activeJob->sessionId();
        if ($sid === null) {
            return;
        }
        $this->db->setSessionId($this->jobContext['chat_id'], $this->jobContext['thread_id'], $sid);
        $this->jobContext['session_saved'] = true;
    }

    private function pollJob(): void
    {
        if ($this->activeJob === null || $this->jobContext === null) {
            return;
        }

        $chatId = $this->jobContext['chat_id'];
        $threadId = $this->jobContext['thread_id'];

        try {
            $state = $this->activeJob->poll();
        } catch (\Throwable $e) {
            // The process is in an inconsistent state: drop the job, otherwise the
            // following ticks would loop on poll() of a closed process.
            $queueId = $this->jobContext['queue_id'];
            $this->finishJobState($chatId, $threadId);
            if ($queueId > 0) {
                $this->db->queueRemove($queueId);
            }

            throw $e;
        }

        if ($state === RunnerState::Running) {
            $this->persistSessionEarly();
            $this->maybeUpdateProgress();

            return;
        }

        $thinkingId = $this->jobContext['thinking_id'];
        $prompt = $this->jobContext['prompt'];
        $kind = $this->jobContext['kind'];
        $mode = $this->jobContext['mode'];
        $localeCode = $this->jobContext['locale'];
        $queueId = $this->jobContext['queue_id'];
        $epicSlug = $this->jobContext['epic_slug'];
        $epicRun = $this->jobContext['epic_run'];
        // The skip reason is read before finishJobState() removes the gate files.
        $gateSkip = $this->jobContext['pm_gate_run_id'] !== null
            ? PmGateHook::skipReason($this->config->stateDir, $this->jobContext['pm_gate_run_id'])
            : '';
        $compactOld = $this->jobContext['compact_old_session_id'] ?? null;
        $promptHash = $this->jobContext['prompt_hash'];
        // Capture BEFORE finishJobState(), which resets jobContext.
        $inboxFiles = $this->jobContext['inbox_files'];
        $requeued = false;

        try {
            if ($state === RunnerState::Timeout) {
                $reason = $this->activeJob->timeoutReason();
                $timeoutWarnings = $this->activeJob->promptWarnings();
                $this->finishJobState($chatId, $threadId);
                $this->inbox->deleteInboxFiles($inboxFiles);
                $this->tg->deleteMessage($chatId, $thinkingId);
                $this->delivery->flushOutbox($chatId, $threadId);
                $this->delivery->deliver($chatId, $threadId, $this->t('run.killed', ['reason' => $reason !== '' ? $reason : $this->t('run.timeout')]));
                if ($gateSkip !== '') {
                    $this->reportPmGateSkip($chatId, $threadId, $kind, $epicSlug, $epicRun, $gateSkip);
                }
                $this->reportPromptWarnings($chatId, $threadId, $timeoutWarnings, true);
                $this->afterFailedRun($chatId, $threadId, $epicSlug, 'process killed: '.($reason !== '' ? $reason : 'timeout'));

                return;
            }

            // Done: take the result before the state is reset (result() is valid only here).
            $claude = $this->activeJob->result();
            $promptWarnings = $this->activeJob->promptWarnings();
            if ($claude->exitedWithoutResult) {
                $diagnostics = $this->activeJob->diagnosticsPath();
                Log::line('claude exited with code 0 without a result event'
                    .($claude->recovered ? ' (answer recovered from the last assistant message)' : '')
                    .($diagnostics !== null ? "; diagnostics: {$diagnostics}" : ''));
            }
            // A recovered answer is just the last assistant message and may be partial: never feed it
            // to compact (it would replace the session context); mark it in a chat reply.
            if ($claude->recovered && !$claude->isError) {
                if (in_array($kind, [self::KIND_COMPACT_SUMMARIZE, self::KIND_COMPACT_RESEED], true)) {
                    $claude = new ClaudeResult(true, $this->t('claude.recovered_unusable'), $claude->sessionId, $claude->inputTokens, $claude->outputTokens, true, true);
                } elseif ($kind === self::KIND_CHAT) {
                    $claude = new ClaudeResult(false, $claude->text."\n\n".$this->t('claude.recovered_note'), $claude->sessionId, $claude->inputTokens, $claude->outputTokens, true, true);
                }
            }
            // sendResult() appends the warnings to a normal reply itself; every
            // other outcome (error, epic, compact) gets a separate message.
            $this->reportPromptWarnings($chatId, $threadId, $promptWarnings, $claude->isError || $kind !== self::KIND_CHAT);
            // A chat reply carries the gate note itself (sendResult appends the warnings); every other
            // outcome gets a separate message after the job state is dropped.
            $gateInReply = $kind === self::KIND_CHAT && !$claude->isError;
            if ($gateSkip !== '' && $gateInReply) {
                $promptWarnings[] = $this->t('result.pm_gate_skipped', ['reason' => $gateSkip]);
            }

            // Drop the job BEFORE delivery to Telegram: a delivery failure then cannot
            // leave a dangling activeJob or make poll() loop on a closed process.
            $this->finishJobState($chatId, $threadId);
            $this->inbox->deleteInboxFiles($inboxFiles);
            if ($gateSkip !== '' && !$gateInReply) {
                $this->reportPmGateSkip($chatId, $threadId, $kind, $epicSlug, $epicRun, $gateSkip);
            }

            $this->tg->deleteMessage($chatId, $thinkingId);

            // Outbox files go out BEFORE the text reply (to the same topic); the
            // directory is always cleared regardless of the run outcome (error or
            // success below).
            $this->delivery->flushOutbox($chatId, $threadId);

            // KIND_COMPACT_RESEED writes to the DB itself (finishCompactReseed), after
            // resetSession; otherwise the accumulated counters of the old session
            // would be added on top of the new one. KIND_EPIC is isolated: its
            // session_id must not become the topic's session.
            if ($claude->sessionId !== null && !in_array($kind, [self::KIND_COMPACT_RESEED, self::KIND_EPIC], true)) {
                // The prompt hash moves only after a successful run (a failed one leaves the update block due) and
                // never after the summarize phase, which runs under another prompt.
                $this->db->recordRun($chatId, $threadId, $claude->sessionId, $claude->inputTokens, $claude->outputTokens, $claude->isError || $kind === self::KIND_COMPACT_SUMMARIZE ? null : $promptHash);
            }

            // An epic run is judged by its signal file, not by the reply, so a clean exit
            // without a result event is not a failure there.
            if ($claude->isError && !($claude->exitedWithoutResult && $kind === self::KIND_EPIC)) {
                $limit = $this->claudeErrorText->parseLimit($claude->text);
                if ($limit !== null && $queueId > 0) {
                    // A Claude limit pauses the WHOLE queue until the reset time; the item
                    // returns as the head (its id does not change) and the following ones
                    // do not start, so there is no cascade of identical errors.
                    $until = min($limit['reset_at'] ?? time() + self::LIMIT_PAUSE_FALLBACK_SEC, time() + self::LIMIT_PAUSE_MAX_SEC);
                    $this->db->setLimitPauseUntil($until);
                    $this->db->queueRelease($queueId);
                    $requeued = true;
                    $this->delivery->deliver($chatId, $threadId, $limit['message']."\n".$this->t('run.queue_paused_until', ['until' => date('H:i d.m', $until)]));
                    $this->afterFailedRun($chatId, $threadId, $epicSlug, 'Claude limit: '.$limit['message']);

                    return;
                }

                $this->delivery->deliver($chatId, $threadId, $this->claudeErrorText->format($claude->text));
                $this->afterFailedRun($chatId, $threadId, $epicSlug, 'Claude error: '.$claude->text);

                return;
            }

            match ($kind) {
                self::KIND_COMPACT_SUMMARIZE => $this->finishCompactSummarize($chatId, $threadId, $claude, $compactOld, $queueId, $localeCode),
                self::KIND_COMPACT_RESEED => $this->finishCompactReseed($chatId, $threadId, $claude, $compactOld, $promptHash),
                self::KIND_EPIC => $this->finishEpicRunRemembering($chatId, $threadId, (string) $epicSlug, $epicRun),
                default => $this->sendResult($chatId, $threadId, $claude, $prompt, $mode, $promptWarnings),
            };
        } finally {
            // The item is removed when the run finishes. Exceptions: a return to the
            // head because of the limit and the compact summarize -> reseed
            // transition (the job is alive).
            if (!$requeued && $this->activeJob === null && $queueId > 0) {
                $this->db->queueRemove($queueId);
            }
            // The run ended with a clean tree (including via auto-commit), so there is
            // no dirt owner; otherwise external dirt would later be attributed to this
            // topic.
            if ($this->activeJob === null && !$this->git->hasChanges()) {
                $this->db->clearDirtyOwner();
            }
            if ($this->activeJob === null) {
                $this->runTreeSnapshot = null;
            }
        }
    }

    /**
     * The gate let an issue through without all pipeline stages: tell the author in a separate message.
     *
     * @param array{run:string, pr:?string}|null $epicRun
     */
    private function reportPmGateSkip(int $chatId, int $threadId, string $kind, ?string $epicSlug, ?array $epicRun, string $reason): void
    {
        Log::line('pm gate: the pipeline stages were skipped: '.$reason);
        $this->delivery->deliver($chatId, $threadId, $kind === self::KIND_EPIC
            ? $this->t('epicrun.pm_gate_skipped', ['slug' => (string) $epicSlug, 'run' => (string) ($epicRun['run'] ?? ''), 'reason' => $reason])
            : $this->t('result.pm_gate_skipped', ['reason' => $reason]));
    }

    /** Epic run outcome + GitHub fingerprint AFTER it (the run's own edits do not wake the tick). */
    /**
     * @param array{run:string, pr:?string}|null $epicRun
     */
    private function finishEpicRunRemembering(int $chatId, int $threadId, string $slug, ?array $epicRun): void
    {
        try {
            $this->finishEpicRun($chatId, $threadId, $slug, $epicRun);
        } finally {
            $this->orchestrator?->rememberAfterRun($slug);
        }
    }

    /**
     * Epic run finished: the reply text does not go to the topic, the bot reads
     * only the signal. An invalid or missing signal is a run failure (a message
     * to the topic, nothing from the signal is applied).
     */
    /**
     * @param array{run:string, pr:?string}|null $epicRun
     */
    private function finishEpicRun(int $chatId, int $threadId, string $slug, ?array $epicRun = null): void
    {
        try {
            $path = Run::signalPath($this->config->stateDir, $slug);
            $raw = is_file($path) ? file_get_contents($path) : false;
            if ($raw === false) {
                throw new \InvalidArgumentException('signal file not created');
            }
            $signal = Signal::parse($raw, $this->config->githubOwners);

            $known = array_column($this->db->epicTasks($slug), 'task');
            $known = [...$known, ...array_column($signal->added, 'task')];
            $refs = [...($signal->hold ?? [])];
            foreach ($signal->added as $added) {
                $refs = [...$refs, ...$added['after']];
            }
            foreach ($refs as $ref) {
                if (!in_array($ref, $known, true)) {
                    throw new \InvalidArgumentException($this->t('epicrun.task_not_in_epic', ['ref' => $ref]));
                }
            }
        } catch (\InvalidArgumentException $e) {
            Log::line("epic {$slug}: run failed: ".$e->getMessage());
            $this->delivery->deliver($chatId, $threadId, $this->t('epicrun.signal_invalid', ['slug' => $slug, 'error' => $e->getMessage()]));
            $this->failEpicRun($slug, 'invalid signal: '.$e->getMessage());

            return;
        }

        if ($epicRun !== null && $epicRun['run'] === Run::REVIEW && $epicRun['pr'] !== null
            && !$this->checkColdCoverage($chatId, $threadId, $slug, $epicRun['pr'], $signal)) {
            return;
        }

        // blocked outranks done: a stopped epic is not considered completed.
        if ($this->db->epicApplySignal($slug, $signal->added, $signal->hold, $signal->done && $signal->blocked === null)) {
            $this->delivery->deliver($chatId, $threadId, $this->t('epicrun.tails_ceiling', ['slug' => $slug, 'rounds' => Database::MAX_TAILS_ROUNDS]));
        }
        if ($signal->message !== null) {
            $this->delivery->deliver($chatId, $threadId, $signal->message);
        }
        if ($signal->blocked !== null) {
            $this->blockEpic($slug, $signal->blocked);
        }
        $this->epicCard->update($slug);

        // A successful run with leftover dirt is auto-committed, like a regular request.
        // With AUTO_COMMIT=0 the leftovers stay in the tree; only the prompt-files warning is given.
        if (!$this->config->autoCommit) {
            $this->deliverPromptFilesWarning($chatId, $threadId);
        } elseif ($this->git->hasChanges()) {
            $this->deliverPromptFilesWarning($chatId, $threadId);
            try {
                $this->git->commitAndPush($this->commitMessage("epic {$slug}"));
            } catch (\RuntimeException $e) {
                $this->delivery->deliver($chatId, $threadId, $this->t('epicrun.git_failed', ['slug' => $slug, 'error' => $e->getMessage()]));
                $this->offerDecisionIfDirty($chatId, $threadId);
            }
        }
    }

    /**
     * Coverage of the cold reviewer's findings by the `review` run signal.
     * Full coverage means the task's `coverage_head` = the head from the saved
     * file. A failure is a run failure, with a repeated review of the same head
     * (`reviewed_head` is reset, `cold_retry` lets the review pipeline run on top
     * of an already published one); after the {@see ColdFindings::MAX_ATTEMPTS}-th
     * failure on a head the task is blocked.
     *
     * @param string $pr `repo#N`
     *
     * @return bool true if the coverage is full
     */
    private function checkColdCoverage(int $chatId, int $threadId, string $slug, string $pr, Signal $signal): bool
    {
        $cold = ColdFindings::load(ColdFindings::path($this->config->stateDir, $slug, $pr));
        if ($cold === null) {
            $reason = $this->t('epicrun.no_cold_list', ['pr' => $pr]);
            $this->delivery->deliver($chatId, $threadId, $this->t('epicrun.failed', ['slug' => $slug, 'reason' => $reason]));
            $this->failEpicRun($slug, $reason);

            return false;
        }
        $result = ColdFindings::check($signal, $cold['findings'], $this->locale);
        if ($result['problems'] === []) {
            // The merge gate opens only on an approval with no open findings: request_changes gives no coverage.
            if ($signal->verdict === Signal::VERDICT_APPROVE && $result['open'] === []) {
                $this->db->epicSetTaskCounter($slug, $cold['task'], 'coverage_head', $cold['head']);
            }

            return true;
        }

        $reason = implode('; ', $result['problems']);
        $this->delivery->deliver($chatId, $threadId, $this->t('epicrun.coverage_incomplete', ['slug' => $slug, 'pr' => $pr, 'reason' => $reason]));
        $this->failEpicRun($slug, "findings coverage {$pr}: {$reason}");

        $counters = [];
        foreach ($this->db->epicTasks($slug) as $task) {
            if ($task['task'] === $cold['task']) {
                $counters = $task['counters'];
            }
        }
        [$prevHead, $prevCount] = array_pad(explode(':', (string) ($counters['cold_attempts'] ?? ''), 2), 2, '');
        $attempts = ($prevHead === $cold['head'] ? (int) $prevCount : 0) + 1;
        $this->db->epicSetTaskCounter($slug, $cold['task'], 'cold_attempts', $cold['head'].':'.$attempts);

        if ($attempts >= ColdFindings::MAX_ATTEMPTS) {
            $this->db->epicSetTaskStatus($slug, $cold['task'], 'blocked');
            $ids = array_values(array_unique([...$result['missing'], ...$result['open']]));
            $list = $ids !== [] ? implode(', ', $ids) : $this->t('epicrun.no_verdict');
            $this->notifyEpic($slug, $this->t('epicrun.hot_review_gave_up', ['task' => $cold['task'], 'attempts' => $attempts, 'list' => $list]));
            $this->epicCard->update($slug);

            return false;
        }
        $this->db->epicSetTaskCounter($slug, $cold['task'], 'reviewed_head', '');
        $this->db->epicSetTaskCounter($slug, $cold['task'], 'cold_retry', $cold['head']);

        return false;
    }

    /**
     * Single transition of an epic to `blocked`: status, reason and exactly one
     * message to the epic's topic. All stops go through it (run signal, task
     * and merge-gate stops); callers send no stop messages of their own.
     */
    private function notifyEpic(string $slug, string $text): void
    {
        $epic = $this->db->epicGet($slug);
        if ($epic !== null) {
            $this->delivery->deliver($epic['chat_id'], $epic['thread_id'], "⚠️ {$text}");
        }
    }

    private function blockEpic(string $slug, string $reason): void
    {
        $epic = $this->db->epicGet($slug);
        if ($epic === null) {
            return;
        }
        $this->db->epicBlock($slug, $reason);
        $this->epicCard->update($slug);
        $this->delivery->deliver($epic['chat_id'], $epic['thread_id'], $this->t('epic.blocked', ['reason' => $reason]));
    }

    /**
     * A failed regular request gets the commit/discard buttons and a queue
     * pause; an epic run failure is handled without a human, see
     * {@see failEpicRun()}.
     */
    private function afterFailedRun(int $chatId, int $threadId, ?string $epicSlug, string $reason): void
    {
        if ($epicSlug === null) {
            $this->offerDecisionIfDirty($chatId, $threadId);

            return;
        }
        $this->failEpicRun($epicSlug, $reason);
        $this->orchestrator?->rememberAfterRun($epicSlug);
    }

    /**
     * Epic run failure: the whole dirty tree (including untracked and binary
     * files) is saved as a patch to `state/epics/<slug>/dirty-<ts>.patch`, then
     * the tree is rolled back; a record goes to `failures.log`. There are no
     * buttons or queue pause, and the failure is not committed to the
     * repository. The epic continues.
     */
    private function failEpicRun(string $slug, string $reason): void
    {
        $dir = $this->config->stateDir.'/epics/'.$slug;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Log::line("epic {$slug}: cannot create {$dir}");

            return;
        }

        // AUTO_COMMIT=0: the tree is the operator's, so there is no patch and no rollback.
        $patchName = null;
        if ($this->config->autoCommit && $this->git->hasChanges()) {
            try {
                $patch = $this->git->dirtyPatch();
                if ($patch === '') {
                    throw new \RuntimeException('patch is empty for a dirty tree');
                }
                $base = 'dirty-'.date('Ymd-His');
                $patchName = $base.'.patch';
                for ($i = 2; file_exists($dir.'/'.$patchName); ++$i) {
                    $patchName = "{$base}-{$i}.patch";
                }
                if (file_put_contents($dir.'/'.$patchName, $patch) !== strlen($patch)) {
                    throw new \RuntimeException("cannot write {$patchName}");
                }
            } catch (\RuntimeException $e) {
                // There is no patch, so we do not roll the tree back, or the edits would be lost.
                $this->git->unstage();
                Log::line("epic {$slug}: {$e->getMessage()}, not rolling back the tree");
                $this->failLog($dir, $reason.' (patch not saved: '.$e->getMessage().', tree not rolled back)', null);

                return;
            }
            $this->git->discardChanges();
        }
        $this->db->clearDirtyOwner();
        $this->failLog($dir, $reason, $patchName);
        Log::line("epic {$slug}: run failed: {$reason}".($patchName !== null ? " (patch {$patchName})" : ''));
    }

    private function failLog(string $dir, string $reason, ?string $patchName): void
    {
        $line = date('c').' '.str_replace(["\r", "\n"], ' ', $reason).($patchName !== null ? ' patch='.$patchName : '')."\n";
        file_put_contents($dir.'/failures.log', $line, FILE_APPEND);
    }

    /**
     * Phase 1 of /compact finished: we got the summary of the old session. If
     * Claude disobeyed and changed something in the repository, we go no
     * further, hand the decision to the user (commit/discard) and leave the
     * session untouched.
     */
    /**
     * Paths the summarize run changed: the whole dirty tree with AUTO_COMMIT=1, the per-run diff with 0.
     *
     * @return list<string>
     */
    private function compactChangedPaths(): array
    {
        if ($this->config->autoCommit) {
            return $this->git->hasChanges() ? ['*'] : [];
        }
        if ($this->runTreeSnapshot === null) {
            return [];
        }
        try {
            return $this->git->changedSince($this->runTreeSnapshot);
        } catch (\RuntimeException $e) {
            Log::line('compact: tree snapshot diff failed: '.$e->getMessage());

            return [];
        }
    }

    private function finishCompactSummarize(int $chatId, int $threadId, ClaudeResult $claude, ?string $compactOldSessionId, int $queueId, string $localeCode): void
    {
        $summary = trim($claude->text);
        if ($summary === '') {
            $this->delivery->deliver($chatId, $threadId, $this->t('compact.no_summary'));

            return;
        }

        $changed = $this->compactChangedPaths();
        if ($changed !== []) {
            $this->delivery->deliver(
                $chatId,
                $threadId,
                $this->t('compact.dirty', ['changes' => $this->config->autoCommit ? $this->git->changesSummary() : implode("\n", $changed)]),
            );
            $this->offerDecisionIfDirty($chatId, $threadId);

            return;
        }

        $thinkingId = $this->tg->sendText($chatId, $this->t('compact.reseeding'), null, $threadId);
        try {
            $prompt = PromptFiles::load('compact_reseed_prefix')."\n".$summary."\n\n".PromptFiles::load('compact_reseed_suffix');
        } catch (\RuntimeException $e) {
            $this->tg->deleteMessage($chatId, $thinkingId);
            $this->delivery->deliver($chatId, $threadId, $this->t('queue.claude_start_failed', ['error' => $e->getMessage()]));

            return;
        }
        $error = $this->launchJob($chatId, $threadId, self::KIND_COMPACT_RESEED, $prompt, null, $thinkingId, $compactOldSessionId, $this->db->getMode($chatId, $threadId), $queueId, localeCode: $localeCode);
        if ($error !== null) {
            $this->tg->deleteMessage($chatId, $thinkingId);
            $this->delivery->deliver($chatId, $threadId, $this->t('queue.claude_start_failed', ['error' => $error]));
        }
    }

    /**
     * Phase 2 of /compact finished: the new session is created. Only now do we
     * reset the old counters and record the new ones; had we reset them earlier
     * (before making sure the new session really started), a start failure
     * (see launchJob) would leave us with neither the old context nor the new one.
     */
    private function finishCompactReseed(int $chatId, int $threadId, ClaudeResult $claude, ?string $compactOldSessionId, ?string $promptHash): void
    {
        if ($claude->sessionId === null) {
            $this->delivery->deliver($chatId, $threadId, $this->t('compact.reseed_failed'));

            return;
        }

        $this->db->resetSession($chatId, $threadId);
        $this->db->recordRun($chatId, $threadId, $claude->sessionId, $claude->inputTokens, $claude->outputTokens, $promptHash);

        // The new session was created successfully, so the transcript of the
        // pre-compact session on disk is no longer needed. We delete it only now:
        // when reseed fails (above) the old session is kept for a repeated
        // /compact and its file must not be touched.
        if ($compactOldSessionId !== null) {
            $this->sessionFiles->delete($compactOldSessionId);
        }

        $text = $claude->text !== '' ? $claude->text : $this->t('result.empty');
        $this->delivery->deliver($chatId, $threadId, $this->t('compact.done', ['text' => $text]));
        $this->offerDecisionIfDirty($chatId, $threadId);
    }

    /**
     * Live update of the "thinking" message with the list of Claude's tool calls.
     * Throttled to >=3 s (Telegram limit is ~1 edit/s) and only on new lines.
     * editMessageText addresses by message_id, so the topic (thread_id) is not needed.
     */
    private function maybeUpdateProgress(): void
    {
        if ($this->activeJob === null || $this->jobContext === null) {
            return;
        }

        $lines = $this->activeJob->getProgressLines();
        if (count($lines) <= $this->jobContext['shown_count']) {
            return; // no new calls
        }
        if (time() - $this->jobContext['last_progress_at'] < 3) {
            return; // throttle
        }

        $tail = array_slice($lines, -15);
        $this->tg->editMessageText(
            $this->jobContext['chat_id'],
            $this->jobContext['thinking_id'],
            $this->thinkingText($this->jobContext['mode'])."\n\n".implode("\n", $tail),
        );

        $this->jobContext['last_progress_at'] = time();
        $this->jobContext['shown_count'] = count($lines);
    }

    /**
     * Persona-file problems always go to the log; with $notify also to the chat
     * as a separate message (outcomes that do not go through sendResult()).
     *
     * @param list<string> $warnings
     */
    private function reportPromptWarnings(int $chatId, int $threadId, array $warnings, bool $notify): void
    {
        foreach ($warnings as $warning) {
            Log::line('prompt: '.$warning);
            if ($notify) {
                $this->delivery->deliver($chatId, $threadId, '⚠️ '.$warning);
            }
        }
    }

    /**
     * @param list<string> $promptWarnings
     */
    private function sendResult(int $chatId, int $threadId, ClaudeResult $claude, string $prompt, string $mode, array $promptWarnings = []): void
    {
        $text = $claude->text !== '' ? $claude->text : $this->t('result.empty');
        foreach ($promptWarnings as $warning) {
            $text .= "\n\n⚠️ ".$warning;
        }

        // The review Stop hook did not wait for the review status in the PR and
        // let the turn go; say so explicitly, otherwise the "approve posted"
        // verdict in the model's text looks true (see ReviewStatusHook).
        if ($claude->sessionId !== null && ReviewStatusHook::consumeMarker($this->config->stateDir, $claude->sessionId)) {
            $text .= "\n\n".$this->t('result.review_status_missing');
        }
        if ($claude->sessionId !== null && ($missingIds = ReviewStatusHook::consumeIdsMarker($this->config->stateDir, $claude->sessionId)) !== null) {
            $text .= "\n\n".$this->t('result.review_verdicts_missing', ['ids' => implode(', ', $missingIds)]);
        }

        $text .= $this->promptFilesWarning($this->changedPromptFiles());

        $commitLinks = [];

        if (!$this->config->autoCommit) {
            try {
                $changed = $this->runTreeSnapshot !== null ? $this->git->changedSince($this->runTreeSnapshot) : [];
            } catch (\RuntimeException $e) {
                Log::line('tree snapshot diff failed: '.$e->getMessage());
                $changed = [];
            }
            if ($changed !== []) {
                $text .= "\n\n".$this->t('result.changed_files', ['count' => count($changed)]);
            }
        } elseif ($this->git->hasChanges()) {
            try {
                $hash = $this->git->commitAndPush($this->commitMessage($prompt));
                $commitLinks[] = $this->commitUrl($hash);
            } catch (\RuntimeException $e) {
                $text .= "\n\n".$this->t('result.git_failed', ['error' => $e->getMessage()]);
            }
        }

        $commitLinks = array_merge($commitLinks, $this->subRepoSync->collectSubRepoLinks($this->repoSnapshot ?? []));
        $this->repoSnapshot = null;

        if ($commitLinks !== []) {
            $text .= "\n\n".$this->t('result.committed')."\n".implode("\n", $commitLinks);
        }

        if ($this->db->isVerbose($chatId, $threadId)) {
            $text .= "\n\n".$this->t('result.tokens', [
                'input' => number_format($claude->inputTokens, 0, '.', ' '),
                'output' => number_format($claude->outputTokens, 0, '.', ' '),
            ]);
        }

        $this->delivery->deliver($chatId, $threadId, $text, null, $this->delivery->overflowCaption($mode));
    }

    /**
     * Prompt files under PROMPTS_DIR and locale files under LOCALES_DIR changed by the run,
     * before the auto-commit. Both shape future behaviour (locale texts can even swap the
     * labels of the commit/discard buttons), so they are reported. A directory outside the
     * repository is not tracked, so nothing is reported for it.
     *
     * @return list<string>
     */
    private function changedPromptFiles(): array
    {
        $files = [];
        foreach ([$this->config->promptsDir, $this->config->localesDir] as $dir) {
            if ($dir === '' || str_contains($dir, '..')) {
                continue;
            }

            try {
                if (!$this->config->autoCommit) {
                    $files = array_merge($files, $this->runTreeSnapshot !== null ? $this->git->changedSince($this->runTreeSnapshot, $dir) : []);

                    continue;
                }
                $files = array_merge($files, $this->git->changedPathsIn($dir));
            } catch (\RuntimeException) {
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Separate chat message for commit paths that do not go through sendResult()
     * (autonomous epic runs, the manual "commit" button).
     */
    private function deliverPromptFilesWarning(int $chatId, int $threadId): void
    {
        $warning = trim($this->promptFilesWarning($this->changedPromptFiles()));
        if ($warning !== '') {
            Log::line('prompt: '.$warning);
            $this->delivery->deliver($chatId, $threadId, $warning);
        }
    }

    /**
     * @param list<string> $files
     */
    private function promptFilesWarning(array $files): string
    {
        if ($files === []) {
            return '';
        }

        $list = implode(', ', $files);

        return "\n\n".($this->config->autoCommit
            ? $this->t('prompt_files.changed', ['files' => $list])
            : $this->t('prompt_files.changed_uncommitted', ['files' => $list]));
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $userId = (int) ($callback['from']['id'] ?? 0);
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');

        if (!$this->config->isAllowed($userId)) {
            $this->tg->answerCallback($callbackId, $this->t('access.denied'));

            return;
        }

        // The mode branch is stateless (the topic is carried in data, not in the
        // decision record) and must be handled BEFORE the decision check below,
        // otherwise the /mode keyboard would either clobber a pending commit/discard
        // decision or not work at all (the decision check would reject it first).
        // This branch neither reads nor writes the decision record itself. Rule:
        // .claude-docs/gotchas.md.
        if (str_starts_with($data, 'mode:')) {
            $this->modeAndLang->handleModeCallback($callbackId, $chatId, $message, $data);

            return;
        }

        // The lang branch is stateless for the same reason: the language is stored per user.
        if (str_starts_with($data, 'lang:')) {
            $this->modeAndLang->handleLangCallback($callbackId, $userId, $chatId, is_array($message) ? $message : [], $data);

            return;
        }

        // The epic branch is stateless too: the topic and the epic slug are carried in data.
        if (str_starts_with($data, 'epic:')) {
            $this->epicChat->handleEpicCallback($callbackId, $chatId, is_array($message) ? $message : [], $data);

            return;
        }

        // Under AUTO_COMMIT=0 the bot never commits or resets the tree, so a button issued earlier is refused.
        if (!$this->config->autoCommit && ($data === 'commit' || $data === 'discard')) {
            $this->tg->answerCallback($callbackId, $this->t('decision.auto_commit_off'));

            return;
        }

        $threadId = is_array($message) ? $this->threadIdOf($message) : 0;

        // A decision belongs to a specific topic (chat_id, thread_id): in a forum
        // all topics share chat_id, so the topic is checked too, otherwise a button
        // from another topic would commit/discard someone else's tree. The check is
        // against the SQLite record (survives /restart), not an in-memory field.
        $decision = $this->db->getDecision();
        $callbackMessageId = (int) ($message['message_id'] ?? 0);
        if ($decision === null
            || $decision['chat_id'] !== $chatId
            || $decision['thread_id'] !== $threadId
            || ($decision['message_id'] > 0 && $callbackMessageId > 0 && $decision['message_id'] !== $callbackMessageId)) {
            $this->tg->answerCallback($callbackId, $this->t('decision.stale'));

            return;
        }

        // The tree was cleaned manually while the buttons were hanging.
        if (!$this->git->hasChanges()) {
            $this->db->clearDecision();
            $this->db->clearDirtyOwner();
            $this->tg->answerCallback($callbackId, $this->t('decision.stale'));

            return;
        }

        $external = $this->db->getDirtyOwner() === null;
        $this->db->clearDecision();

        if ($data === 'commit') {
            $this->deliverPromptFilesWarning($chatId, $threadId);
            try {
                $hash = $this->git->commitAndPush($external
                    ? $this->t('commit.external')
                    : $this->t('commit.interrupted'));
                $this->db->clearDirtyOwner();
                $this->tg->answerCallback($callbackId, $this->t('decision.committed_toast'));
                $this->delivery->deliver($chatId, $threadId, $this->t('decision.saved', ['url' => $this->commitUrl($hash)]));
            } catch (\RuntimeException $e) {
                $this->tg->answerCallback($callbackId, $this->t('decision.git_error_toast'));
                $this->delivery->deliver($chatId, $threadId, $this->t('decision.git_failed', ['error' => $e->getMessage()]));
                // A failed commit is a new entry into the pause: buttons are issued again.
                $this->ensureDecision($chatId, $threadId);
            }

            return;
        }

        if ($data === 'discard') {
            $this->git->discardChanges();
            $this->db->clearDirtyOwner();
            $this->tg->answerCallback($callbackId, $this->t('decision.discarded_toast'));
            $this->delivery->deliver($chatId, $threadId, $this->t('decision.discarded'));

            return;
        }

        $this->tg->answerCallback($callbackId);
    }

    /**
     * Entering the pause when a job ends/is cancelled: if the tree is dirty, the
     * commit/discard buttons go to the owner topic (the topic of this job). The
     * decision record is stored in SQLite; there is no auto-commit or auto-rollback.
     */
    private function offerDecisionIfDirty(int $chatId, int $threadId): void
    {
        if (!$this->config->autoCommit) {
            return;
        }
        if (!$this->git->hasChanges()) {
            // The run left a clean tree — there is no owner of the dirt;
            // otherwise later external dirt would be attributed to this topic.
            $this->db->clearDirtyOwner();

            return;
        }

        $this->db->clearDecision();
        $this->ensureDecision($chatId, $threadId);
    }

    /**
     * Issues the commit/discard buttons if no decision is pending yet. The owner
     * is the topic of the last job that touched the tree (stored in the DB). If
     * the owner is unknown ("external" dirt), the buttons go to the head topic
     * ($fallback*) with a note "changes not from this request". Topics with
     * pending items get a "waiting for a decision in topic X" notification.
     */
    private function ensureDecision(int $fallbackChatId, int $fallbackThreadId): void
    {
        if (!$this->config->autoCommit) {
            return;
        }
        if ($this->db->getDecision() !== null) {
            return;
        }

        $owner = $this->db->getDirtyOwner();
        $chatId = $owner['chat_id'] ?? $fallbackChatId;
        $threadId = $owner['thread_id'] ?? $fallbackThreadId;

        $markup = [
            'inline_keyboard' => [[
                ['text' => $this->t('decision.button_commit'), 'callback_data' => 'commit'],
                ['text' => $this->t('decision.button_discard'), 'callback_data' => 'discard'],
            ]],
        ];

        // The message with buttons must go out as TEXT: a long one goes as a file
        // without reply_markup, the decision would be left without buttons and the
        // queue on an eternal pause. So the summary in it is truncated (with a
        // margin for HTML escaping), and the full list goes as a separate message below.
        $summary = $this->git->changesSummary();
        $short = $this->delivery->truncateSummary($summary);
        $text = ($owner === null ? $this->t('decision.external')."\n\n" : '')
            .$this->t('decision.uncommitted')."\n\n".$short."\n\n".$this->t('decision.question');

        // Key the decision by the topic where the buttons ACTUALLY landed: with a
        // deleted topic deliver() falls back to General (0), and the callback will
        // come with thread_id=0. Keeping the original (deleted) topic would make a
        // click yield "decision is no longer relevant" and the dirty tree could not
        // be resolved interactively.
        $landed = $this->delivery->deliverMessage($chatId, $threadId, $text, $markup);
        $this->db->setDecision($chatId, $landed['thread_id'], $landed['message_id']);
        if ($short !== $summary) {
            $this->delivery->safeDeliver($chatId, $landed['thread_id'], $this->t('decision.full_list')."\n\n".$summary);
        }

        $notified = [];
        foreach ($this->db->queueWaiting() as $item) {
            $key = $item['chat_id'].':'.$item['thread_id'];
            if (isset($notified[$key]) || ($item['chat_id'] === $chatId && $item['thread_id'] === $landed['thread_id'])) {
                continue;
            }
            $notified[$key] = true;
            $this->delivery->safeDeliver($item['chat_id'], $item['thread_id'], $this->t('decision.queue_waits', ['topic' => $this->topicLabel($chatId, $landed['thread_id'])]));
        }
    }

    private function finishJobState(int $chatId, int $threadId): void
    {
        $this->db->setPid($chatId, $threadId, null);
        if (($gateRunId = $this->jobContext['pm_gate_run_id'] ?? null) !== null) {
            PmGateHook::cleanup($this->config->stateDir, $gateRunId);
        }
        $this->activeJob = null;
        $this->jobContext = null;
        $this->repoSnapshot = null;
    }

    private function commitMessage(string $prompt): string
    {
        $firstLine = strtok($prompt, "\n") ?: $prompt;
        $firstLine = trim(mb_substr($firstLine, 0, 60));

        return "tg: {$firstLine}";
    }

    private function commitUrl(string $hash): string
    {
        if ($this->config->githubRepo === '') {
            return $hash;
        }

        return "https://github.com/{$this->config->githubRepo}/commit/{$hash}";
    }

    private function helpText(): string
    {
        return $this->t('help');
    }
}
