<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\Gates;
use GpsLab\WorkspaceAssistant\Epic\MergeGate;
use GpsLab\WorkspaceAssistant\Epic\Orchestrator;
use GpsLab\WorkspaceAssistant\Epic\TaskState;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MergeGate::class)]
#[CoversClass(Gates::class)]
#[CoversClass(Database::class)]
final class GatesTest extends TestCase
{
    private string $dir;
    private Database $db;
    private bool $uncovered = false;
    private int $now = 1_000_000;
    private ?string $locale = 'en';
    private FakeGitHub $gh;
    /** @var list<array{string, string}> */
    private array $blocked = [];
    /** @var list<array{string, string}> */
    private array $notified = [];
    /** @var list<array{string, string}> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tgg_'.uniqid('', true);
        mkdir($this->dir);
        $this->db = new Database($this->dir);
        $this->gh = new FakeGitHub();
        $this->gh->changelogs['a'] = "## 1.2.0\n";
        $this->db->epicCreate('epic', 1, 2, [['task' => 'a#1', 'after' => []], ['task' => 'a#2', 'after' => []]], [['repo' => 'a', 'version' => '1.2.0', 'after' => ['a#1']]]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function pr(string $task = 'a#1', array $o = []): TaskState
    {
        $o += [
            'prState' => 'open', 'head' => 'h1', 'checks' => 'green', 'mergeable' => 'true',
            'epicVerdict' => 'APPROVED', 'epicReviewHead' => 'h1', 'changedAfterReview' => false,
            'headOnlyMasterMerge' => false, 'behindMaster' => false, 'epicChangesRequested' => 0,
            'epicChangesRequestedAt' => [], 'labels' => [], 'prNumber' => 7,
        ];

        return new TaskState(
            $task,
            prState: $o['prState'],
            prNumber: $o['prNumber'],
            head: $o['head'],
            checks: $o['checks'],
            mergeable: $o['mergeable'],
            epicVerdict: $o['epicVerdict'],
            epicReviewHead: $o['epicReviewHead'],
            changedAfterReview: $o['changedAfterReview'],
            headOnlyMasterMerge: $o['headOnlyMasterMerge'],
            behindMaster: $o['behindMaster'],
            epicChangesRequested: $o['epicChangesRequested'],
            epicChangesRequestedAt: $o['epicChangesRequestedAt'],
            labels: $o['labels'],
        );
    }

    /**
     * @param array<string, TaskState> $states
     * @param array<string, string>    $master    repo => CI result
     * @param list<string>             $reviewPrs
     */
    private function runGates(array &$states, array $master = [], bool $busy = false, array $reviewPrs = []): bool
    {
        foreach ($master as $repo => $state) {
            $this->gh->master[$repo] = $state;
        }
        $master = array_map(fn (string $repo): array => $this->gh->masterCi($repo), array_combine(array_keys($master), array_keys($master)));
        if (!$this->uncovered) {
            foreach ($states as $ref => $state) {
                $this->db->epicSetTaskCounter('epic', $ref, 'coverage_head', (string) $state->epicReviewHead);
            }
        }
        $gates = new Gates(
            $this->db,
            $this->gh,
            function (string $slug, string $run): bool {
                $this->enqueued[] = [$slug, $run];

                return true;
            },
            function (string $slug, string $reason): void {
                $this->blocked[] = [$slug, $reason];
            },
            function (string $slug, string $text): void {
                $this->notified[] = [$slug, $text];
            },
            6,
            fn (): int => $this->now,
            self::owners(),
            ...($this->locale === null ? [] : [new Locale($this->locale)]),
        );

        return $gates->run('epic', $states, $master, $busy, $reviewPrs);
    }

    /**
     * @return list<array{string, string}>
     */
    private function blockedList(): array
    {
        return $this->blocked;
    }

    private function queueFinal(): int
    {
        return (int) $this->db->queueAdd(1, 2, 100, 'epic', 'run', [['slug' => 'epic', 'run' => 'final']]);
    }

