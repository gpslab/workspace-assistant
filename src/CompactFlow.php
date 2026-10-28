<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;

/**
 * The two phases of /compact after their runs finish: the summary of the old session, then the reseed.
 * Phase 2 is started through {@see JobLauncher}, which writes {@see RunState} synchronously, so once the
 * outcome of phase 1 is routed a run is active and the /compact queue item stays in the queue.
 */
final class CompactFlow
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly GitManager $git,
        private readonly SessionFiles $sessionFiles,
        private readonly Delivery $delivery,
        private readonly Locale $locale,
        private readonly DirtyTreeDecision $dirtyTree,
        private readonly RunState $runState,
        private readonly JobLauncher $launcher,
    ) {
    }

    /**
     * Paths the summarize run changed: the whole dirty tree with AUTO_COMMIT=1, the per-run diff with 0.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when the tree state is unknown (no snapshot, git status failed or git could not start)
     */
    private function changedPaths(): array
    {
        if ($this->config->autoCommit) {
            return $this->git->hasChanges() ? ['*'] : [];
        }
        if ($this->runState->runTreeSnapshotError !== null) {
            throw new GitStatusException($this->runState->runTreeSnapshotError);
        }
        if ($this->runState->runTreeSnapshot === null) {
            return [];
        }

        return $this->git->changedSince($this->runState->runTreeSnapshot);
    }

    /**
     * Phase 1 of /compact finished: we got the summary of the old session. If
     * Claude disobeyed and changed something in the repository, we go no
     * further, hand the decision to the user (commit/discard, with AUTO_COMMIT=1)
     * and leave the session untouched.
     */
    public function finishSummarize(int $chatId, int $threadId, ClaudeResult $claude, ?string $compactOldSessionId, int $queueId, string $localeCode): void
    {
        $summary = trim($claude->text);
        if ($summary === '') {
            $this->delivery->deliver($chatId, $threadId, $this->t('compact.no_summary'));

            return;
        }

        try {
            $changed = $this->changedPaths();
            $changes = $changed === [] ? '' : ($this->config->autoCommit ? $this->git->changesSummary() : implode("\n", $changed));
        } catch (\RuntimeException $e) {
            // Unknown tree state (git status failed, or git could not start): no guard verdict, so no phase 2 and no buttons.
            Log::line('compact: git status failed, not reseeding: '.$e->getMessage());
            $this->delivery->deliver($chatId, $threadId, $this->t('compact.status_failed', ['error' => $e->getMessage()]));

            return;
        }
        if ($changed !== []) {
            $this->delivery->deliver($chatId, $threadId, $this->t('compact.dirty', ['changes' => $changes]));
            $this->dirtyTree->offerDecisionIfDirty($chatId, $threadId);

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
        $error = $this->launcher->launchJob($chatId, $threadId, RunKind::KIND_COMPACT_RESEED, $prompt, null, $thinkingId, $compactOldSessionId, $this->db->getMode($chatId, $threadId), $queueId, localeCode: $localeCode);
        if ($error !== null) {
            $this->tg->deleteMessage($chatId, $thinkingId);
            $this->delivery->deliver($chatId, $threadId, $this->t('queue.claude_start_failed', ['error' => $error]));
        }
    }

    /**
     * Phase 2 of /compact finished: the new session is created. Only now do we
     * reset the old counters and record the new ones; had we reset them earlier
     * (before making sure the new session really started), a start failure
     * (see {@see JobLauncher::launchJob()}) would leave us with neither the old context nor the new one.
     */
    public function finishReseed(int $chatId, int $threadId, ClaudeResult $claude, ?string $compactOldSessionId, ?string $promptHash): void
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
        $this->dirtyTree->offerDecisionIfDirty($chatId, $threadId);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->locale->t($key, $params);
    }
}
