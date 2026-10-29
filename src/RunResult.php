<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Callback\DecisionHost;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;

/**
 * The reply to a finished run: the result message, the auto-commit with its link, the sub-repository
 * links and the changed-prompt-files warning. Reads the run's tree snapshots from {@see RunState}.
 */
final class RunResult implements DecisionHost
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly GitManager $git,
        private readonly SubRepoSync $subRepoSync,
        private readonly Delivery $delivery,
        private readonly Locale $locale,
        private readonly RunState $runState,
    ) {
    }

    /**
     * @param list<string> $promptWarnings
     */
    public function sendResult(int $chatId, int $threadId, ClaudeResult $claude, string $prompt, string $mode, array $promptWarnings = []): void
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

        $text .= $this->promptFilesNote();

        $commitLinks = [];

        if (!$this->config->autoCommit) {
            $changed = [];
            try {
                if ($this->runState->runTreeSnapshotError !== null) {
                    throw new GitStatusException($this->runState->runTreeSnapshotError);
                }
                $changed = $this->runState->runTreeSnapshot !== null ? $this->git->changedSince($this->runState->runTreeSnapshot) : [];
            } catch (\RuntimeException $e) {
                Log::line('tree snapshot diff failed: '.$e->getMessage());
                $text .= "\n\n".$this->t('result.changes_unknown', ['error' => $e->getMessage()]);
            }
            if ($changed !== []) {
                $text .= "\n\n".$this->t('result.changed_files', ['count' => count($changed)]);
            }
        } else {
            try {
                $dirty = $this->git->hasChanges();
            } catch (GitStatusException $e) {
                Log::line('git status failed, nothing committed: '.$e->getMessage());
                $text .= "\n\n".$this->t('result.status_failed', ['error' => $e->getMessage()]);
                $dirty = false;
            }
            if ($dirty) {
                try {
                    $hash = $this->git->commitAndPush($this->commitMessage($prompt));
                    $commitLinks[] = $this->commitUrl($hash);
                } catch (\RuntimeException $e) {
                    $text .= "\n\n".$this->t('result.git_failed', ['error' => $e->getMessage()]);
                }
            }
        }

        $commitLinks = array_merge($commitLinks, $this->subRepoSync->collectSubRepoLinks($this->runState->repoSnapshot ?? []));
        $this->runState->repoSnapshot = null;

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
     * @param string|null $statusError set to the first `git status` failure; the files of that directory are unknown
     *
     * @return list<string>
     */
    private function changedPromptFiles(?string &$statusError = null): array
    {
        $files = [];
        foreach ([$this->config->promptsDir, $this->config->localesDir] as $dir) {
            if ($dir === '' || str_contains($dir, '..')) {
                continue;
            }

            try {
                if (!$this->config->autoCommit) {
                    if ($this->runState->runTreeSnapshotError !== null) {
                        throw new GitStatusException($this->runState->runTreeSnapshotError);
                    }
                    $files = array_merge($files, $this->runState->runTreeSnapshot !== null ? $this->git->changedSince($this->runState->runTreeSnapshot, $dir) : []);

                    continue;
                }
                $files = array_merge($files, $this->git->changedPathsIn($dir));
            } catch (\RuntimeException $e) {
                $statusError ??= $e->getMessage();
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Separate chat message for commit paths that do not go through sendResult()
     * (autonomous epic runs, the manual "commit" button).
     */
    public function deliverPromptFilesWarning(int $chatId, int $threadId): void
    {
        $warning = trim($this->promptFilesNote());
        if ($warning !== '') {
            Log::line('prompt: '.$warning);
            $this->delivery->deliver($chatId, $threadId, $warning);
        }
    }

    /** The changed-prompt-files warning, or a warning that they could not be checked. */
    private function promptFilesNote(): string
    {
        $statusError = null;
        $note = $this->promptFilesWarning($this->changedPromptFiles($statusError));
        if ($statusError !== null) {
            $note .= "\n\n⚠️ ".$this->t('prompt_files.check_failed', ['error' => $statusError]);
        }

        return $note;
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

    public function commitMessage(string $prompt): string
    {
        $firstLine = strtok($prompt, "\n") ?: $prompt;
        $firstLine = trim(mb_substr($firstLine, 0, 60));

        return "tg: {$firstLine}";
    }

    public function commitUrl(string $hash): string
    {
        if ($this->config->forgeRepo === '') {
            return $hash;
        }

        $host = $this->config->forgeHost;

        return GitManager::buildCommitUrl("https://{$host}/{$this->config->forgeRepo}", $hash, $this->config->forge, $host);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->locale->t($key, $params);
    }
}