    /**
     * @return array<string, TaskState>
     */
    private function closedStates(): array
    {
        return ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['prState' => 'merged'])];
    }

    public function testLostFinalIsRetriedOnceWithoutRaisingFinalRuns(): void
    {
        $this->gh->tags = ['a@1.2.0'];
        $states = $this->closedStates();
        // a stuck epic: the final was counted, nothing is queued
        $this->db->epicFinalStarted('epic');

        self::assertTrue($this->runGates($states));
        self::assertSame([['epic', 'final']], $this->enqueued);
        self::assertSame(1, $this->db->epicFinalCounters('epic')['final_runs']);
        self::assertSame(1, $this->db->epicFinalRetries('epic'));
        self::assertCount(1, $this->notified);
        self::assertSame([], $this->blocked);

        // lost again → blocked
        self::assertTrue($this->runGates($states));
        self::assertSame([['epic', 'final']], $this->enqueued);
        $blocked = $this->blockedList();
        self::assertCount(1, $blocked);
        self::assertSame('epic', $blocked[0][0]);
        self::assertStringContainsString('twice', $blocked[0][1]);
    }

    public function testQueuedFinalIsNotLost(): void
    {
        $this->gh->tags = ['a@1.2.0'];
        $states = $this->closedStates();
        $this->db->epicFinalStarted('epic');
        $this->queueFinal();

        self::assertFalse($this->runGates($states));
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->db->epicFinalRetries('epic'));
        self::assertSame([], $this->blocked);
    }

    public function testBusyEpicIsNeverRetriedAsLostFinal(): void
    {
        $this->gh->tags = ['a@1.2.0'];
        $states = $this->closedStates();
        $this->db->epicFinalStarted('epic');

        self::assertFalse($this->runGates($states, busy: true));
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->db->epicFinalRetries('epic'));
        self::assertSame([], $this->blocked);
    }

    public function testRunningFinalIsNotLost(): void
    {
        $this->gh->tags = ['a@1.2.0'];
        $states = $this->closedStates();
        $this->db->epicFinalStarted('epic');
        $id = $this->queueFinal();
        $this->db->queueMarkRunning($id);

        self::assertFalse($this->runGates($states));
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->db->epicFinalRetries('epic'));
    }

    public function testFinalRetriesResetByTailsRoundAndResume(): void
    {
        $this->db->epicFinalStarted('epic');
        $this->db->epicFinalRetried('epic');
        self::assertSame(1, $this->db->epicFinalRetries('epic'));
        self::assertFalse($this->db->epicApplySignal('epic', [['task' => 'a#11', 'after' => []]], null, false));
        self::assertSame(0, $this->db->epicFinalRetries('epic'));

        $this->db->epicFinalRetried('epic');
        $this->db->epicBlock('epic', 'x');
        $this->db->epicResume('epic');
        self::assertSame(0, $this->db->epicFinalRetries('epic'));
    }

    private function enqueuedCount(): int
    {
        return count($this->enqueued);
    }

    private function notifiedCount(): int
    {
        return count($this->notified);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function mergeCases(): array
    {
        return [
            'ok' => [[], 'merge'],
            'request changes' => [['epicVerdict' => 'CHANGES_REQUESTED'], 'wait'],
            'no epic review (manual only)' => [['epicVerdict' => null, 'epicReviewHead' => null], 'wait'],
            'comment or foreign review after approve' => [['changedAfterReview' => true], 'wait'],
            'commit after approve' => [['head' => 'h2'], 'wait'],
            'head is master merge only' => [['head' => 'h2', 'headOnlyMasterMerge' => true], 'merge'],
            'red check' => [['checks' => 'failed'], 'wait'],
            'pending check' => [['checks' => 'pending'], 'wait'],
            'empty checks' => [['checks' => 'none'], 'wait'],
            'conflicting' => [['mergeable' => 'false'], 'wait'],
            'mergeable null' => [['mergeable' => 'unknown'], 'wait'],
            'behind master' => [['behindMaster' => true], 'update-branch'],
            'draft' => [['prState' => 'draft'], 'wait'],
        ];
    }

    public function testGateRequiresCoverageOfLastEpicReviewHead(): void
    {
        $s = self::pr('a#1');
        self::assertSame('wait', MergeGate::decide($s, '')['action']);
        self::assertSame('merge', MergeGate::decide($s, 'h1')['action']);
    }

    public function testNoMergeWithoutCoverageHead(): void
    {
        $this->uncovered = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        self::assertSame([], $this->gh->merged);

        // coverage_head from another head — not coverage either
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'old');
        $this->runGates($states);
        self::assertSame([], $this->gh->merged);
    }

    public function testMergesWhenApprovalMovedToMasterOnlyMergeOfCoverageHead(): void
    {
        $this->uncovered = true;
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        // GitHub moved APPROVED to h2 (a master merge on top of coverage_head h1): the anchor is coverage_head
        $states = ['a#1' => self::pr('a#1', ['head' => 'h2', 'epicReviewHead' => 'h2', 'headOnlyMasterMerge' => true])];
        $this->runGates($states);
        self::assertSame(['a#1 7'], $this->gh->merged);
    }

    public function testNoMergeWhenApprovalMovedToOrdinaryCommit(): void
    {
        $this->uncovered = true;
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $states = ['a#1' => self::pr('a#1', ['head' => 'h2', 'epicReviewHead' => 'h2', 'headOnlyMasterMerge' => false])];
        $this->runGates($states);
        self::assertSame([], $this->gh->merged);
    }

    public function testNoMergeWhenApprovalStaysOnOlderHeadThanCoverage(): void
    {
        $this->uncovered = true;
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        // the review published on h0 only; coverage was recorded on h1 without a published approval
        $states = ['a#1' => self::pr('a#1', ['head' => 'h1', 'epicReviewHead' => 'h0', 'headOnlyMasterMerge' => false])];
        $this->runGates($states);
        self::assertSame([], $this->gh->merged);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('mergeCases')]
    public function testMergeGate(array $override, string $action): void
    {
        $states = ['a#1' => self::pr('a#1', $override)];
        self::assertSame($action, MergeGate::decide($states['a#1'], 'h1')['action']);
        $this->runGates($states);

        self::assertSame($action === 'merge' ? ['a#1 7'] : [], $this->gh->merged);
        self::assertSame($action === 'update-branch' ? ['a#1 7'] : [], $this->gh->updated);
    }

    public function testBusyRunDoesNotSkipMergeOfOtherPr(): void
    {
        $states = ['a#1' => self::pr('a#1', ['prNumber' => 7]), 'a#2' => self::pr('a#2', ['prNumber' => 8])];
        // a review run is pending for a#1: a#1 is skipped, a#2 is merged
        self::assertTrue($this->runGates($states, busy: true, reviewPrs: ['a#7']));
        self::assertSame(['a#2 8'], $this->gh->merged);
    }

    public function testReviewRunOfThePrSkipsItsMerge(): void
    {
        $states = ['a#1' => self::pr('a#1')];
        self::assertFalse($this->runGates($states, busy: true, reviewPrs: ['a#7']));
        self::assertSame([], $this->gh->merged);
    }

    public function testPendingTickSkipsNoTask(): void
    {
        $states = ['a#1' => self::pr('a#1')];
        // a tick carries no PR, so the review set is empty
        self::assertTrue($this->runGates($states, busy: true, reviewPrs: []));
        self::assertSame(['a#1 7'], $this->gh->merged);
    }

    public function testFinalCheckIsNotEnqueuedWhileRunPending(): void
    {
        $this->db->epicSetReleaseStatus('epic', 'a', '1.2.0', 'done');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['prState' => 'merged'])];
        self::assertFalse($this->runGates($states, busy: true));
        self::assertSame([], $this->enqueued);

        self::assertTrue($this->runGates($states));
        self::assertSame([['epic', 'final']], $this->enqueued);
    }

    #[DataProvider('openroninLabels')]
    public function testOpenroninLabelHoldsMerge(string $label): void
    {
        $s = self::pr('a#1', ['labels' => [$label]]);
        $decision = MergeGate::decide($s, 'h1', new Locale('en'));
        self::assertSame('wait', $decision['action']);
        self::assertSame('openronin has an open question or is working on the task (an openronin label is set)', $decision['reason']);

        $states = ['a#1' => $s];
        $this->runGates($states);
        self::assertSame([], $this->gh->merged);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openroninLabels(): array
    {
        return ['awaiting-answer' => ['openronin:awaiting-answer'], 'in-progress' => ['openronin:in-progress']];
    }

    public function testConflictCommentThenBlockedWhenNotResolved(): void
    {
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false'])];
        self::assertTrue($this->runGates($states));
        self::assertSame(['a#2 7'], $this->gh->commented);

        // same head: we wait, we do not comment a second time
        $this->runGates($states);
        self::assertSame(['a#2 7'], $this->gh->commented);
        self::assertSame([], $this->notified);

        // openronin made an iteration (head changed), the conflict remains
        $states['a#2'] = self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false', 'head' => 'h9']);
        $this->runGates($states);
        $task = array_values(array_filter($this->db->epicTasks('epic'), static fn (array $t): bool => $t['task'] === 'a#2'))[0];
        self::assertSame('blocked', $task['status']);
        self::assertSame(1, $this->notifiedCount());
    }

    public function testConflictCommentAndNoticeAreEnglishByDefault(): void
    {
        $this->locale = null;
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false'])];
        $this->runGates($states);
        self::assertCount(1, $this->gh->commentBodies);
        self::assertStringStartsWith(
            'After a neighbouring epic PR was merged, this PR conflicts with `master`. Merge `master` into the branch and resolve the conflict.'."\n\n",
            $this->gh->commentBodies[0],
        );

        $states['a#2'] = self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false', 'head' => 'h9']);
        $this->runGates($states);
        self::assertSame('a#2: the conflict with master was not resolved within one iteration — the task is stopped (blocked). Resolve the conflict manually?', $this->notified[0][1]);
    }

    public function testRussianConflictCommentIsUnchanged(): void
    {
        // Deliberately Cyrillic: the Russian comment text must stay unchanged
        $this->locale = 'ru';
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false'])];
        $this->runGates($states);
        self::assertStringStartsWith('После мержа соседнего PR эпика здесь конфликт с `master`. Влей `master` в ветку и разреши конфликт.'."\n\n", $this->gh->commentBodies[0]);
    }

    public function testMergeGateWaitReasonFollowsLocale(): void
    {
        // Deliberately Cyrillic: the ru expectation checks that the passed locale is used
        $s = self::pr('a#1', ['epicVerdict' => null]);
        self::assertSame('the last epic review is not APPROVE', MergeGate::decide($s, 'h1')['reason']);
        self::assertSame('последнее ревью эпика не APPROVE', MergeGate::decide($s, 'h1', new Locale('ru'))['reason']);
    }

    public function testMasterCiRedAfterEpicMergeRerunsOnceAndPausesRepoWithoutBlocking(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        self::assertTrue($this->runGates($states, ['a' => 'FAILURE']));
        self::assertSame(['a 11'], $this->gh->reran);
        self::assertSame([], $this->blocked);
        self::assertSame([], $this->notified);
        self::assertSame([], $this->gh->merged);
        self::assertSame([], $this->gh->released);

        // CI is still red (old result), the mark is set: no second restart, the pause holds.
        $this->now += 30;
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(['a 11'], $this->gh->reran);
        self::assertSame([], $this->gh->merged);
    }

    public function testRerunRestartsAllFailedWorkflows(): void
    {
        $this->gh->failedRuns['a'] = [
            ['id' => 11, 'url' => 'u1', 'workflow' => 'CI'],
            ['id' => 12, 'url' => 'u2', 'workflow' => 'Lint'],
        ];
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(['a 11', 'a 12'], $this->gh->reran);
    }

    public function testPendingWithRerunMarkKeepsMergesAndReleasesStopped(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->runGates($states, ['a' => 'PENDING']);
        $this->runGates($states, ['a' => 'PENDING']);
        self::assertSame([], $this->gh->merged);
        self::assertSame([], $this->gh->released);
        self::assertSame([], $this->blocked);
        self::assertSame(['a 11'], $this->gh->reran);
    }

    public function testPendingWithoutRerunMarkDoesNotPause(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        $this->runGates($states, ['a' => 'PENDING']);
        self::assertSame(['a#2 7'], $this->gh->merged);
        self::assertSame([], $this->gh->reran);
    }

    public function testGreenAfterRerunIsFlakyAndLiftsPause(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->runGates($states, ['a' => 'PENDING']);
        self::assertSame(['a' => ['flaky' => false, 'broken' => false]], $this->events(['a' => 'PENDING']));

        self::assertSame(['a' => ['flaky' => true, 'broken' => false]], $this->events(['a' => 'SUCCESS']));
        $this->runGates($states, ['a' => 'SUCCESS']);
        self::assertSame(['a#2 7'], $this->gh->merged);
        self::assertSame(['a 11'], $this->gh->reran);
        self::assertSame([], $this->blocked);
        // The event stays visible in the fingerprint while the SHA is the same: no repeated "raise".
        self::assertSame(['a' => ['flaky' => true, 'broken' => false]], $this->events(['a' => 'SUCCESS']));
    }

    public function testRedAfterRerunIsBrokenOtherReposKeepMerging(): void
    {
        $this->db->epicApplySignal('epic', [['task' => 'b#1', 'after' => []]], null, false);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2'), 'b#1' => self::pr('b#1')];
        $this->runGates($states, ['a' => 'FAILURE', 'b' => 'SUCCESS']);
        self::assertSame(['a' => ['flaky' => false, 'broken' => false], 'b' => ['flaky' => false, 'broken' => false]], $this->events(['a' => 'FAILURE', 'b' => 'SUCCESS']));

        $this->now += Gates::RERUN_GRACE;
        self::assertSame(['a' => ['flaky' => false, 'broken' => true], 'b' => ['flaky' => false, 'broken' => false]], $this->events(['a' => 'FAILURE', 'b' => 'SUCCESS']));
        $this->runGates($states, ['a' => 'FAILURE', 'b' => 'SUCCESS']);

        self::assertSame(['b#1 7'], $this->gh->merged);
        self::assertSame([], $this->gh->released);
        self::assertSame([], $this->blocked);
        self::assertSame(['a 11'], $this->gh->reran);
        self::assertSame(['a' => ['flaky' => false, 'broken' => true], 'b' => ['flaky' => false, 'broken' => false]], $this->events(['a' => 'FAILURE', 'b' => 'SUCCESS']));

        // A green master lifts the pause and the breakage record.
        $this->runGates($states, ['a' => 'SUCCESS', 'b' => 'SUCCESS']);
        self::assertSame(['b#1 7', 'a#2 7'], $this->gh->merged);
    }

    public function testBrokenMasterBlocksEpicAfterRedHours(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE'], true);
        self::assertSame([], $this->blocked);

        $this->now += 6 * 3600 - 1;
        $this->runGates($states, ['a' => 'FAILURE'], true);
        self::assertSame([], $this->blocked);

        ++$this->now;
        $this->runGates($states, ['a' => 'FAILURE'], true);
        self::assertSame(1, $this->blockedCount());
        self::assertSame('master of a has been red for 6 h, the fix did not help', $this->blockedReason());
    }

    public function testBrokenMasterBlocksEpicWithoutFixTaskAfterTwoTicks(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE'], true);
        $this->db->epicTickQueued('epic', $this->now, 0);
        $this->now += 1800;
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->blocked);

        $this->db->epicTickQueued('epic', $this->now, 0);
        $this->runGates($states, ['a' => 'FAILURE'], true);
        self::assertSame([], $this->blocked, 'the second tick is still running');
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(1, $this->blockedCount());
        self::assertStringContainsString('no fix task appeared', $this->blockedReason());
    }

    public function testFixTaskInSameRepoStopsTickCheck(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->db->epicTickQueued('epic', $this->now, 0);
        $this->db->epicApplySignal('epic', [['task' => 'a#9', 'after' => []]], null, false);
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->db->epicTickQueued('epic', $this->now, 0);
        $this->db->epicTickQueued('epic', $this->now, 0);
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->blocked);
    }

    public function testFixTaskInBrokenRepoIsMergedOthersStay(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->db->epicApplySignal('epic', [['task' => 'a#9', 'after' => []]], null, false);
        $states['a#2'] = self::pr('a#2');
        $states['a#9'] = self::pr('a#9');
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(['a#9 7'], $this->gh->merged);
    }

    public function testRerunFailureDoesNotBreakPollAndFallsToBroken(): void
    {
        $this->gh->rerunFails = true;
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->gh->merged);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(['a' => ['flaky' => false, 'broken' => true]], $this->events(['a' => 'FAILURE']));
        self::assertSame([], $this->gh->merged);
    }

    public function testNewPendingShaKeepsBrokenPauseUntilGreen(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2')];
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->now += Gates::RERUN_GRACE;
        $this->runGates($states, ['a' => 'FAILURE']);
        $this->gh->masterOid['a'] = 'sha-new';
        $this->runGates($states, ['a' => 'PENDING']);
        self::assertSame([], $this->gh->merged);
        self::assertSame([], $this->gh->released);

        $this->now += 6 * 3600;
        $this->runGates($states, ['a' => 'PENDING'], true);
        self::assertSame(1, $this->blockedCount());
    }

    public function testRedMasterNotCausedByEpicDoesNotBlock(): void
    {
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->blocked);
    }

    public function testGreenMasterClearsMergeMark(): void
    {
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'SUCCESS']);
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->blocked);
    }

    private function blockedReason(): string
    {
        return $this->blocked[0][1] ?? '';
    }

    private function blockedCount(): int
    {
        return count($this->blocked);
    }

    /**
     * @param array<string, string> $master
     *
     * @return array<string, array{flaky: bool, broken: bool}>
     */
    private function events(array $master): array
    {
        foreach ($master as $repo => $state) {
            $this->gh->master[$repo] = $state;
        }
        $gates = new Gates($this->db, $this->gh, static fn (): bool => true, static function (): void {
        }, null, 6, fn (): int => $this->now, self::owners(), new Locale('ru'));

        return $gates->masterEvents('epic', array_map(fn (string $repo): array => $this->gh->masterCi($repo), array_combine(array_keys($master), array_keys($master))));
    }

    public function testOnlyOneMergePerRepoPerPass(): void
    {
        $states = ['a#1' => self::pr('a#1'), 'a#2' => self::pr('a#2')];
        $this->runGates($states);
        self::assertSame(['a#1 7'], $this->gh->merged);
        $this->runGates($states);
        self::assertSame(['a#1 7', 'a#2 7'], $this->gh->merged);
    }

    public function testMergeFailureBlocksOnlyThatTask(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1'), 'a#2' => self::pr('a#2')];
        $this->runGates($states);
        $this->runGates($states);
        $blocked = static fn (array $t): bool => $t['status'] === 'blocked';
        self::assertCount(2, array_filter($this->db->epicTasks('epic'), $blocked));
        self::assertSame(2, $this->notifiedCount());
        // second pass: blocked ones are untouched, no more messages
        $this->runGates($states);
        self::assertSame(2, $this->notifiedCount());
    }

    private function mergeFailures(string $task = 'a#1'): string
    {
        return (string) (array_column($this->db->epicTasks('epic'), 'counters', 'task')[$task]['merge_failures'] ?? '');
    }

    public function testFirstMergeFailureRetriesAndSecondBlocks(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);
        self::assertSame('1', $this->mergeFailures());
        self::assertSame(0, $this->notifiedCount());

        $this->runGates($states);
        self::assertSame('blocked', $this->db->epicTasks('epic')[0]['status']);
        self::assertSame(1, $this->notifiedCount());
        self::assertStringContainsString('a#1', $this->notified[0][1]);
    }

    public function testMergeSuccessResetsFailureCounter(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        $this->gh->mergeFails = false;
        $this->runGates($states);
        self::assertSame(['a#1 7'], $this->gh->merged);
        self::assertSame('0', $this->mergeFailures());

        $this->gh->mergeFails = true;
        $states = ['a#2' => self::pr('a#2')];
        $this->runGates($states);
        self::assertSame('open', array_column($this->db->epicTasks('epic'), 'status', 'task')['a#2']);
    }

    public function testWaitPassResetsFailureCounter(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        $states = ['a#1' => self::pr('a#1', ['checks' => 'pending'])];
        $this->runGates($states);
        self::assertSame('0', $this->mergeFailures());

        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);
        self::assertSame(0, $this->notifiedCount());
    }

    public function testResumeResetsFailureCounter(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);
        self::assertSame('1', $this->mergeFailures());
        $this->db->epicResume('epic');
        self::assertSame('0', $this->mergeFailures());
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);
    }

    public function testMergeThatSucceededBehindErrorIsWatched(): void
    {
        $this->gh->mergeFails = true;
        $states = ['a#1' => self::pr('a#1')];
        $this->runGates($states);

        // The PR turns out to be merged: marked unverified, no retry.
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame([], $this->gh->merged);
        self::assertSame('0', $this->mergeFailures());
        $counters = array_column($this->db->epicTasks('epic'), 'counters', 'task')['a#1'];
        self::assertSame('1', $counters['merge_unverified']);

        // From the next pass the master watch covers it, as after a successful merge().
        $this->runGates($states, ['a' => 'FAILURE']);
        self::assertSame(['a 11'], $this->gh->reran);
    }

    public function testMasterCiGreenDoesNotBlock(): void
    {
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states, ['a' => 'SUCCESS']);
        self::assertSame([], $this->blocked);
    }

    /**
     * @param list<string> $tags
     */
    private function release(string $merged, ?string $changelog, array $tags = []): void
    {
        $states = ['a#1' => self::pr('a#1', ['prState' => $merged, 'epicVerdict' => null])];
        if ($changelog !== null) {
            $this->gh->changelogs['a'] = $changelog;
        }
        $this->gh->tags = $tags;
        $this->runGates($states);
    }

    public function testReleaseCreated(): void
    {
        $this->release('merged', "# Changelog\n\n## [Unreleased]\n\n## [1.2.0] - 2026-01-01\n");
        self::assertSame(['a@1.2.0'], $this->gh->released);
    }

    public function testNoReleaseWhenTaskNotMerged(): void
    {
        $this->release('open', "## 1.2.0\n");
        self::assertSame([], $this->gh->released);
    }

    public function testNoReleaseWhenTagExists(): void
    {
        $this->release('merged', "## 1.2.0\n", ['a@1.2.0']);
        self::assertSame([], $this->gh->released);
        self::assertSame([], $this->notified);
    }

    public function testReleaseParkedOnVersionMismatchAskedOnce(): void
    {
        $this->release('merged', "## 0.26.5\n");
        self::assertSame([], $this->gh->released);
        self::assertSame(1, $this->notifiedCount());

        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged'])];
        $this->runGates($states);
        self::assertSame(1, $this->notifiedCount());

        $this->gh->changelogs['a'] = "## 1.2.0\n";
        $this->runGates($states);
        self::assertSame(['a@1.2.0'], $this->gh->released);
    }

    public function testThirdEpicRequestChangesBlocksTask(): void
    {
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 2])];
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);

        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 3])];
        $this->runGates($states);
        self::assertSame('blocked', $this->db->epicTasks('epic')[0]['status']);
        self::assertSame(1, $this->notifiedCount());
    }

    public function testResumeResetsReviewCeilingCount(): void
    {
        $at = [time() - 300, time() - 200, time() - 100];
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 3, 'epicChangesRequestedAt' => $at])];
        $this->runGates($states);
        self::assertSame('blocked', $this->db->epicTasks('epic')[0]['status']);

        $this->db->epicResume('epic');
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status'], 'old reviews are not counted after resume');

        $later = [...$at, time() + 10, time() + 20];
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 5, 'epicChangesRequestedAt' => $later])];
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);

        $later[] = time() + 30;
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 6, 'epicChangesRequestedAt' => $later])];
        $this->runGates($states);
        self::assertSame('blocked', $this->db->epicTasks('epic')[0]['status']);
    }

    public function testResumeCountsReviewsWithUnknownTimeAsFresh(): void
    {
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => 'CHANGES_REQUESTED', 'epicChangesRequested' => 3, 'epicChangesRequestedAt' => [0, 0, 0]])];
        $this->db->epicSetTaskCounter('epic', 'a#1', 'epic_changes_since', (string) time());
        $this->runGates($states);
        self::assertSame('blocked', $this->db->epicTasks('epic')[0]['status']);
    }

    public function testResumeClearsConflictHeadSoAfterMergeGateRestarts(): void
    {
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false'])];
        $this->runGates($states);
        $states['a#2'] = self::pr('a#2', ['epicVerdict' => null, 'mergeable' => 'false', 'head' => 'h9']);
        $this->runGates($states);
        $statuses = array_column($this->db->epicTasks('epic'), 'status', 'task');
        self::assertSame('blocked', $statuses['a#2']);

        self::assertSame(['a#2'], $this->db->epicResume('epic'));
        $this->runGates($states);
        $statuses = array_column($this->db->epicTasks('epic'), 'status', 'task');
        self::assertSame('open', $statuses['a#2']);
        self::assertSame(['a#2 7', 'a#2 7'], $this->gh->commented);
    }

    public function testManualReviewsDoNotCount(): void
    {
        // Manual reviews without the GhClient marker are not reflected in epicVerdict/epicChangesRequested.
        $states = ['a#1' => self::pr('a#1', ['epicVerdict' => null, 'epicChangesRequested' => 0])];
        $this->runGates($states);
        self::assertSame('open', $this->db->epicTasks('epic')[0]['status']);
    }

    public function testFinalCheckStartedOncePerRoundAndThirdTailsRoundRefused(): void
    {
        $this->gh->tags = ['a@1.2.0'];
        $states = ['a#1' => self::pr('a#1', ['prState' => 'merged']), 'a#2' => self::pr('a#2', ['prState' => 'merged'])];
        $this->runGates($states);
        self::assertSame([['epic', 'final']], $this->enqueued);
        $queueId = $this->queueFinal();
        $this->runGates($states);
        self::assertSame([['epic', 'final']], $this->enqueued);
        self::assertSame(0, $this->db->epicFinalRetries('epic'));
        $this->db->queueRemove($queueId);

        // round 1: two signals in one round — one count
        self::assertFalse($this->db->epicApplySignal('epic', [['task' => 'a#11', 'after' => []]], null, false));
        self::assertFalse($this->db->epicApplySignal('epic', [['task' => 'a#12', 'after' => []]], null, false));
        self::assertSame(1, $this->db->epicFinalCounters('epic')['tails_rounds']);
        $states['a#11'] = self::pr('a#11', ['prState' => 'merged']);
        $states['a#12'] = self::pr('a#12', ['prState' => 'merged']);
        $this->runGates($states);
        self::assertSame(2, $this->enqueuedCount());

        // round 2
        self::assertFalse($this->db->epicApplySignal('epic', [['task' => 'a#21', 'after' => []]], null, false));
        self::assertSame(2, $this->db->epicFinalCounters('epic')['tails_rounds']);
        $states['a#21'] = self::pr('a#21', ['prState' => 'merged']);
        $this->runGates($states);
        self::assertSame(3, $this->enqueuedCount());

        // round 3 discarded
        self::assertTrue($this->db->epicApplySignal('epic', [['task' => 'a#31', 'after' => []]], null, false));
        self::assertCount(5, $this->db->epicTasks('epic'));
    }

    public function testChangelogVersion(): void
    {
        self::assertSame('1.2.0', Gates::changelogVersion("# Changelog\n## [Unreleased]\n## [1.2.0] - 2026\n## [1.1.0]\n"));
        self::assertSame('0.27.0', Gates::changelogVersion("## v0.27.0\n"));
        self::assertNull(Gates::changelogVersion("nothing\n"));
    }

    public function testOrchestratorMergesThroughPoll(): void
    {
        $this->gh->states['a#1'] = self::pr('a#1');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $c = new Orchestrator($this->db, $this->gh, fn (): bool => true, fn (): null => null, owners: self::owners());
        $c->poll();
        self::assertSame(['a#1 7'], $this->gh->merged);
    }

    private static function owners(): GithubOwners
    {
        return new GithubOwners(['anime-db', 'gpslab']);
    }
}
