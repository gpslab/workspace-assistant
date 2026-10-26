<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\Locale;

/**
 * Epic orchestrator, part 4: irreversible and counted steps — merge, post-merge actions,
 * contract release, review and tail-round ceilings. Decisions are made entirely from GitHub
 * facts and the plan; the model takes no part. Moving the epic to blocked and messages to the
 * topic go through closures.
 */
final class Gates
{
    /** The third epic review with REQUEST_CHANGES on a task blocks the task. */
    public const int MAX_CHANGES_REQUESTED = 3;

    /** How many seconds after a restart a red CI result may still be the old one rather than the restart's result. */
    public const int RERUN_GRACE = 120;

    /** How many enqueued ticks in a row without a fix task are tolerated while a breakage is confirmed. */
    public const int FIX_TICKS = 2;

    /**
     * @param \Closure(string, string, ?string): bool $enqueue enqueue an epic run (slug, kind, PR)
     * @param \Closure(string, string): void          $block   the single epic transition to blocked (slug, reason)
     * @param ?\Closure(string, string): void         $notify  message to the epic topic (slug, text)
     * @param ?\Closure(): int                        $now
     */
    public function __construct(
        private readonly Database $db,
        private readonly GitHub $github,
        private readonly \Closure $enqueue,
        private readonly \Closure $block,
        private readonly ?\Closure $notify = null,
        private readonly int $masterRedHours = 6,
        private readonly ?\Closure $now = null,
        private readonly GithubOwners $owners = new GithubOwners(),
        private readonly Locale $locale = new Locale(),
    ) {
    }

