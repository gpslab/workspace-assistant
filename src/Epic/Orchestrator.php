<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;

/**
 * Epic orchestrator, part 3: the GitHub change detector, deterministic actions
 * and tick scheduling. It makes no judgements — a tick is enqueued only where one
 * is needed; everything decided by facts (the do-it label, clearing draft, starting the hot
 * review) it does itself. Merges, releases and ceilings live in {@see Gates}.
 */
final class Orchestrator
{
    public const string LABEL_DO_IT = 'openronin:do-it';
    public const string LABEL_IN_PROGRESS = 'openronin:in-progress';
    public const string LABEL_AWAITING_ANSWER = 'openronin:awaiting-answer';
    public const string LABEL_AWAITING_ACTION = 'openronin:awaiting-action';

    /**
     * @param \Closure(string, string, ?string): bool $enqueue        enqueue an epic run (slug, kind, PR) into the shared queue
     * @param \Closure(string, string): void          $block          the single epic transition to blocked (slug, reason)
     * @param \Closure(): int                         $now
     * @param int                                     $pollBudget     seconds for one poll() pass: polling must not hang the bot's main loop
     * @param ?\Closure(string, string): void         $notify         message to the epic topic (slug, text)
     * @param ?Card                                   $card           epic status card: edited after every successful poll
     * @param ?string                                 $stateDir       state directory: the cold reviewer's findings are saved there before the hot review; null — do not collect
     * @param int                                     $masterRedHours how many hours a confirmed red master is tolerated before the epic is blocked
     */
    public function __construct(
        private readonly Database $db,
        private readonly Forge $github,
        private readonly \Closure $enqueue,
        private readonly \Closure $block,
        private readonly int $tickInterval = 1800,
        private readonly int $stallTicks = 6,
        private readonly int $quietSec = 600,
        private readonly int $answerTimeout = 7200,
        private readonly ?\Closure $now = null,
        private readonly int $pollBudget = 20,
        private readonly ?\Closure $notify = null,
        private readonly ?Card $card = null,
        private readonly ?string $stateDir = null,
        private readonly int $masterRedHours = 6,
        private readonly ForgeOwners $owners = new ForgeOwners(),
        private readonly Locale $locale = new Locale(),
    ) {
    }

    /** How many consecutive epic polls must fail before the topic is told about it. */
    private const int NOTIFY_AFTER_FAILURES = 5;
    private const int EXECUTOR_GRACE_SEC = 60;

    private int $cursor = 0;

    /** PR (repo#N) of the review enqueued in the current pass; its task is excluded from the merge gate. */
    private ?string $reviewEnqueued = null;
    /** @var array<string, int> */
    private array $failures = [];
    /** @var array<string, string> external part of the fingerprint from the last poll with no epic run */
    private array $external = [];
    /** @var array<string, true> a run has finished, the fingerprint is remembered on the next poll */
    private array $remember = [];

    /**
     * Poll `running` epics; paused/blocked/done get no ticks. The pass
     * is bounded by a time budget and starts from the epic where the previous one stopped.
     *
     * @return int the number of epics polled
     */
    public function poll(): int
    {
        $epics = $this->db->epicsRunning();
        $n = count($epics);
        $deadline = microtime(true) + $this->pollBudget;
        for ($i = 0; $i < $n; ++$i) {
            if ($i > 0 && microtime(true) > $deadline) {
                break;
            }
            $epic = $epics[($this->cursor + $i) % $n];
            $slug = $epic['slug'];
            try {
                $states = $this->pollEpic($slug, $epic['fingerprint'], $epic['attention'], $epic['last_tick_at'], $epic['stale_ticks']);
                unset($this->failures[$slug]);
                $now = ($this->now ?? time(...))();
                $this->db->epicPolled($slug, $now);
                $this->card?->update($slug, $states, true, $now);
            } catch (\Throwable $e) {
                Log::line("epic {$slug}: GitHub poll failed — ".$e->getMessage());
                $this->failures[$slug] = ($this->failures[$slug] ?? 0) + 1;
                if ($this->failures[$slug] === self::NOTIFY_AFTER_FAILURES && $this->notify !== null) {
                    ($this->notify)($slug, $this->locale->t('orchestrator.poll_failing', ['count' => self::NOTIFY_AFTER_FAILURES, 'error' => $e->getMessage()]));
                }
            }
        }
        if ($n > 0) {
            $this->cursor = ($this->cursor + $i) % $n;
        }

        return $i;
    }

