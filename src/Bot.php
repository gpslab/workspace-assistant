<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Callback\EpicHandler;
use GpsLab\WorkspaceAssistant\Callback\Handler;
use GpsLab\WorkspaceAssistant\Callback\LangHandler;
use GpsLab\WorkspaceAssistant\Callback\ModeHandler;
use GpsLab\WorkspaceAssistant\Command\CancelCommand;
use GpsLab\WorkspaceAssistant\Command\CancelResult;
use GpsLab\WorkspaceAssistant\Command\CompactCommand;
use GpsLab\WorkspaceAssistant\Command\EpicCommand;
use GpsLab\WorkspaceAssistant\Command\HelpCommand;
use GpsLab\WorkspaceAssistant\Command\LangCommand;
use GpsLab\WorkspaceAssistant\Command\Locales;
use GpsLab\WorkspaceAssistant\Command\Menu;
use GpsLab\WorkspaceAssistant\Command\ModeCommand;
use GpsLab\WorkspaceAssistant\Command\NewCommand;
use GpsLab\WorkspaceAssistant\Command\QueueCommand;
use GpsLab\WorkspaceAssistant\Command\RestartCommand;
use GpsLab\WorkspaceAssistant\Command\Router;
use GpsLab\WorkspaceAssistant\Command\RunControl;
use GpsLab\WorkspaceAssistant\Command\StatsCommand;
use GpsLab\WorkspaceAssistant\Command\VerboseCommand;
use GpsLab\WorkspaceAssistant\Epic\Card;
use GpsLab\WorkspaceAssistant\Epic\ChatSurface;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\EpicNotifier;
use GpsLab\WorkspaceAssistant\Epic\GitHub;
use GpsLab\WorkspaceAssistant\Epic\Orchestrator;
use GpsLab\WorkspaceAssistant\Epic\PollSchedule;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\RunLifecycle;
use GpsLab\WorkspaceAssistant\Epic\RunPrompt;
use GpsLab\WorkspaceAssistant\Epic\Signal;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;
use GpsLab\WorkspaceAssistant\Queue\QueueScheduler;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;

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
 * bot — at most one task is active at a time (see RunState::$activeJob), which
 * serializes everything by construction.
 */
final class Bot implements RunControl, JobLauncher
{
    private const string EPIC_RUNNING = 'running';
    private const string EPIC_PAUSED = 'paused';

    private const int LIMIT_PAUSE_MAX_SEC = 7 * 86400;

    /** Pause on a Claude limit when the reset time could not be parsed from the error text. */
    private const int LIMIT_PAUSE_FALLBACK_SEC = 3600;

    /** Album debounce: how many seconds without new frames of the group to wait before starting the task. */
    private const float ALBUM_DEBOUNCE_SEC = 1.5;

    private int $offset = 0;
    private readonly Card $epicCard;
    private int $lastEpicPollAt = 0;
    private bool $running = true;

    /** Album frame buffer between maturations (see ALBUM_DEBOUNCE_SEC). */
    private readonly AlbumBuffer $albumBuffer;

    private readonly Locale $locale;
    private readonly ClaudeErrorText $claudeErrorText;
    private readonly SubRepoSync $subRepoSync;
    private readonly Delivery $delivery;
    private readonly Inbox $inbox;
    private readonly ChatSurface $epicChat;
    private readonly ModeAndLang $modeAndLang;
    private readonly Router $commandRouter;
    private readonly Menu $commandMenu;
    private readonly QueueTexts $queueTexts;
    private readonly DirtyTreeDecision $dirtyTree;
    private readonly RunState $runState;
    private readonly RunResult $runResult;
    private readonly ?Orchestrator $orchestrator;
    private readonly Locales $locales;
    private readonly QueueScheduler $queueScheduler;
    private readonly RunLifecycle $runLifecycle;
    private readonly CompactFlow $compactFlow;

