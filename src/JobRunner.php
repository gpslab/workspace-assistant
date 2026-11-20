<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Command\CancelResult;
use GpsLab\WorkspaceAssistant\Command\RunControl;
use GpsLab\WorkspaceAssistant\Epic\Card;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;

/**
 * The Claude process lifecycle: launch, polling, the end of a run and the cancel of a topic's work.
 *
 * Owns {@see RunState}. {@see poll()} does not call the finishers of chat, epic or `/compact` runs: it
 * returns a {@see RunOutcome} and `Bot` routes it.
 */
final class JobRunner implements JobLauncher, RunControl
{
    private const string EPIC_RUNNING = 'running';
    private const string EPIC_PAUSED = 'paused';

    private const int LIMIT_PAUSE_MAX_SEC = 7 * 86400;

    /** Pause on a Claude limit when the reset time could not be parsed from the error text. */
    private const int LIMIT_PAUSE_FALLBACK_SEC = 3600;

    private readonly ClaudeErrorText $claudeErrorText;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly GitManager $git,
        private readonly Delivery $delivery,
        private readonly Locale $locale,
        private readonly Inbox $inbox,
        private readonly SubRepoSync $subRepoSync,
        private readonly QueueTexts $queueTexts,
        private readonly Card $epicCard,
        private readonly RunState $runState,
        private readonly RunnerFactory $runnerFactory,
    ) {
        $this->claudeErrorText = new ClaudeErrorText($locale);
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

    public function cancelTopic(int $chatId, int $threadId): CancelResult
    {
        $killed = false;
        if ($this->runState->activeJob !== null && $this->runState->jobContext !== null
            && $this->runState->jobContext['chat_id'] === $chatId && $this->runState->jobContext['thread_id'] === $threadId) {
            $inboxFiles = $this->runState->jobContext['inbox_files'];
            $queueId = $this->runState->jobContext['queue_id'];

            $this->runState->activeJob->kill();
            $this->finishJobState($chatId, $threadId);
            $this->runState->repoSnapshot = null;
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

    /**
     * Starts claude. The mode comes from the queue item (fixed when it was
     * enqueued) and lives on in jobContext; it is never read from sessions.
     * Returns the start error text or null.
     *
     * @param array{run:string, pr:?string}|null $epicRun
     * @param array<string, string>              $reviewShas reviewed commit per PR (`owner/repo#N` => SHA) for {@see ReviewCommitHook}
     */
    public function launchJob(int $chatId, int $threadId, string $kind, string $prompt, ?string $sessionId, int $thinkingId, ?string $compactOldSessionId, string $mode, int $queueId, ?string $epicSlug = null, ?array $epicRun = null, ?string $idsPath = null, ?string $localeCode = null, array $reviewShas = [], ?string $model = null, bool $resumeSkipped = false, bool $clearOutbox = true): ?string
    {
        $model = (new ModelChoice($this->config, $this->db))->checkPinned($model);
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
        // to another topic. The repeat of a review turn (see repeatReviewRun())
        // skips this: the files the repeated turn produced belong to its reply, and
        // the run that delivers that reply is the repeat's.
        if ($clearOutbox) {
            $this->delivery->clearOutbox();
        }

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
        $settingsJson = $mode === SystemPrompt::MODE_REVIEW ? ReviewStatusHook::settingsJson($this->config->stateDir, $this->config->forge) : '';
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
            // The gate contract of the run, unchanged for the engine it is not used by: Claude Code gets
            // these as arguments of the hook command in its settings, OpenCode as the environment of the
            // gate shim, which starts the same PHP hooks with the same arguments.
            $env[PmGateHook::STATE_ENV] = $this->config->stateDir;
            $env[PmGateHook::KIND_ENV] = $gateKind;
            $env[PmGateHook::RUN_ENV] = $gateRunId;
            $env[PmGateHook::PHP_ENV] = PHP_BINARY;
            $settingsJson = PmGateHook::settingsJson($this->config->stateDir, $gateKind, $gateRunId, $mode === SystemPrompt::MODE_REVIEW, $this->config->forge);
            // A formal review goes onto the reviewed commit: review mode and the epic review run.
            if ($mode === SystemPrompt::MODE_REVIEW || ($epicRun !== null && $epicRun['run'] === Run::REVIEW)) {
                try {
                    if ($reviewShas !== []) {
                        $env[ReviewCommitHook::SHAS_ENV] = ReviewCommitHook::writeShas($this->config->stateDir, $gateRunId, $reviewShas);
                    }
                } catch (\RuntimeException $e) {
                    PmGateHook::cleanup($this->config->stateDir, $gateRunId);

                    return $e->getMessage();
                }
                $env[ReviewCommitHook::ENABLED_ENV] = '1';
                $settingsJson = ReviewCommitHook::addTo($settingsJson, $this->config->stateDir);
            }
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
            $runner = $this->runnerFactory->create(
                $mode,
                $settingsJson,
                $env,
                $localeCode,
                RunKind::runsWithAgents($kind),
                $kind === RunKind::KIND_CHAT,
                $kind === RunKind::KIND_CHAT && $sessionId !== null ? $this->savedPromptHash($chatId, $threadId) : null,
                $model,
            );
            $runner->start($prompt, $sessionId);
        } catch (\RuntimeException $e) {
            if ($gateRunId !== null) {
                PmGateHook::cleanup($this->config->stateDir, $gateRunId);
                ReviewCommitHook::cleanup($this->config->stateDir, $gateRunId);
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
            'model' => $model,
            'epic_slug' => $epicSlug,
            'epic_run' => $epicRun,
            'pm_gate_run_id' => $gateRunId,
            'prompt_hash' => $runner->promptHash(),
            'resume_skipped' => $resumeSkipped,
            // The finding ids of a manual review (see repeatReviewRun), and whether this run is the
            // repeat of a review turn whose status was missing after the run before it.
            'ids_path' => $idsPath,
            'review_repeated' => false,
        ];

        // The topic of the last job that touched the tree owns the commit/discard decision.
        if ($this->config->autoCommit) {
            $this->db->setDirtyOwner($chatId, $threadId);
        }
        $this->runState->repoSnapshot = $this->subRepoSync->snapshotSubRepos();
        $this->db->setPid($chatId, $threadId, $runner->pid());

        return null;
    }

    /**
     * One step of the job in progress: null while it runs (or when there is none), otherwise how it ended.
     * The state is already dropped by then, and the outbox, the prompt-file warnings and the messages that
     * do not depend on the kind of run are delivered; what is left is for the caller to route.
     */
    public function poll(): ?RunOutcome
    {
        if ($this->runState->activeJob === null || $this->runState->jobContext === null) {
            return null;
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

            return null;
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
        $resumeSkipped = $this->runState->jobContext['resume_skipped'] ?? false;
        $actualModel = $this->runState->activeJob->actualModel();
        // The fingerprint uses the model of THIS run; jobContext is reset by finishJobState() below.
        $engineFingerprint = $this->config->engineFingerprint($this->runState->jobContext['model'] ?? null);
        // Capture BEFORE finishJobState(), which resets jobContext.
        $inboxFiles = $this->runState->jobContext['inbox_files'];

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

            return new RunOutcome(
                $kind, $chatId, $threadId, $queueId,
                null, false, 'process killed: '.($reason !== '' ? $reason : 'timeout'),
                null,
                $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $timeoutWarnings,
            );
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
                $claude = new RunAnswer(true, $this->t('claude.recovered_unusable'), $claude->sessionId, $claude->totals, true, true);
            } elseif ($kind === RunKind::KIND_CHAT) {
                $claude = new RunAnswer(false, $claude->text."\n\n".$this->t('claude.recovered_note'), $claude->sessionId, $claude->totals, true, true);
            }
        }
        // Post-run review status check (see repeatReviewRun()): only an engine with no Stop hooks needs
        // it, and when it asks for a repeat this run is not over — the caller must not finish the job
        // state, deliver the reply or touch the thinking message.
        if ($this->repeatReviewRun($claude, $kind, $mode, $inboxFiles)) {
            // The warnings of this run are not tied to a reply that only the repeat will produce:
            // they go out as their own messages now, so the repeat does not swallow them.
            $this->reportPromptWarnings($chatId, $threadId, $promptWarnings, true);

            return null;
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
        // An epic run uses the topic's row but never touches its session state.
        if ($actualModel !== null && $kind !== RunKind::KIND_EPIC) {
            $this->db->setLastModel($chatId, $threadId, $actualModel);
        }
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
        $runTotals = null;
        if ($claude->sessionId !== null && !in_array($kind, [RunKind::KIND_COMPACT_RESEED, RunKind::KIND_EPIC], true)) {
            // The prompt hash moves only after a successful run (a failed one leaves the update block due) and
            // never after the summarize phase, which runs under another prompt.
            $runTotals = $this->db->recordRun($chatId, $threadId, $claude->sessionId, $claude->totals, $claude->isError || $kind === RunKind::KIND_COMPACT_SUMMARIZE ? null : $promptHash, $engineFingerprint);
        }
        // From here on the totals are the figure of this run (the verbose footer), not the snapshot.
        $claude = $claude->withTotals($runTotals);
        // A new session started because the stored engine fingerprint did not match: say so in the reply.
        // An error reply is built by ClaudeErrorText (limit/session-expired map to fixed texts and would
        // drop an appended line), so there the notice goes out as its own message.
        if ($resumeSkipped && $kind === RunKind::KIND_CHAT) {
            if ($claude->isError) {
                $this->delivery->deliver($chatId, $threadId, $this->t('session.engine_changed'));
            } else {
                $claude = $claude->withText($claude->text === '' ? $this->t('session.engine_changed') : $claude->text."\n\n".$this->t('session.engine_changed'));
            }
        }

        // An epic run is judged by its signal file, not by the reply, so a clean exit
        // without a result event is not a failure there.
        if ($claude->isError && !($claude->exitedWithoutResult && $kind === RunKind::KIND_EPIC)) {
            // The engine's own parser may already classify the limit (OpenCode does); otherwise a
            // foreign provider has its own text semantics: `429` / "rate limit" pauses the queue
            // (like an unparseable Claude reset), a quota/balance error does not (waiting does not
            // refill a balance). On the Anthropic subscription the Claude handling below is unchanged.
            $limitClass = $claude->limitClass
                ?? ($this->config->usesForeignProvider() ? $this->claudeErrorText->foreignLimit($claude->text) : null);
            if ($limitClass === 'rate' && $queueId > 0) {
                $until = min(time() + self::LIMIT_PAUSE_FALLBACK_SEC, time() + self::LIMIT_PAUSE_MAX_SEC);
                $this->db->setLimitPauseUntil($until);
                $this->db->queueRelease($queueId);

                return new RunOutcome(
                    $kind, $chatId, $threadId, $queueId,
                    $claude, true, 'Provider rate limit: '.$claude->text,
                    $this->claudeErrorText->format($claude->text)."\n".$this->t('run.queue_paused_until', ['until' => date('H:i d.m', $until)]),
                    $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $promptWarnings,
                );
            }
            if ($limitClass === 'quota') {
                $this->delivery->deliver($chatId, $threadId, $this->t('error.provider_quota', ['text' => $claude->text]));

                return new RunOutcome(
                    $kind, $chatId, $threadId, $queueId,
                    $claude, false, 'Provider quota: '.$claude->text,
                    null,
                    $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $promptWarnings,
                );
            }

            $limit = $this->claudeErrorText->parseLimit($claude->text);
            if ($limit !== null && $queueId > 0) {
                // A Claude limit pauses the WHOLE queue until the reset time; the item
                // returns as the head (its id does not change) and the following ones
                // do not start, so there is no cascade of identical errors.
                $until = min($limit['reset_at'] ?? time() + self::LIMIT_PAUSE_FALLBACK_SEC, time() + self::LIMIT_PAUSE_MAX_SEC);
                $this->db->setLimitPauseUntil($until);
                $this->db->queueRelease($queueId);

                return new RunOutcome(
                    $kind, $chatId, $threadId, $queueId,
                    $claude, true, 'Claude limit: '.$limit['message'],
                    $limit['message']."\n".$this->t('run.queue_paused_until', ['until' => date('H:i d.m', $until)]),
                    $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $promptWarnings,
                );
            }

            $this->delivery->deliver($chatId, $threadId, $this->claudeErrorText->format($claude->text));

            return new RunOutcome(
                $kind, $chatId, $threadId, $queueId,
                $claude, false, 'Engine error: '.$claude->text,
                null,
                $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $promptWarnings,
            );
        }

        return new RunOutcome(
            $kind, $chatId, $threadId, $queueId,
            $claude, false, null,
            null,
            $compactOld, $promptHash, $localeCode, $epicSlug, $epicRun, $prompt, $mode, $promptWarnings,
        );
    }

    /**
     * The post-run review status check of an engine that has no Stop hooks to attach it to (OpenCode):
     * the same {@see ReviewStatusHook} decision, taken by the Bot after the run, on the neutral record
     * of the finished turn ({@see TurnRecord}). A Claude Code review run needs none of this — its status
     * is enforced by the Stop hook while the turn runs. The epic hot review
     * ({@see RunKind::KIND_EPIC} + {@see SystemPrompt::MODE_REVIEW}) is checked the same way: its
     * finding ids are null ({@see ReviewStatusHook} — the epic run's coverage is the signal, not the
     * review body), and the repeat is a full epic run of the same slug and run kind.
     *
     * false — the run is over as usual: it is not the review run this check is about, the turn passed,
     * or its missing status was recorded by the marker of {@see ReviewStatusHook::markMissing()} (the
     * outcome a repeated stop has on Claude Code) and the reply will carry the warning.
     *
     * true — one more run was launched into the same session with the requirement as its prompt, and
     * the end of THIS run now belongs to that one: the caller returns without finishing the job state.
     * The repeat carries the flag of a repeated turn, so its own missing status is recorded instead of
     * starting a third run: exactly one repeat per review run.
     *
     * @param list<string> $inboxFiles inbox files of the finished run, to be restored onto the repeat
     */
    private function repeatReviewRun(RunAnswer $claude, string $kind, string $mode, array $inboxFiles): bool
    {
        $context = $this->runState->jobContext;
        $turn = $this->runState->activeJob?->turnRecord();
        // No record — no check: the engine reports nothing of what the check reads (Claude Code), or
        // the run produced no turn at all. An error reply is never repeated either: there is nothing
        // to re-ask a session that did not answer.
        if ($context === null || $turn === null
            || !$this->config->isOpencode()
            || ($kind !== RunKind::KIND_CHAT && $kind !== RunKind::KIND_EPIC)
            || $mode !== SystemPrompt::MODE_REVIEW
            || $claude->isError
            || $claude->sessionId === null) {
            return false;
        }

        $ids = $this->reviewFindingIds();
        // Whether this is the run after the repeat: on Claude Code the same question is what
        // `stop_hook_active` of the second stop answers.
        $decision = ReviewStatusHook::decideTurn($turn, $context['review_repeated'] ?? false, $ids);
        if ($decision === ReviewStatusHook::PASS) {
            return false;
        }
        if ($decision === ReviewStatusHook::MISSING) {
            // This turn IS the repeat and left the status missing too: release the run with the marker
            // and let the reply carry the warning — a repeated turn is never asked a third time.
            ReviewStatusHook::markMissing($this->config->stateDir, $claude->sessionId, $turn, $ids);
            Log::line('review status check: the repeated turn released without a matching review status, session '.$claude->sessionId);

            return false;
        }

        $reason = ReviewStatusHook::reasonForTurnOrFallback(
            $turn,
            $ids,
            static fn (\Throwable $e) => Log::line('review status check: reason text not loaded: '.$e->getMessage()),
            $this->config->forge,
        );
        $previous = $this->runState->activeJob;
        Log::line('review status check: repeating the turn in session '.$claude->sessionId);
        try {
            // The findings of this review are passed on: the repeat answers for the same list. The
            // outbox files of the finished run are kept (clearOutbox=false) and go out with the
            // repeat's reply when it is delivered. An epic review repeats as an epic run: the same
            // kind, slug and run, so the repeat is a full epic run judged by its signal again.
            $isEpic = $kind === RunKind::KIND_EPIC;
            $error = $this->launchJob(
                $context['chat_id'],
                $context['thread_id'],
                $kind,
                $reason,
                $claude->sessionId,
                $context['thinking_id'],
                null,
                SystemPrompt::MODE_REVIEW,
                $context['queue_id'],
                $isEpic ? $context['epic_slug'] : null,
                $isEpic ? $context['epic_run'] : null,
                idsPath: $context['ids_path'] ?? null,
                localeCode: $context['locale'],
                model: $context['model'],
                clearOutbox: false,
            );
        } catch (\Throwable $e) {
            // The process of the repeat may already be alive and tracked — then that run goes on, the
            // same way a queue item does when the launch throws after the start (see QueueScheduler).
            if ($this->runState->activeJob !== $previous) {
                $this->handOverToRepeat($inboxFiles, $context['prompt'], $context['pm_gate_run_id']);

                throw $e;
            }

            ReviewStatusHook::markMissing($this->config->stateDir, $claude->sessionId, $turn, $ids);
            Log::line('review status check: the repeat did not start: '.$e->getMessage());

            return false;
        }

        if ($error !== null) {
            ReviewStatusHook::markMissing($this->config->stateDir, $claude->sessionId, $turn, $ids);
            Log::line('review status check: the repeat did not start: '.$error);

            return false;
        }

        $this->handOverToRepeat($inboxFiles, $context['prompt'], $context['pm_gate_run_id']);

        return true;
    }

    /**
     * The repeat runs the same job item as the run it repeats: it takes over the inbox files of that
     * run (launchJob() starts a context without them), keeps the original prompt (the auto-commit
     * message and the run outcome are built from it, not from the reason text) and is marked as the
     * repeated turn, so it is released with a marker instead of starting a further run. The finished
     * run's gate files go here: launchJob() already replaced jobContext, so finishJobState() will run
     * only for the repeat and cannot reach the first run's id.
     *
     * @param list<string> $inboxFiles
     */
    private function handOverToRepeat(array $inboxFiles, string $prompt, ?string $previousGateRunId): void
    {
        if ($this->runState->jobContext === null) {
            return;
        }
        if ($previousGateRunId !== null) {
            PmGateHook::cleanup($this->config->stateDir, $previousGateRunId);
            ReviewCommitHook::cleanup($this->config->stateDir, $previousGateRunId);
        }
        $this->runState->jobContext['inbox_files'] = $inboxFiles;
        $this->runState->jobContext['prompt'] = $prompt;
        $this->runState->jobContext['review_repeated'] = true;
    }

    /**
     * The finding ids of this run's review ({@see ColdFindings}), as the Stop hook of a Claude Code
     * review reads them from the file launchJob() points it at: null when the run had none to cover.
     *
     * @return list<string>|null
     */
    private function reviewFindingIds(): ?array
    {
        $path = $this->runState->jobContext['ids_path'] ?? null;
        if ($path === null || !is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
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
        $this->db->setSessionId($this->runState->jobContext['chat_id'], $this->runState->jobContext['thread_id'], $sid, $this->config->engineFingerprint($this->runState->jobContext['model'] ?? null));
        $this->runState->jobContext['session_saved'] = true;
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

    private function finishJobState(int $chatId, int $threadId): void
    {
        $this->db->setPid($chatId, $threadId, null);
        if (($gateRunId = $this->runState->jobContext['pm_gate_run_id'] ?? null) !== null) {
            PmGateHook::cleanup($this->config->stateDir, $gateRunId);
            ReviewCommitHook::cleanup($this->config->stateDir, $gateRunId);
        }
        $this->runState->activeJob = null;
        $this->runState->jobContext = null;
    }
}