    /**
     * An epic run has finished: the fingerprint is remembered on the next poll — but only
     * if nothing changed during the run that the run itself does not touch (red CI
     * on a PR, third-party comments, closing without a merge). Otherwise the external change yields a tick.
     */
    public function rememberAfterRun(string $slug): void
    {
        $this->remember[$slug] = true;
    }

    /**
     * @return array{fingerprint:string, attention:string, external:string, states:array<string, TaskState>, master:array<string, array{state: string, oid: string, branch: string}>}
     */
    private function snapshot(string $slug): array
    {
        $marker = Run::marker($slug);
        $states = [];
        $repos = [];
        foreach ($this->db->epicTasks($slug) as $task) {
            $states[$task['task']] = $this->github->taskState($task['task'], $marker, (string) ($task['counters']['coverage_head'] ?? '') ?: null);
            $repos[$this->owners->repoKey($task['task'])] = true;
        }
        $master = [];
        $names = array_keys($repos);
        sort($names);
        foreach ($names as $repo) {
            $master[$repo] = $this->github->masterCi($repo);
        }
        $events = $this->gates()->masterEvents($slug, $master);

        return [
            'fingerprint' => $this->fingerprint($states, $master),
            'attention' => $this->attentionHash($states, false, $events),
            'external' => $this->attentionHash($states, true, $events),
            'states' => $states,
            'master' => $master,
        ];
    }