    /**
     * The top version heading in the CHANGELOG.md text (`## [1.2.0] - …`, `## v1.2.0`);
     * the Unreleased section is skipped. null — there is no version heading.
     */
    public static function changelogVersion(string $changelog): ?string
    {
        foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
            if (preg_match('/^#{1,3}\s*\[?v?(\d+\.\d+\.\d+[^\]\s]*)\]?/i', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, TaskState>                                         $states    mutated: reflects our actions
     * @param array<string, array{state: string, oid: string, branch: string}> $master    CI result of the default branch per repository
     * @param list<string>                                                     $reviewPrs PRs (repo#N) with a review run queued, in progress or enqueued in this pass
     *
     * @return bool an irreversible action was performed (merge, update-branch, release, CI restart, comment, finish)
     */
    public function run(string $slug, array &$states, array $master, bool $busy, array $reviewPrs = []): bool
    {
        // A red master after an epic merge pauses merges and releases of that repository, but not the epic.
        $watch = $this->watchMaster($slug, $master, $busy);
        if ($watch === null) {
            return false;
        }
        [$paused, $acted] = $watch;
        $this->enforceReviewCeiling($slug, $states);
        $acted = $this->mergeGate($slug, $states, $paused, $reviewPrs) || $acted;
        $acted = $this->afterMerge($slug, $states, $paused) || $acted;
        $acted = $this->releases($slug, $states, $paused) || $acted;
        if (!$busy && !$acted) {
            $acted = $this->finalCheck($slug, $states);
        }

        return $acted;
    }

    /**
     * `master` CI events per repository: flaky — green after a restart, broken —
     * red after a restart. A pure function of the records and the current snapshot: goes into the attention
     * fingerprint and into the very first poll that sees the event.
     *
     * @param array<string, array{state: string, oid: string, branch: string}> $master
     *
     * @return array<string, array{flaky: bool, broken: bool}>
     */
    public function masterEvents(string $slug, array $master): array
    {
        $now = $this->now();
        $records = $this->db->epicMasters($slug);
        $events = [];
        foreach ($master as $repo => $ci) {
            $events[$repo] = ['flaky' => false, 'broken' => false];
            $rec = $records[$repo] ?? null;
            if ($rec === null || $rec->sha !== $ci['oid']) {
                continue;
            }
            if ($ci['state'] === 'SUCCESS') {
                $events[$repo]['flaky'] = $rec->kind !== MasterRecord::BROKEN;
            } elseif (self::isRed($ci['state'])) {
                $events[$repo]['broken'] = $rec->kind === MasterRecord::BROKEN
                    || ($rec->kind === MasterRecord::RERUN && $rec->settled($now, self::RERUN_GRACE));
            }
        }

        return $events;
    }

    /** There is a confirmed `master` breakage: stagnation by timer does not count. */
    public function masterBroken(string $slug): bool
    {
        return array_any($this->db->epicMasters($slug), static fn (MasterRecord $r): bool => $r->kind === MasterRecord::BROKEN);
    }

    private static function isRed(string $state): bool
    {
        return in_array($state, ['FAILURE', 'ERROR'], true);
    }

    private function now(): int
    {
        return ($this->now ?? time(...))();
    }

    /**
     * @param array<string, TaskState> $states
     */
    private function enforceReviewCeiling(string $slug, array $states): void
    {
        foreach ($this->db->epicTasks($slug) as $task) {
            $s = $states[$task['task']] ?? null;
            if ($s === null || $task['status'] === 'blocked' || $s->epicVerdict !== 'CHANGES_REQUESTED') {
                continue;
            }
            // After /epic resume only reviews submitted no earlier than the resume mark count;
            // time 0 (submittedAt not recognised) counts as fresh so the ceiling cannot be bypassed.
            $since = (int) ($task['counters']['epic_changes_since'] ?? 0);
            $fresh = $since > 0
                ? count(array_filter($s->epicChangesRequestedAt, static fn (int $at): bool => $at === 0 || $at >= $since))
                : $s->epicChangesRequested;
            $count = max($fresh, (int) ($task['counters']['epic_changes'] ?? 0));
            if ($count !== (int) ($task['counters']['epic_changes'] ?? 0)) {
                $this->db->epicSetTaskCounter($slug, $task['task'], 'epic_changes', (string) $count);
            }
            if ($count >= self::MAX_CHANGES_REQUESTED) {
                $this->db->epicSetTaskStatus($slug, $task['task'], 'blocked');
                $this->say($slug, $this->locale->t('gate.changes_ceiling', ['task' => $task['task'], 'count' => $count]));
            }
        }
    }

    /**
     * Merge or update-branch — one PR per repository per pass: after the first one
     * the snapshot of the repository's other PRs (behind, mergeable, CI) is stale.
     * A `gh` failure on a task blocks the task, not the poll of the whole epic.
     *
     * @param array<string, TaskState> $states
     * @param array<string, true>      $paused    repositories paused because of a red master
     * @param list<string>             $reviewPrs PRs with a review run: their tasks are skipped
     */
    private function mergeGate(string $slug, array &$states, array $paused, array $reviewPrs): bool
    {
        $acted = false;
        $touched = [];
        foreach ($this->db->epicTasks($slug) as $task) {
            $s = $states[$task['task']] ?? null;
            $repo = $this->owners->repoKey($task['task']);
            if ($s === null || $task['status'] === 'blocked' || $s->prNumber === null || isset($touched[$repo])
                || in_array($repo.'#'.$s->prNumber, $reviewPrs, true)) {
                continue;
            }
            if (isset($paused[$repo]) && !$this->isFixTask($slug, $repo, $task)) {
                continue;
            }
            $coverage = (string) ($task['counters']['coverage_head'] ?? '');
            $decision = MergeGate::decide($s, $coverage, $this->locale);
            if ($decision['action'] === MergeGate::WAIT) {
                continue;
            }
            try {
                if ($decision['action'] === MergeGate::MERGE) {
                    $this->github->merge($task['task'], $s->prNumber);
                    $states[$task['task']] = $s->withPrState(TaskState::MERGED);
                    $this->db->epicSetTaskCounter($slug, $task['task'], 'merge_unverified', '1');
                } else {
                    $this->github->updateBranch($task['task'], $s->prNumber);
                }
            } catch (\RuntimeException $e) {
                $this->db->epicSetTaskStatus($slug, $task['task'], 'blocked');
                $this->say($slug, $this->locale->t('gate.merge_failed', ['task' => $task['task'], 'action' => $decision['action'], 'error' => $e->getMessage()]));

                continue;
            }
            $touched[$repo] = true;
            $acted = true;
        }

        return $acted;
    }

    /**
     * A fix task for a confirmed breakage: `added` in the same repository, which did not exist
     * at confirmation. Only it passes the merge gate while the repository is paused.
     *
     * @param array{task: string, source: string} $task
     */
    private function isFixTask(string $slug, string $repo, array $task): bool
    {
        $rec = $this->db->epicMaster($slug, $repo);

        return $rec !== null && $rec->kind === MasterRecord::BROKEN
            && $task['source'] === 'added' && !in_array($task['task'], $rec->tasksBase, true);
    }

    /**
     * Red `master` CI in a repository the epic merged into and has not seen a green master since
     * (stray failures older than the epic's merges do not count). For the first red
     * SHA the bot restarts the failed jobs once (a mark per repository and SHA).
     * Green after the restart — flakiness, red — a confirmed breakage.
     * While the SHA has a restart mark and CI is not SUCCESS (including PENDING) the repository
     * is paused. A breakage longer than `masterRedHours` or with no fix task within
     * {@see FIX_TICKS} ticks — the epic is blocked.
     *
     * @param array<string, array{state: string, oid: string, branch: string}> $master
     *
     * @return array{array<string, true>, bool}|null paused repositories and "there was a restart"; null — the epic is stopped
     */
    private function watchMaster(string $slug, array $master, bool $busy): ?array
    {
        $now = $this->now();
        $byRepo = [];
        foreach ($this->db->epicTasks($slug) as $task) {
            $byRepo[$this->owners->repoKey($task['task'])][] = $task;
        }
        $paused = [];
        $acted = false;
        foreach ($byRepo as $repo => $tasks) {
            $rec = $this->db->epicMaster($slug, $repo);
            $unverified = array_any($tasks, static fn (array $t): bool => ($t['counters']['merge_unverified'] ?? '') === '1');
            $ci = $master[$repo] ?? null;
            if ($ci === null || (!$unverified && $rec === null)) {
                continue;
            }

            if ($ci['state'] === 'SUCCESS') {
                foreach ($tasks as $task) {
                    if (($task['counters']['merge_unverified'] ?? '') === '1') {
                        $this->db->epicSetTaskCounter($slug, $task['task'], 'merge_unverified', '0');
                    }
                }
                if ($rec !== null) {
                    $this->onGreen($slug, $rec, $ci['oid']);
                }

                continue;
            }

            if ($rec === null || $rec->sha !== $ci['oid']) {
                if (self::isRed($ci['state']) && $unverified) {
                    $this->db->epicMasterSet($slug, $this->rerun($slug, $rec, $repo, $ci, $now));
                    $paused[$repo] = true;
                    $acted = true;

                    continue;
                }
                if ($rec === null || $rec->kind === MasterRecord::FLAKY) {
                    continue;
                }
                // A new commit on top of an unresolved breakage: the pause and the safety net hold until SUCCESS.
                $paused[$repo] = true;
                if ($rec->kind !== MasterRecord::BROKEN) {
                    continue;
                }
            } else {
                $paused[$repo] = true;
            }
            if ($rec->sha === $ci['oid'] && $rec->kind === MasterRecord::RERUN) {
                if ($ci['state'] === 'PENDING' && !$rec->pendingSeen) {
                    $rec->pendingSeen = true;
                    $this->db->epicMasterSet($slug, $rec);
                } elseif (self::isRed($ci['state']) && $rec->settled($now, self::RERUN_GRACE)) {
                    $this->confirmBroken($slug, $rec, $now);
                }
            }
            if ($rec->kind === MasterRecord::BROKEN && ($reason = $this->brokenFailure($slug, $rec, $now, $busy)) !== null) {
                $this->db->epicMasterDelete($slug, $repo);
                ($this->block)($slug, $reason);

                return null;
            }
        }

        return [$paused, $acted];
    }

    /**
     * @param array{state: string, oid: string, branch: string} $ci
     */
    private function rerun(string $slug, ?MasterRecord $old, string $repo, array $ci, int $now): MasterRecord
    {
        $mark = $this->db->masterRerun($repo);
        if ($mark !== null && $mark['sha'] === $ci['oid']) {
            $runs = $mark['runs'];
        } else {
            // A gh failure must not bring down the epic poll: the mark is set regardless, then grace → broken.
            $runs = [];
            try {
                $runs = $this->github->failedRuns($repo, $ci['branch'], $ci['oid']);
            } catch (\RuntimeException $e) {
                $this->say($slug, $this->locale->t('gate.failed_runs_failed', ['repo' => $repo, 'error' => $e->getMessage()]));
            }
            $rerun = [];
            foreach ($runs as $run) {
                try {
                    $this->github->rerunFailed($repo, $run['id']);
                    $rerun[] = $run;
                } catch (\RuntimeException $e) {
                    $this->say($slug, $this->locale->t('gate.rerun_failed', ['repo' => $repo, 'run' => $run['id'], 'error' => $e->getMessage()]));
                }
            }
            $runs = $rerun;
            $this->db->masterRerunSet($repo, $ci['oid'], $runs);
        }
        $rec = new MasterRecord($repo, $ci['oid'], $runs, $now);
        if ($old !== null && $old->kind === MasterRecord::BROKEN) {
            // The breakage was not interrupted by green: the safety-net clock and the tick count keep running.
            $rec->redSince = $old->redSince;
            $rec->ticksBase = $old->ticksBase;
            $rec->tasksBase = $old->tasksBase;
            $rec->fixFound = $old->fixFound;
        }

        return $rec;
    }

    private function onGreen(string $slug, MasterRecord $rec, string $oid): void
    {
        if ($rec->sha === $oid && $rec->kind !== MasterRecord::BROKEN) {
            if ($rec->kind !== MasterRecord::FLAKY) {
                $rec->kind = MasterRecord::FLAKY;
                $this->db->epicMasterSet($slug, $rec);
            }
        } elseif ($rec->kind !== MasterRecord::FLAKY || $rec->prompted) {
            // The pause is lifted, the tick already knows about the flakiness (or it was a green after a breakage).
            $this->db->epicMasterDelete($slug, $rec->repo);
        }
    }

    private function confirmBroken(string $slug, MasterRecord $rec, int $now): void
    {
        $rec->kind = MasterRecord::BROKEN;
        if ($rec->redSince === 0) {
            $rec->redSince = $now;
            $rec->ticksBase = $this->db->epicTicksTotal($slug);
            $rec->tasksBase = array_column($this->db->epicTasks($slug), 'task');
        }
        $this->db->epicMasterSet($slug, $rec);
    }

    /**
     * Safety net for a confirmed breakage: the reason to stop the epic, or null.
     */
    private function brokenFailure(string $slug, MasterRecord $rec, int $now, bool $busy): ?string
    {
        $hours = intdiv($now - $rec->redSince, 3600);
        if ($now - $rec->redSince >= $this->masterRedHours * 3600) {
            return $this->locale->t('gate.master_red_hours', ['repo' => $rec->repo, 'hours' => $hours]);
        }
        if ($rec->fixFound) {
            return null;
        }
        $fix = array_any($this->db->epicTasks($slug), fn (array $t): bool => $t['source'] === 'added'
            && $this->owners->repoKey($t['task']) === $this->owners->repoKey($rec->repo) && !in_array($t['task'], $rec->tasksBase, true));
        if ($fix) {
            $rec->fixFound = true;
            $this->db->epicMasterSet($slug, $rec);
        } elseif (!$busy && $this->db->epicTicksTotal($slug) - $rec->ticksBase >= self::FIX_TICKS) {
            return $this->locale->t('gate.master_no_fix', ['repo' => $rec->repo, 'ticks' => self::FIX_TICKS]);
        }

        return null;
    }

    /**
     * Conflicts of the epic's other PRs in repositories with a merged task.
     *
     * @param array<string, TaskState> $states
     * @param array<string, true>      $paused
     */
    private function afterMerge(string $slug, array $states, array $paused): bool
    {
        $merged = [];
        foreach ($states as $ref => $s) {
            if ($s->prState === TaskState::MERGED && !isset($paused[$this->owners->repoKey($ref)])) {
                $merged[$this->owners->repoKey($ref)] = true;
            }
        }
        if ($merged === []) {
            return false;
        }

        $acted = false;
        foreach ($this->db->epicTasks($slug) as $task) {
            $s = $states[$task['task']] ?? null;
            $repo = $this->owners->repoKey($task['task']);
            if ($s === null || $task['status'] === 'blocked' || $s->prNumber === null || !isset($merged[$repo])
                || !in_array($s->prState, [TaskState::OPEN, TaskState::DRAFT], true)) {
                continue;
            }
            $asked = (string) ($task['counters']['conflict_head'] ?? '');
            if ($s->mergeable === TaskState::MERGEABLE_YES && $asked !== '') {
                $this->db->epicSetTaskCounter($slug, $task['task'], 'conflict_head', '');
            } elseif ($s->mergeable === TaskState::MERGEABLE_NO) {
                if ($asked === '') {
                    $this->github->commentPr($task['task'], $s->prNumber, $this->locale->t('gate.conflict_comment')."\n\n".Run::marker($slug));
                    $this->db->epicSetTaskCounter($slug, $task['task'], 'conflict_head', (string) $s->head);
                    $acted = true;
                } elseif ($asked !== $s->head) {
                    $this->db->epicSetTaskStatus($slug, $task['task'], 'blocked');
                    $this->say($slug, $this->locale->t('gate.conflict_unresolved', ['task' => $task['task']]));
                }
            }
        }

        return $acted;
    }

    /**
     * @param array<string, TaskState> $states
     * @param array<string, true>      $paused
     */
    private function releases(string $slug, array $states, array $paused): bool
    {
        $acted = false;
        foreach ($this->db->epicReleases($slug) as $release) {
            $status = $this->db->epicReleaseStatus($slug, $release['repo'], $release['version']);
            if ($status === 'done' || isset($paused[$this->owners->repoKey($release['repo'])]) || !array_all($release['after'], static fn (string $ref): bool => ($states[$ref]->prState ?? null) === TaskState::MERGED)) {
                continue;
            }
            if ($this->github->tagExists($release['repo'], $release['version'])) {
                $this->db->epicSetReleaseStatus($slug, $release['repo'], $release['version'], 'done');

                continue;
            }
            $changelog = $this->github->changelog($release['repo']);
            $top = $changelog === null ? null : self::changelogVersion($changelog);
            if ($top === null || ltrim($top, 'vV') !== ltrim($release['version'], 'vV')) {
                if ($status !== 'parked') {
                    $this->db->epicSetReleaseStatus($slug, $release['repo'], $release['version'], 'parked');
                    $this->say($slug, $this->locale->t('gate.release_parked', ['repo' => $release['repo'], 'version' => $release['version'], 'top' => $top ?? $this->locale->t('gate.changelog_top_missing')]));
                }

                continue;
            }
            $this->github->createRelease($release['repo'], $release['version']);
            $this->db->epicSetReleaseStatus($slug, $release['repo'], $release['version'], 'done');
            $acted = true;
        }

        return $acted;
    }

    /**
     * All plan tasks and extra tasks are closed → the final check. Each check
     * (the first and one per tail round) is enqueued once; beyond the tail-round
     * ceiling extra tasks are discarded by {@see Database::epicApplySignal()}.
     *
     * @param array<string, TaskState> $states
     */
    private function finalCheck(string $slug, array $states): bool
    {
        $tasks = $this->db->epicTasks($slug);
        if ($tasks === [] || !array_all($tasks, static fn (array $t): bool => $t['status'] !== 'blocked'
            && ($s = $states[$t['task']] ?? null) !== null && ($s->prState === TaskState::MERGED || !$s->issueOpen))) {
            return false;
        }
        ['final_runs' => $finals, 'tails_rounds' => $tails] = $this->db->epicFinalCounters($slug);
        if ($finals > $tails) {
            return $this->retryLostFinal($slug);
        }
        if (!($this->enqueue)($slug, Run::FINAL, null)) {
            return false;
        }
        $this->db->epicFinalStarted($slug);

        return true;
    }

    /**
     * The round's final check is counted but neither queued nor running and the epic is not done:
     * it is lost. The first loss in a round queues it again (`final_runs` is not touched), the next one blocks the epic.
     * The main guard against a false loss is the `busy` gate in `run()` (any queued or running run of the epic);
     * the `epicFinalPending` check is defense in depth.
     */
    private function retryLostFinal(string $slug): bool
    {
        $epic = $this->db->epicGet($slug);
        if ($epic === null || $epic['status'] !== 'running' || $epic['done'] || $this->db->epicFinalPending($slug)) {
            return false;
        }
        if ($this->db->epicFinalRetries($slug) >= 1) {
            ($this->block)($slug, $this->locale->t('gate.final_lost_blocked'));

            return true;
        }
        if (!($this->enqueue)($slug, Run::FINAL, null)) {
            return false;
        }
        $this->db->epicFinalRetried($slug);
        $this->say($slug, $this->locale->t('gate.final_lost_retry'));

        return true;
    }

    private function say(string $slug, string $text): void
    {
        if ($this->notify !== null) {
            ($this->notify)($slug, $text);
        }
    }
}
