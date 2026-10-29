<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\RunResult;

/**
 * What happens when an epic run finishes or fails: the signal is applied, leftovers are committed,
 * a failure is logged and the tree is rolled back.
 */
final class RunLifecycle
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly GitManager $git,
        private readonly Delivery $delivery,
        private readonly Locale $locale,
        private readonly Card $card,
        private readonly EpicNotifier $notifier,
        private readonly RunResult $runResult,
        private readonly DirtyTreeDecision $dirtyTree,
        private readonly ?Orchestrator $orchestrator,
    ) {
    }

    /**
     * Epic run outcome + GitHub fingerprint AFTER it (the run's own edits do not wake the tick).
     *
     * @param array{run:string, pr:?string}|null $epicRun
     */
    public function finishRemembering(int $chatId, int $threadId, string $slug, ?array $epicRun): void
    {
        try {
            $this->finish($chatId, $threadId, $slug, $epicRun);
        } finally {
            $this->orchestrator?->rememberAfterRun($slug);
        }
    }

    /** Failed epic run, handled without a human: see {@see fail()}; then the fingerprint is remembered. */
    public function failRemembering(int $chatId, int $threadId, string $slug, string $reason): void
    {
        $this->fail($chatId, $threadId, $slug, $reason);
        $this->orchestrator?->rememberAfterRun($slug);
    }

    /**
     * Epic run finished: the reply text does not go to the topic, the bot reads
     * only the signal. An invalid or missing signal is a run failure (a message
     * to the topic, nothing from the signal is applied).
     *
     * @param array{run:string, pr:?string}|null $epicRun
     */
    private function finish(int $chatId, int $threadId, string $slug, ?array $epicRun = null): void
    {
        try {
            $path = Run::signalPath($this->config->stateDir, $slug);
            $raw = is_file($path) ? file_get_contents($path) : false;
            if ($raw === false) {
                throw new \InvalidArgumentException('signal file not created');
            }
            $signal = Signal::parse($raw, $this->config->forgeOwners);

            $known = array_column($this->db->epicTasks($slug), 'task');
            $known = [...$known, ...array_column($signal->added, 'task')];
            $refs = [...($signal->hold ?? [])];
            foreach ($signal->added as $added) {
                $refs = [...$refs, ...$added['after']];
            }
            foreach ($refs as $ref) {
                if (!in_array($ref, $known, true)) {
                    throw new \InvalidArgumentException($this->locale->t('epicrun.task_not_in_epic', ['ref' => $ref]));
                }
            }
        } catch (\InvalidArgumentException $e) {
            Log::line("epic {$slug}: run failed: ".$e->getMessage());
            $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.signal_invalid', ['slug' => $slug, 'error' => $e->getMessage()]));
            $this->fail($chatId, $threadId, $slug, 'invalid signal: '.$e->getMessage());

            return;
        }

        if ($epicRun !== null && $epicRun['run'] === Run::REVIEW && $epicRun['pr'] !== null
            && !$this->checkColdCoverage($chatId, $threadId, $slug, $epicRun['pr'], $signal)) {
            return;
        }

        // blocked outranks done: a stopped epic is not considered completed.
        if ($this->db->epicApplySignal($slug, $signal->added, $signal->hold, $signal->done && $signal->blocked === null)) {
            $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.tails_ceiling', ['slug' => $slug, 'rounds' => Database::MAX_TAILS_ROUNDS]));
        }
        if ($signal->message !== null) {
            $this->delivery->deliver($chatId, $threadId, $signal->message);
        }
        if ($signal->blocked !== null) {
            $this->notifier->blockEpic($slug, $signal->blocked);
        }
        $this->card->update($slug);

        // A successful run with leftover dirt is auto-committed, like a regular request.
        // With AUTO_COMMIT=0 the leftovers stay in the tree; only the prompt-files warning is given.
        if (!$this->config->autoCommit) {
            $this->runResult->deliverPromptFilesWarning($chatId, $threadId);
        } else {
            try {
                $dirty = $this->git->hasChanges();
            } catch (GitStatusException $e) {
                Log::line("epic {$slug}: git status failed, leftovers not committed: ".$e->getMessage());
                $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.git_failed', ['slug' => $slug, 'error' => $e->getMessage()]));

                return;
            }
            if ($dirty) {
                $this->runResult->deliverPromptFilesWarning($chatId, $threadId);
                try {
                    $this->git->commitAndPush($this->runResult->commitMessage("epic {$slug}"));
                } catch (\RuntimeException $e) {
                    $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.git_failed', ['slug' => $slug, 'error' => $e->getMessage()]));
                    $this->dirtyTree->offerDecisionIfDirty($chatId, $threadId);
                }
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
            $reason = $this->locale->t('epicrun.no_cold_list', ['pr' => $pr]);
            $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.failed', ['slug' => $slug, 'reason' => $reason]));
            $this->fail($chatId, $threadId, $slug, $reason);

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
        $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.coverage_incomplete', ['slug' => $slug, 'pr' => $pr, 'reason' => $reason]));
        $this->fail($chatId, $threadId, $slug, "findings coverage {$pr}: {$reason}");

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
            $list = $ids !== [] ? implode(', ', $ids) : $this->locale->t('epicrun.no_verdict');
            $this->notifier->notifyEpic($slug, $this->locale->t('epicrun.hot_review_gave_up', ['task' => $cold['task'], 'attempts' => $attempts, 'list' => $list]));
            $this->card->update($slug);

            return false;
        }
        $this->db->epicSetTaskCounter($slug, $cold['task'], 'reviewed_head', '');
        $this->db->epicSetTaskCounter($slug, $cold['task'], 'cold_retry', $cold['head']);

        return false;
    }

    /**
     * Epic run failure: with AUTO_COMMIT=1 the whole dirty tree (including
     * untracked and binary files) is saved as a patch to
     * `state/epics/<slug>/dirty-<ts>.patch`, then the tree is rolled back; with 0
     * there is no patch and the tree is left as is. A record goes to `failures.log`.
     * There are no buttons or queue pause, and the failure is not committed to the
     * repository. The epic continues.
     */
    private function fail(int $chatId, int $threadId, string $slug, string $reason): void
    {
        $dir = $this->config->stateDir.'/epics/'.$slug;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Log::line("epic {$slug}: cannot create {$dir}");

            return;
        }

        // AUTO_COMMIT=0: the tree is the operator's, so there is no patch and no rollback.
        $patchName = null;
        $dirty = false;
        if ($this->config->autoCommit) {
            try {
                $dirty = $this->git->hasChanges();
            } catch (GitStatusException $e) {
                // Unknown state: no patch, no rollback, and the dirty-tree owner stays.
                Log::line("epic {$slug}: git status failed, not rolling back the tree: ".$e->getMessage());
                $this->failLog($dir, $reason.' (git status failed: '.$e->getMessage().', tree not rolled back)', null);
                $this->delivery->deliver($chatId, $threadId, $this->locale->t('epicrun.status_failed', ['slug' => $slug, 'error' => $e->getMessage()]));

                return;
            }
        }
        if ($dirty) {
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
}