    /**
     * @param array<string, TaskState>                                         $states
     * @param array<string, array{state: string, oid: string, branch: string}> $master
     */
    private function fingerprint(array $states, array $master): string
    {
        $data = ['tasks' => [], 'master' => $master];
        $refs = array_keys($states);
        sort($refs);
        foreach ($refs as $ref) {
            $data['tasks'][$ref] = $states[$ref]->fingerprintData();
        }

        return sha1(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Whether the new attention fingerprint has something bad the old one lacked:
     * a false → true transition (label, red CI, closing) or a growing number of third-party comments.
     * Reverse transitions (CI no longer red, label removed) are progress, not an event.
     * An unparseable old fingerprint (null or not JSON) — assume there are no events.
     */
    private function attentionRaised(?string $old, string $new): bool
    {
        $oldData = $old === null ? null : json_decode($old, true);
        if (!is_array($oldData)) {
            return false;
        }
        $newData = json_decode($new, true);
        if (!is_array($newData)) {
            return false;
        }
        foreach ($newData as $ref => $fields) {
            foreach ($fields as $key => $value) {
                $was = $oldData[$ref][$key] ?? ($key === 'foreign' ? 0 : false);
                if ($key === 'foreign' ? $value > $was : ($value === true && $was !== true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, TaskState>                        $states
     * @param bool                                            $external external part only (without awaiting-* labels, which the run sets itself)
     * @param array<string, array{flaky: bool, broken: bool}> $events   master CI events per repository: key `master:<repo>`; external and in both parts
     */
    private function attentionHash(array $states, bool $external, array $events): string
    {
        $data = [];
        foreach ($states as $ref => $state) {
            $data[$ref] = $external ? $state->externalData() : $state->attentionData();
        }
        foreach ($events as $repo => $event) {
            $data["master:{$repo}"] = $event;
        }
        ksort($data);

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, TaskState> task states of this poll (including the bot's actions)
     */
    private function pollEpic(string $slug, ?string $stored, ?string $storedAttention, int $lastTickAt, int $staleTicks): array
    {
        $now = ($this->now ?? time(...))();
        $snap = $this->snapshot($slug);
        $states = $snap['states'];

        if ($this->blockOnStaleAnswer($slug, $states, $now)) {
            return $states;
        }

        $busy = $this->db->epicRunPending($slug);
        $forceTick = false;
        if (!$busy) {
            if (isset($this->remember[$slug])) {
                unset($this->remember[$slug]);
                if (($this->external[$slug] ?? null) === $snap['external']) {
                    $this->db->epicSetFingerprint($slug, $snap['fingerprint'], $snap['attention']);
                    $stored = $snap['fingerprint'];
                    $storedAttention = $snap['attention'];
                } else {
                    $this->db->epicSetFingerprint($slug, $snap['fingerprint']);
                    $stored = $snap['fingerprint'];
                    // No attention fingerprint (a pre-migration record): nothing to compare with, do not swallow the external event.
                    $forceTick = $storedAttention === null;
                }
            }
            $this->external[$slug] = $snap['external'];
        }
        $acted = $this->act($slug, $states, $now, $busy);
        if ($acted === 'review') {
            $busy = true;
        }
        $gates = $this->gates();
        $reviewPrs = $this->db->epicReviewPrs($slug);
        if ($this->reviewEnqueued !== null) {
            $reviewPrs[] = $this->reviewEnqueued;
        }
        if ($gates->run($slug, $states, $snap['master'], $busy, $reviewPrs)) {
            $acted ??= 'other';
        }

        $after = $this->fingerprint($states, $snap['master']);
        if (!$busy) {
            if ($stored === null || $forceTick || $this->attentionRaised($storedAttention, $snap['attention'])) {
                $this->tick($slug, 0, $now);

                return $states;
            }
            if ($snap['fingerprint'] !== $stored || $after !== $stored || $snap['attention'] !== $storedAttention) {
                // What changed (including by this pass's own actions) does not call for judgement (or attention subsided): progress, not a reason for a tick.
                $this->db->epicProgress($slug, $after, $snap['attention'], $now);
                $lastTickAt = $now;
                $staleTicks = 0;
            }
            $inProgress = array_any($states, static fn (TaskState $s): bool => $s->hasLabel(self::LABEL_IN_PROGRESS));
            if ($inProgress && $now - $lastTickAt >= $this->tickInterval * $this->stallTicks) {
                // openronin may have crashed and left the label: with no activity for longer than the stagnation budget — block.
                ($this->block)($slug, $this->locale->t('orchestrator.stalled_in_progress', ['minutes' => intdiv($this->tickInterval * $this->stallTicks, 60)]));

                return $states;
            }
            if (!$inProgress && $now - $lastTickAt >= $this->tickInterval) {
                // A confirmed master breakage is not stagnation: it is bounded by EPIC_MASTER_RED_HOURS (otherwise stagnation would fire after K ticks).
                $broken = $gates->masterBroken($slug);
                if (!$broken && $staleTicks >= $this->stallTicks) {
                    ($this->block)($slug, $this->locale->t('orchestrator.no_movement', ['ticks' => $this->stallTicks]));

                    return $states;
                }
                $this->tick($slug, $broken ? $staleTicks : $staleTicks + 1, $now);

                return $states;
            }
        }
        if ($acted !== null && !$busy) {
            // Our own actions must not wake the next tick: remember the state after them.
            $this->db->epicSetFingerprint($slug, $after, $this->attentionHash($states, false, $gates->masterEvents($slug, $snap['master'])));
        }

        return $states;
    }

    private function gates(): Gates
    {
        return new Gates($this->db, $this->github, $this->enqueue, $this->block, $this->notify, $this->masterRedHours, $this->now, $this->owners, $this->locale);
    }

    private function tick(string $slug, int $stale, int $now): void
    {
        if (($this->enqueue)($slug, Run::TICK, null)) {
            $this->db->epicTickQueued($slug, $now, $stale);
        }
    }

    /**
     * The awaiting-answer label has been up longer than the timeout since the last comment with
     * the epic marker (no such comment — nothing to count from).
     *
     * @param array<string, TaskState> $states
     */
    private function blockOnStaleAnswer(string $slug, array $states, int $now): bool
    {
        foreach ($this->db->epicTasks($slug) as $task) {
            $s = $states[$task['task']] ?? null;
            if ($s === null || $task['status'] === 'blocked' || !$s->hasLabel(self::LABEL_AWAITING_ANSWER) || $s->lastEpicComment === null) {
                continue;
            }
            if ($now - $s->lastEpicComment > $this->answerTimeout) {
                $this->db->epicSetTaskStatus($slug, $task['task'], 'blocked');
                ($this->block)($slug, $this->locale->t('orchestrator.answer_timeout', ['task' => $task['task'], 'minutes' => intdiv($this->answerTimeout, 60)]));

                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic actions by facts, without a tick.
     *
     * @param array<string, TaskState> $states mutated: reflects our actions
     *
     * @return string|null 'review' — hot review enqueued, 'other' — another action, null — nothing
     */
    private function act(string $slug, array &$states, int $now, bool $busy): ?string
    {
        $result = null;
        $this->reviewEnqueued = null;
        foreach ($this->db->epicTasks($slug) as $task) {
            $s = $states[$task['task']] ?? null;
            if ($s === null || $task['status'] === 'blocked') {
                continue;
            }

            if (!$task['hold'] && $s->issueOpen && $s->prState === TaskState::NO_PR && !$this->hasOpenroninLabel($s)
                && $this->afterMerged($task['after'], $states)) {
                $this->github->addLabel($task['task'], self::LABEL_DO_IT);
                $states[$task['task']] = $s->withLabel(self::LABEL_DO_IT);
                $result ??= 'other';
            }

            if ($s->prState === TaskState::DRAFT && $s->checks === TaskState::CHECKS_GREEN && $s->prNumber !== null) {
                $this->github->markReady($task['task'], $s->prNumber);
                $states[$task['task']] = $s->withPrState(TaskState::OPEN);
                $result ??= 'other';
            }

            if (!$busy && $result !== 'review' && $this->readyForReview($s, $task['counters'], $now)) {
                $pr = $this->owners->repoKey($task['task']).'#'.$s->prNumber;
                if ($this->stateDir !== null) {
                    // Collect before enqueueing: the signal parser reads this file and re-collects nothing.
                    ColdFindings::save(ColdFindings::path($this->stateDir, $slug, $pr), [
                        'task' => $task['task'],
                        'pr' => $pr,
                        'head' => (string) $s->head,
                        'findings' => $this->github->coldFindings($pr),
                    ]);
                }
                if (($this->enqueue)($slug, Run::REVIEW, $pr)) {
                    $this->db->epicSetTaskCounter($slug, $task['task'], 'reviewed_head', (string) $s->head);
                    $result = 'review';
                    $this->reviewEnqueued = $pr;
                }
            }
        }

        return $result;
    }

    private function hasOpenroninLabel(TaskState $s): bool
    {
        return array_any($s->labels, static fn (string $l): bool => str_starts_with($l, 'openronin:'));
    }

    /**
     * @param list<string>             $after
     * @param array<string, TaskState> $states
     */
    private function afterMerged(array $after, array $states): bool
    {
        return array_all($after, static fn (string $ref): bool => ($states[$ref]->prState ?? null) === TaskState::MERGED);
    }

    /**
     * The epic review on the current head is APPROVED but there is no coverage on that head (a commit
     * arrived between enqueueing and the run): without a retry the gate would wait forever. After
     * REQUEST_CHANGES there is no retry: the gate waits for a new commit from openronin, and a repeat
     * review would only publish a duplicate. The retry is one-off — enqueueing writes `reviewed_head` = head.
     *
     * @param array<string, mixed> $counters
     */
    private function coverageStale(TaskState $s, array $counters): bool
    {
        return $s->epicReviewHead !== null
            && $s->epicReviewHead === $s->head
            && $s->epicVerdict === 'APPROVED'
            && ($counters['coverage_head'] ?? '') !== $s->head
            && !$s->headAccepted((string) ($counters['coverage_head'] ?? ''));
    }

    /**
     * The event path next to the quiet window: openronin's comment is the last activity on the PR and
     * issue, CI is green, there are no conflicts, the cold reviewer (if there is one) has left its summary,
     * and a short grace period has passed (a second push, CI run registration).
     */
    private function executorSpoke(TaskState $s, int $now): bool
    {
        return $s->executorLastWord
            && $s->checks === TaskState::CHECKS_GREEN
            && $s->mergeable !== TaskState::MERGEABLE_NO
            && ($s->coldReviewDone === null || $s->coldSummary)
            && $now - (int) $s->lastActivityAt >= self::EXECUTOR_GRACE_SEC;
    }

    /**
     * The PR "went quiet": not draft, the cold reviewer has finished (if there is one), no
     * in-progress, checks non-empty and not pending, no commits or comments
     * younger than T (or the event path, {@see executorSpoke()}), no epic review on this head yet (and none enqueued), or one exists but the cold reviewer's findings coverage
     * is not recorded for this head ({@see coverageStale()}). Never for a head already accepted on the review side
     * ({@see TaskState::headAccepted()}): the merge gate merges it without a new review. Exception — a retry
     * after a findings-coverage failure (`cold_retry` = head): a review on the head exists but is not counted.
     *
     * @param array<string, mixed> $counters
     */
    private function readyForReview(TaskState $s, array $counters, int $now): bool
    {
        return $s->prState === TaskState::OPEN
            && $s->prNumber !== null
            && $s->coldReviewDone !== false
            && !$s->hasLabel(self::LABEL_IN_PROGRESS)
            && !in_array($s->checks, [TaskState::CHECKS_NONE, TaskState::CHECKS_PENDING], true)
            && $s->lastActivityAt !== null
            && ($now - $s->lastActivityAt >= $this->quietSec || $this->executorSpoke($s, $now))
            && (
                ($counters['cold_retry'] ?? '') === $s->head
                || (!$s->headAccepted((string) ($counters['coverage_head'] ?? '')) && (!$s->epicReviewedHead || $this->coverageStale($s, $counters)))
            )
            && ($counters['reviewed_head'] ?? null) !== $s->head;
    }
}