    /**
     * Callback handlers in the order they are tried: the stateless `mode:`, `lang:`, `epic:` first,
     * the decision handler (it takes everything left) last.
     *
     * @var list<Handler>
     */
    private readonly array $callbackHandlers;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly GitManager $git,
        private readonly SessionFiles $sessionFiles,
        private readonly ?GitHub $github = null,
        ?Locale $locale = null,
        private readonly ?string $selfRepoPath = null,
    ) {
        $this->locale = $locale ?? Locale::fromConfig($config);
        $this->claudeErrorText = new ClaudeErrorText($this->locale);
        $this->locales = new Locales($this->locale, $db);
        $this->subRepoSync = new SubRepoSync($config, $this->locale);
        $this->runState = new RunState();
        $this->delivery = new Delivery($tg, $config, $this->locale);
        $this->runResult = new RunResult($config, $db, $git, $this->subRepoSync, $this->delivery, $this->locale, $this->runState);
        $this->inbox = new Inbox($tg, $config, $this->locale);
        $this->albumBuffer = new AlbumBuffer();
        $this->epicCard = new Card($db, $tg, $config->repoPath, $config->epicsDir, $config->epicTickMinutes * 60, $this->locale);
        $this->epicChat = new ChatSurface($db, $tg, $config, $git, $this->epicCard, $this->locale);
        $this->modeAndLang = new ModeAndLang(
            $db,
            $tg,
            $this->locale,
            $this,
            $this->locales,
        );
        $this->queueTexts = new QueueTexts($this->locale);
        $this->dirtyTree = new DirtyTreeDecision($config, $db, $tg, $git, $this->delivery, $this->locale, $this->queueTexts, $this->runResult);
        $this->callbackHandlers = [
            new ModeHandler($this->modeAndLang),
            new LangHandler($this->modeAndLang),
            new EpicHandler($this->epicChat),
            $this->dirtyTree,
        ];
        $notifier = new EpicNotifier($db, $this->delivery, $this->epicCard, $this->locale);
        $runPrompt = new RunPrompt($db, $config, $github);
        $this->queueScheduler = new QueueScheduler($config, $db, $tg, $git, $sessionFiles, $this->delivery, $this->locale, $this->locales, $this->queueTexts, $this->dirtyTree, $this->runState, $runPrompt, $this->inbox, $this->subRepoSync, $this);
        $this->commandRouter = $this->buildCommandRouter();
        $this->commandMenu = new Menu($this->commandRouter, $tg, $this->locale);
        $this->orchestrator = $github === null ? null : new Orchestrator(
            $db,
            $github,
            $this->queueScheduler->enqueueEpicRun(...),
            $notifier->blockEpic(...),
            $config->epicTickMinutes * 60,
            $config->epicStallTicks,
            $config->epicQuietMinutes * 60,
            $config->epicAnswerTimeoutMinutes * 60,
            notify: $notifier->notifyEpic(...),
            card: $this->epicCard,
            stateDir: $config->stateDir,
            masterRedHours: $config->epicMasterRedHours,
            owners: $config->githubOwners,
            locale: $this->locale,
        );
        $this->runLifecycle = new RunLifecycle($config, $db, $git, $this->delivery, $this->locale, $this->epicCard, $notifier, $this->runResult, $this->dirtyTree, $this->orchestrator);
        $this->compactFlow = new CompactFlow($config, $db, $tg, $git, $sessionFiles, $this->delivery, $this->locale, $this->dirtyTree, $this->runState, $this);
    }

    /** Menu order = registration order; commands not moved out of `Bot` yet go through temporary adapters. */
    private function buildCommandRouter(): Router
    {
        $router = new Router($this->tg, $this->locale);
        $router->register(new NewCommand($this->db, $this->queueScheduler, $this->locales));
        $router->register(new CompactCommand($this->queueScheduler, $this->locales));
        $router->register(new CancelCommand($this->db, $this->tg, $this->delivery, $this->locale, $this, $this->queueTexts, $this->dirtyTree));
        $router->register(new QueueCommand($this->tg, $this->locale, $this->queueScheduler, $this, $this->queueTexts));
        $router->register(new StatsCommand($this->db, $this->tg, $this->locale, $this->queueScheduler, $this));
        $router->register(new VerboseCommand($this->db, $this->tg, $this->locale));
        $router->register(new ModeCommand($this->modeAndLang));
        $router->register(new LangCommand($this->modeAndLang));
        $router->register(new EpicCommand($this->epicChat));
        $router->register(new RestartCommand($this->config, $this->tg, $this->locale, $this, $this->selfRepoPath ?? dirname(__DIR__)));
        $router->register(new HelpCommand($this->tg, $this->locale));

        return $router;
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
        $this->commandMenu->register();
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
        if ($this->runState->activeJob !== null && $this->runState->jobContext !== null) {
            // A job killed by the restart is removed from the queue and not
            // started again: a rerun would duplicate the prompt in the transcript.
            if ($this->runState->jobContext['queue_id'] > 0) {
                $this->db->queueRemove($this->runState->jobContext['queue_id']);
            }
            try {
                $this->tg->deleteMessage($this->runState->jobContext['chat_id'], $this->runState->jobContext['thinking_id']);
                $this->delivery->deliver(
                    $this->runState->jobContext['chat_id'],
                    $this->runState->jobContext['thread_id'],
                    $this->locales->localeFor($this->runState->jobContext['locale'])->t('restart.job_interrupted_now'),
                );
            } catch (\Throwable) {
                // best-effort on shutdown
            }
        }

        $this->runState->activeJob?->kill();
        Log::line('stopped.');
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

        if ($this->runState->activeJob !== null) {
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
        $busy = $this->runState->activeJob !== null || !$this->albumBuffer->isEmpty();
        $queueEmpty = !$busy && $this->db->queueHead() === null;
        $epicDue = !$busy && $this->orchestrator !== null && $this->running && $this->db->epicsRunningExist()
            ? $this->epicPollDueAt()
            : null;

        return PollSchedule::longPollTimeout(
            $busy,
            $queueEmpty,
            $busy || $queueEmpty ? 0 : $this->queueScheduler->queueBlockedUntil(),
            $this->queueScheduler->isTreePaused(),
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
        return TelegramMessage::threadIdOf($message);
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

    /**
     * @param list<array<string, mixed>> $parts
     */
    private function enqueueMessage(int $chatId, int $threadId, int $messageId, array $parts, int $userId = 0): void
    {
        $this->queueScheduler->enqueueMessage($chatId, $threadId, $messageId, $parts, $userId);
    }

    /**
     * @param list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}> $files
     * @param list<int>                                                                                    $messageIds
     */
    private function enqueueFiles(int $chatId, int $threadId, int $messageId, ?string $caption, array $files, array $messageIds = [], int $userId = 0): void
    {
        $this->queueScheduler->enqueueFiles($chatId, $threadId, $messageId, $caption, $files, $messageIds, $userId);
    }

    private function startQueueHead(): void
    {
        $this->queueScheduler->startQueueHead();
    }

    private function recoverQueueOnStart(): void
    {
        $this->queueScheduler->recoverQueueOnStart();
    }

    private function handleCommand(int $chatId, int $threadId, string $text, int $messageId = 0, int $userId = 0): void
    {
        $this->commandRouter->handle($chatId, $threadId, $text, $messageId, $userId);
    }

    public function cancelTopic(int $chatId, int $threadId): CancelResult
    {
        $killed = false;
        if ($this->runState->activeJob !== null && $this->runState->jobContext !== null
            && $this->runState->jobContext['chat_id'] === $chatId && $this->runState->jobContext['thread_id'] === $threadId) {
            $inboxFiles = $this->runState->jobContext['inbox_files'];
            $queueId = $this->runState->jobContext['queue_id'];

            $this->runState->activeJob->kill();
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

        // Files the cancelled run managed to put into the outbox must not
        // go out with the next run's reply (possibly to another topic). Another topic's
        // running job's outbox is not touched — those are its files.
        if (($killed || $removed !== []) && ($killed || $this->runState->activeJob === null)) {
            $this->delivery->clearOutbox();
        }

        return new CancelResult($killed, $removed, $pausedEpic, $limitReset, $this->runState->activeJob !== null);
    }

    /** Whether the topic has an active job. */
    public function topicHasActiveJob(int $chatId, int $threadId): bool
    {
        return $this->runState->hasActiveJob($chatId, $threadId);
    }

    public function currentRun(): ?array
    {
        if ($this->runState->activeJob === null || $this->runState->jobContext === null) {
            return null;
        }

        return ['chat_id' => $this->runState->jobContext['chat_id'], 'thread_id' => $this->runState->jobContext['thread_id']];
    }

    public function requestStop(): void
    {
        $this->running = false;
    }

    /**
     * Whether a run of this kind gets the built-in sub-agents: the two `/compact` phases do not.
     */
    public static function runsWithAgents(string $kind): bool
    {
        return !in_array($kind, [RunKind::KIND_COMPACT_SUMMARIZE, RunKind::KIND_COMPACT_RESEED], true);
    }

    /**
     * Starts claude. The mode comes from the queue item (fixed when it was
     * enqueued) and lives on in jobContext; it is never read from sessions.
     * Returns the start error text or null.
     *
     * @param array{run:string, pr:?string}|null $epicRun
     */
    public function launchJob(int $chatId, int $threadId, string $kind, string $prompt, ?string $sessionId, int $thinkingId, ?string $compactOldSessionId, string $mode, int $queueId, ?string $epicSlug = null, ?array $epicRun = null, ?string $idsPath = null, ?string $localeCode = null): ?string
    {
        // Compact summarize runs in arch regardless of the topic mode: the summary must
        // stay detailed, otherwise after compression in review/pm we would lose the key
        // decisions (see prompts/compact_summarize_prompt.md.dist). Reseed creates the
        // session the topic lives on, so it runs in the topic mode (passed by the caller).
        if ($kind === RunKind::KIND_COMPACT_SUMMARIZE) {
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
                $gateConfig = PmRunbook::config($this->config->absolutePromptsDir());
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
        $this->runState->runTreeSnapshot = null;
        $this->runState->runTreeSnapshotError = null;
        if (!$this->config->autoCommit) {
            try {
                $this->runState->runTreeSnapshot = $this->git->treeSnapshot();
            } catch (\RuntimeException $e) {
                // Unknown, not "none": what the run changes cannot be told afterwards
                // (git failing to run or to report its status alike).
                $this->runState->runTreeSnapshotError = $e->getMessage();
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
                updateOnResume: $kind === RunKind::KIND_CHAT,
                savedPromptHash: $kind === RunKind::KIND_CHAT && $sessionId !== null ? $this->savedPromptHash($chatId, $threadId) : null,
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
        $this->runState->activeJob = $runner;
        $this->runState->jobContext = [
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
        $this->runState->repoSnapshot = $this->subRepoSync->snapshotSubRepos();
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
     * phases session_id is managed specially (CompactFlow::finishReseed() after
     * resetSession), an early write would break the accounting.
     */
    private function persistSessionEarly(): void
    {
        if ($this->runState->activeJob === null || $this->runState->jobContext === null) {
            return;
        }
        if ($this->runState->jobContext['session_saved'] || $this->runState->jobContext['kind'] !== RunKind::KIND_CHAT) {
            return;
        }
        $sid = $this->runState->activeJob->sessionId();
        if ($sid === null) {
            return;
        }
        $this->db->setSessionId($this->runState->jobContext['chat_id'], $this->runState->jobContext['thread_id'], $sid);
        $this->runState->jobContext['session_saved'] = true;
    }

    private function pollJob(): void
    {
        if ($this->runState->activeJob === null || $this->runState->jobContext === null) {
            return;
        }

        $chatId = $this->runState->jobContext['chat_id'];
        $threadId = $this->runState->jobContext['thread_id'];

        try {
            $state = $this->runState->activeJob->poll();
        } catch (\Throwable $e) {
            // The process is in an inconsistent state: drop the job, otherwise the
            // following ticks would loop on poll() of a closed process.
            $queueId = $this->runState->jobContext['queue_id'];
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

        $thinkingId = $this->runState->jobContext['thinking_id'];
        $prompt = $this->runState->jobContext['prompt'];
        $kind = $this->runState->jobContext['kind'];
        $mode = $this->runState->jobContext['mode'];
        $localeCode = $this->runState->jobContext['locale'];
        $queueId = $this->runState->jobContext['queue_id'];
        $epicSlug = $this->runState->jobContext['epic_slug'];
        $epicRun = $this->runState->jobContext['epic_run'];
        // The skip reason is read before finishJobState() removes the gate files.
        $gateSkip = $this->runState->jobContext['pm_gate_run_id'] !== null
            ? PmGateHook::skipReason($this->config->stateDir, $this->runState->jobContext['pm_gate_run_id'])
            : '';
        $compactOld = $this->runState->jobContext['compact_old_session_id'] ?? null;
        $promptHash = $this->runState->jobContext['prompt_hash'];
        // Capture BEFORE finishJobState(), which resets jobContext.
        $inboxFiles = $this->runState->jobContext['inbox_files'];
        $requeued = false;

        try {
            if ($state === RunnerState::Timeout) {
                $reason = $this->runState->activeJob->timeoutReason();
                $timeoutWarnings = $this->runState->activeJob->promptWarnings();
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
            $claude = $this->runState->activeJob->result();
            $promptWarnings = $this->runState->activeJob->promptWarnings();
            if ($claude->exitedWithoutResult) {
                $diagnostics = $this->runState->activeJob->diagnosticsPath();
                Log::line('claude exited with code 0 without a result event'
                    .($claude->recovered ? ' (answer recovered from the last assistant message)' : '')
                    .($diagnostics !== null ? "; diagnostics: {$diagnostics}" : ''));
            }
            // A recovered answer is just the last assistant message and may be partial: never feed it
            // to compact (it would replace the session context); mark it in a chat reply.
            if ($claude->recovered && !$claude->isError) {
                if (in_array($kind, [RunKind::KIND_COMPACT_SUMMARIZE, RunKind::KIND_COMPACT_RESEED], true)) {
                    $claude = new ClaudeResult(true, $this->t('claude.recovered_unusable'), $claude->sessionId, $claude->inputTokens, $claude->outputTokens, true, true);
                } elseif ($kind === RunKind::KIND_CHAT) {
                    $claude = new ClaudeResult(false, $claude->text."\n\n".$this->t('claude.recovered_note'), $claude->sessionId, $claude->inputTokens, $claude->outputTokens, true, true);
                }
            }
            // sendResult() appends the warnings to a normal reply itself; every
            // other outcome (error, epic, compact) gets a separate message.
            $this->reportPromptWarnings($chatId, $threadId, $promptWarnings, $claude->isError || $kind !== RunKind::KIND_CHAT);
            // A chat reply carries the gate note itself (sendResult appends the warnings); every other
            // outcome gets a separate message after the job state is dropped.
            $gateInReply = $kind === RunKind::KIND_CHAT && !$claude->isError;
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

            // KIND_COMPACT_RESEED writes to the DB itself (CompactFlow::finishReseed()), after
            // resetSession; otherwise the accumulated counters of the old session
            // would be added on top of the new one. KIND_EPIC is isolated: its
            // session_id must not become the topic's session.
            if ($claude->sessionId !== null && !in_array($kind, [RunKind::KIND_COMPACT_RESEED, RunKind::KIND_EPIC], true)) {
                // The prompt hash moves only after a successful run (a failed one leaves the update block due) and
                // never after the summarize phase, which runs under another prompt.
                $this->db->recordRun($chatId, $threadId, $claude->sessionId, $claude->inputTokens, $claude->outputTokens, $claude->isError || $kind === RunKind::KIND_COMPACT_SUMMARIZE ? null : $promptHash);
            }

            // An epic run is judged by its signal file, not by the reply, so a clean exit
            // without a result event is not a failure there.
            if ($claude->isError && !($claude->exitedWithoutResult && $kind === RunKind::KIND_EPIC)) {
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
                RunKind::KIND_COMPACT_SUMMARIZE => $this->compactFlow->finishSummarize($chatId, $threadId, $claude, $compactOld, $queueId, $localeCode),
                RunKind::KIND_COMPACT_RESEED => $this->compactFlow->finishReseed($chatId, $threadId, $claude, $compactOld, $promptHash),
                RunKind::KIND_EPIC => $this->runLifecycle->finishRemembering($chatId, $threadId, (string) $epicSlug, $epicRun),
                default => $this->runResult->sendResult($chatId, $threadId, $claude, $prompt, $mode, $promptWarnings),
            };
        } finally {
            // The item is removed when the run finishes. Exceptions: a return to the
            // head because of the limit and the compact summarize -> reseed
            // transition (the job is alive).
            if (!$requeued && $this->runState->activeJob === null && $queueId > 0) {
                $this->db->queueRemove($queueId);
            }
            // The run ended with a clean tree (including via auto-commit), so there is
            // no dirt owner; otherwise external dirt would later be attributed to this
            // topic.
            if ($this->runState->activeJob === null) {
                try {
                    if (!$this->git->hasChanges()) {
                        $this->db->clearDirtyOwner();
                    }
                } catch (\RuntimeException $e) {
                    // Unknown state: the owner stays, and this must not replace an exception in flight.
                    Log::line('git status failed after the run, dirty owner kept: '.$e->getMessage());
                }
                $this->runState->runTreeSnapshot = null;
                $this->runState->runTreeSnapshotError = null;
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
        $this->delivery->deliver($chatId, $threadId, $kind === RunKind::KIND_EPIC
            ? $this->t('epicrun.pm_gate_skipped', ['slug' => (string) $epicSlug, 'run' => (string) ($epicRun['run'] ?? ''), 'reason' => $reason])
            : $this->t('result.pm_gate_skipped', ['reason' => $reason]));
    }

    /**
     * With AUTO_COMMIT=1 a failed regular request gets the commit/discard buttons and a queue
     * pause (with 0 it gets neither); an epic run failure is handled without a human, see
     * {@see RunLifecycle::failRemembering()}.
     */
    private function afterFailedRun(int $chatId, int $threadId, ?string $epicSlug, string $reason): void
    {
        if ($epicSlug === null) {
            $this->dirtyTree->offerDecisionIfDirty($chatId, $threadId);

            return;
        }
        $this->runLifecycle->failRemembering($chatId, $threadId, $epicSlug, $reason);
    }

    /**
     * Live update of the "thinking" message with the list of Claude's tool calls.
     * Throttled to >=3 s (Telegram limit is ~1 edit/s) and only on new lines.
     * editMessageText addresses by message_id, so the topic (thread_id) is not needed.
     */
    private function maybeUpdateProgress(): void
    {
        if ($this->runState->activeJob === null || $this->runState->jobContext === null) {
            return;
        }

        $lines = $this->runState->activeJob->getProgressLines();
        if (count($lines) <= $this->runState->jobContext['shown_count']) {
            return; // no new calls
        }
        if (time() - $this->runState->jobContext['last_progress_at'] < 3) {
            return; // throttle
        }

        $tail = array_slice($lines, -15);
        $this->tg->editMessageText(
            $this->runState->jobContext['chat_id'],
            $this->runState->jobContext['thinking_id'],
            $this->queueTexts->thinkingText($this->runState->jobContext['mode'])."\n\n".implode("\n", $tail),
        );

        $this->runState->jobContext['last_progress_at'] = time();
        $this->runState->jobContext['shown_count'] = count($lines);
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

        // The order of $callbackHandlers is a safety rule (.claude-docs/gotchas.md): the stateless
        // handlers go before the decision handler, which is the last one and takes everything left.
        foreach ($this->callbackHandlers as $handler) {
            if ($handler->supports($data)) {
                $handler->handle($callbackId, $userId, $chatId, is_array($message) ? $message : [], $data);

                return;
            }
        }
    }

    private function finishJobState(int $chatId, int $threadId): void
    {
        $this->db->setPid($chatId, $threadId, null);
        if (($gateRunId = $this->runState->jobContext['pm_gate_run_id'] ?? null) !== null) {
            PmGateHook::cleanup($this->config->stateDir, $gateRunId);
        }
        $this->runState->activeJob = null;
        $this->runState->jobContext = null;
        $this->runState->repoSnapshot = null;
    }
}
