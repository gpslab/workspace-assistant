<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\EditStatus;
use GpsLab\WorkspaceAssistant\Epic\Card;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\Forge;
use GpsLab\WorkspaceAssistant\Epic\Gates;
use GpsLab\WorkspaceAssistant\Epic\Orchestrator;
use GpsLab\WorkspaceAssistant\Epic\TaskState;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

final class FakeGitHub implements Forge
{
    /** @var array<string, TaskState> */
    public array $states = [];
    /** @var array<string, ?string> task => coverage_head passed to the last taskState() */
    public array $coverageHeads = [];
    /** @var array<string, string> */
    public array $master = [];
    /** @var array<string, string> repo => default branch head SHA */
    public array $masterOid = [];
    /** @var array<string, list<array{id: int, url: string, workflow: string}>> */
    public array $failedRuns = [];
    public bool $rerunFails = false;
    /** @var list<string> repo id */
    public array $reran = [];
    /** @var list<string> */
    public array $labelled = [];
    /** @var list<string> */
    public array $readied = [];

    /** @var list<string> */
    public array $merged = [];
    /** @var list<string> */
    public array $updated = [];
    /** @var list<string> */
    public array $commented = [];
    /** @var list<string> */
    public array $commentBodies = [];
    /** @var list<string> */
    public array $released = [];
    /** @var array<string, string> repo => CHANGELOG.md text */
    public array $changelogs = [];
    /** @var list<string> repo@version */
    public array $tags = [];

    /** @var array<string, list<array{id: string, source: string, path: ?string, line: ?int, body: string}>> pr => cold reviewer findings */
    public array $cold = [];

    public bool $fail = false;
    public bool $mergeFails = false;

    /** @var list<string> */
    public array $failCold = [];
    /** @var list<string> */
    public array $coldAsked = [];

    /** @var array<int, list<string>> number => repositories with an open PR */
    public array $openPrs = [];
    public bool $failOpenPrs = false;
    /** @var list<list<int>> */
    public array $openPrCalls = [];

    public function openPrRepos(array $numbers): array
    {
        $this->openPrCalls[] = $numbers;
        if ($this->failOpenPrs) {
            throw new \RuntimeException('gh: network');
        }
        $out = [];
        foreach ($numbers as $n) {
            $out[$n] = $this->openPrs[$n] ?? [];
        }

        return $out;
    }

    /** @var array<string, string> */
    public array $heads = [];

    public function prHeadOid(string $pr): string
    {
        return $this->heads[$pr] ?? str_repeat('a', 40);
    }

    public function coldFindings(string $pr): array
    {
        $this->coldAsked[] = $pr;
        if (in_array($pr, $this->failCold, true)) {
            throw new \RuntimeException('gh: not found');
        }

        return $this->cold[$pr] ?? [];
    }

    public function taskState(string $task, string $marker, ?string $coverageHead = null): TaskState
    {
        $this->coverageHeads[$task] = $coverageHead;
        if ($this->fail) {
            throw new \RuntimeException('gh: bad credentials');
        }

        return $this->states[$task] ?? new TaskState($task);
    }

    public function masterCi(string $repo): array
    {
        return ['state' => $this->master[$repo] ?? 'SUCCESS', 'oid' => $this->masterOid[$repo] ?? 'sha-'.$repo, 'branch' => 'master'];
    }

    public function failedRuns(string $repo, string $branch, string $sha): array
    {
        return $this->failedRuns[$repo] ?? [['id' => 11, 'url' => "https://github.com/anime-db/{$repo}/actions/runs/11", 'workflow' => 'CI']];
    }

    public function rerunFailed(string $repo, int $id): void
    {
        if ($this->rerunFails) {
            throw new \RuntimeException('gh run rerun failed');
        }
        $this->reran[] = $repo.' '.$id;
    }

    public function addLabel(string $task, string $label): void
    {
        $this->labelled[] = $task.' '.$label;
        $s = $this->states[$task] ?? new TaskState($task);
        $this->states[$task] = $s->withLabel($label);
    }

    public function merge(string $task, int $pr): void
    {
        if ($this->mergeFails) {
            throw new \RuntimeException('gh pr merge: protected branch');
        }
        $this->merged[] = $task.' '.$pr;
    }

    public function updateBranch(string $task, int $pr): void
    {
        $this->updated[] = $task.' '.$pr;
    }

    public function commentPr(string $task, int $pr, string $body): void
    {
        $this->commented[] = $task.' '.$pr;
        $this->commentBodies[] = $body;
    }

    public function changelog(string $repo): ?string
    {
        return $this->changelogs[$repo] ?? null;
    }

    public function tagExists(string $repo, string $version): bool
    {
        return in_array($repo.'@'.$version, $this->tags, true);
    }

    public function createRelease(string $repo, string $version): void
    {
        $this->released[] = $repo.'@'.$version;
    }

    public function markReady(string $task, int $pr): void
    {
        $this->readied[] = $task.' '.$pr;
        $this->states[$task] = ($this->states[$task] ?? new TaskState($task))->withPrState(TaskState::OPEN);
    }
}

#[CoversClass(Orchestrator::class)]
#[CoversClass(Card::class)]
final class OrchestratorTest extends TestCase
{
    private const int T0 = 1_000_000;

    private string $dir;
    private Database $db;
    private FakeGitHub $gh;
    private int $now = self::T0;
    /** @var list<array{string, string, ?string}> */
    private array $enqueued = [];
    /** @var list<array{string, string}> */
    private array $blocked = [];
    private bool $enqueueOk = true;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tgc_'.uniqid('', true);
        mkdir($this->dir);
        $this->db = new Database($this->dir);
        $this->gh = new FakeGitHub();
        $this->db->epicCreate('epic', 1, 2, [['task' => 'a#1', 'after' => []], ['task' => 'b#2', 'after' => ['a#1']]], []);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @var list<array{string, string}> */
    private array $notified = [];

    private function orchestrator(int $tick = 1800, int $stall = 3, int $quiet = 600, int $answer = 7200, int $budget = 20, ?Card $card = null, ?string $locale = 'en'): Orchestrator
    {
        return new Orchestrator(
            $this->db,
            $this->gh,
            function (string $slug, string $run, ?string $pr): bool {
                $this->enqueued[] = [$slug, $run, $pr];

                return $this->enqueueOk;
            },
            function (string $slug, string $reason): void {
                $this->blocked[] = [$slug, $reason];
                $this->db->epicBlock($slug, $reason);
            },
            $tick,
            $stall,
            $quiet,
            $answer,
            fn (): int => $this->now,
            $budget,
            function (string $slug, string $text): void {
                $this->notified[] = [$slug, $text];
            },
            $card,
            $this->dir,
            ...['owners' => new ForgeOwners(['anime-db', 'gpslab'])] + ($locale === null ? [] : ['locale' => new Locale($locale)]),
        );
    }

    /**
     * @return array{fingerprint:?string, attention:?string, stale_ticks:int, card_message_id:int, last_poll_at:int}
     */
    private function epic(): array
    {
        return $this->db->epicGet('epic') ?? self::fail('no epic');
    }

    private function reviewCount(): int
    {
        return count(array_filter($this->enqueued, static fn (array $e): bool => $e[1] === 'review'));
    }

    private function notifiedCount(): int
    {
        return count($this->notified);
    }

    private function blockedReason(): string
    {
        return $this->blocked[0][1] ?? '';
    }

    private function blockedCount(): int
    {
        return count($this->blocked);
    }

    /** First poll (no fingerprint → tick) and "the run finished": the fingerprint is remembered. */
    private function settle(Orchestrator $c): void
    {
        $c->poll();
        $c->rememberAfterRun('epic');
        $c->poll();
        $this->enqueued = [];
    }

    /**
     * @param list<string> $labels
     */
    private function pr(string $task, string $state, string $checks = 'green', array $labels = [], ?bool $cold = null, int $age = 3600, string $head = 'h1', bool $reviewed = false): TaskState
    {
        return new TaskState($task, $labels, $state, 5, $head, $checks, 'true', 1, 0, $this->now - $age, null, $reviewed, $cold);
    }

    public function testFirstPollQueuesChangeTick(): void
    {
        $this->orchestrator()->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
    }

    public function testNoChangeNoTick(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function state(array $o = []): TaskState
    {
        return new TaskState(
            'a#1',
            $o['labels'] ?? [],
            $o['pr'] ?? 'open',
            5,
            $o['head'] ?? 'h1',
            $o['checks'] ?? 'green',
            $o['mergeable'] ?? 'true',
            $o['comments'] ?? 1,
            $o['reviews'] ?? 0,
            foreignComments: $o['foreign'] ?? 0,
            issueOpen: $o['issueOpen'] ?? true,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function progress(): array
    {
        return [
            'ci pending to pass' => [['checks' => 'green']],
            'draft off' => [['pr' => 'open']],
            'head' => [['head' => 'zzz']],
            'mergeable' => [['mergeable' => 'false']],
            'in-progress label' => [['labels' => ['openronin:in-progress']]],
            'other label' => [['labels' => ['x']]],
            'comments' => [['comments' => 9]],
            'reviews' => [['reviews' => 3]],
        ];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('progress')]
    public function testProgressResetsStallWithoutTick(array $change): void
    {
        $base = ['pr' => 'draft', 'checks' => 'pending'];
        $this->gh->states['a#1'] = self::state($base);
        $c = $this->orchestrator(tick: 100);
        $this->settle($c);
        $this->now += 100;
        $c->poll(); // safety-net timer tick: stall 1
        self::assertSame(1, $this->epic()['stale_ticks']);
        $this->enqueued = [];
        $this->now += 10;
        $this->gh->states['a#1'] = self::state([...$base, ...$change]);
        $c->poll();
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->epic()['stale_ticks']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ciRecovery(): array
    {
        return ['failed to pending' => ['pending'], 'failed to green' => ['green']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ciRecovery')]
    public function testCiLeavingRedIsProgressNotAttention(string $checks): void
    {
        $this->gh->states['a#1'] = self::state(['checks' => 'failed']);
        $c = $this->orchestrator(tick: 100);
        $this->settle($c);
        $this->now += 100;
        $c->poll(); // safety-net timer tick: stall 1
        self::assertSame(1, $this->epic()['stale_ticks']);
        $this->enqueued = [];
        $this->now += 10;
        $this->gh->states['a#1'] = self::state(['checks' => $checks]);
        $c->poll();
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->epic()['stale_ticks']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function attention(): array
    {
        return [
            'awaiting-answer' => [['labels' => ['openronin:awaiting-answer']]],
            'awaiting-action' => [['labels' => ['openronin:awaiting-action']]],
            'ci red' => [['checks' => 'failed']],
            'foreign comment' => [['comments' => 2, 'foreign' => 1]],
            'issue closed without pr' => [['pr' => 'none', 'issueOpen' => false]],
            'pr closed without merge' => [['pr' => 'closed']],
        ];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attention')]
    public function testAttentionChangeQueuesTick(array $change): void
    {
        $this->gh->states['a#1'] = self::state();
        $c = $this->orchestrator();
        $this->settle($c);
        $this->now += 60;
        $this->gh->states['a#1'] = self::state($change);
        $c->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
    }

    public function testMasterCiDoesNotQueueTick(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        $this->now += 60;
        $this->gh->master['a'] = 'FAILURE';
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    private function redAfterEpicMerge(Orchestrator $c): void
    {
        $this->settle($c);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'merge_unverified', '1');
        $this->now += 60;
        $this->gh->master['a'] = 'FAILURE';
        $c->poll();
    }

    public function testRedMasterAfterEpicMergeRerunsOnceWithoutBlockOrTick(): void
    {
        $c = $this->orchestrator();
        $this->redAfterEpicMerge($c);
        $this->now += 60;
        $c->poll();
        $this->gh->master['a'] = 'PENDING';
        $this->now += 60;
        $c->poll();
        self::assertSame(['a 11'], $this->gh->reran);
        self::assertSame([], $this->blocked);
        self::assertSame([], $this->enqueued);
    }

    public function testGreenAfterRerunQueuesFlakyTickOnce(): void
    {
        $c = $this->orchestrator();
        $this->redAfterEpicMerge($c);
        $this->gh->master['a'] = 'SUCCESS';
        $this->now += 60;
        $c->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
        self::assertSame([], $this->blocked);

        $this->enqueued = [];
        $c->rememberAfterRun('epic');
        $this->now += 60;
        $c->poll();
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
        self::assertStringContainsString('"master:a":{"flaky":true,"broken":false}', (string) $this->epic()['attention']);
    }

    public function testRedAfterRerunQueuesBrokenTickAndStallDoesNotCount(): void
    {
        // Deliberately Cyrillic: proves the passed locale is used for the block reason (en is the built-in default)
        $c = $this->orchestrator(locale: 'ru');
        $this->redAfterEpicMerge($c);
        $this->now += Gates::RERUN_GRACE;
        $c->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
        self::assertSame([], $this->blocked);
        $c->rememberAfterRun('epic');
        $c->poll();
        self::assertStringContainsString('"master:a":{"flaky":false,"broken":true}', (string) $this->epic()['attention']);

        // The tick created a fix task. Further timer ticks do not accumulate stall (stall = 3).
        $this->db->epicApplySignal('epic', [['task' => 'a#9', 'after' => []]], null, false);
        for ($i = 0; $i < 5; ++$i) {
            $this->now += 1800;
            $c->poll();
        }
        self::assertSame([], $this->blocked);
        self::assertSame(0, $this->epic()['stale_ticks']);

        $this->now += 6 * 3600;
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertSame('master a красный 8 ч, исправление не помогло', $this->blockedReason());
    }

    public function testBrokenMasterWithoutFixTaskBlocksAfterTwoTicks(): void
    {
        $c = $this->orchestrator();
        $this->redAfterEpicMerge($c);
        $this->now += Gates::RERUN_GRACE;
        $c->poll();
        $this->now += 1800;
        $c->poll();
        $this->now += 1800;
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertStringContainsString('no fix task appeared', $this->blockedReason());
    }

    public function testClosedIssueWithMergedPrIsNotAttention(): void
    {
        $this->gh->states['a#1'] = self::state();
        $c = $this->orchestrator();
        $this->settle($c);
        $this->gh->states['a#1'] = self::state(['pr' => 'merged', 'issueOpen' => false]);
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testLegacyEpicWithoutAttentionStoresItSilently(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        (new \PDO('sqlite:'.$this->dir.'/state.sqlite'))->exec("UPDATE epics SET attention = NULL WHERE slug = 'epic'");
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
        self::assertNotNull($this->epic()['attention']);
    }

    public function testAddedTaskParticipates(): void
    {
        $c = $this->orchestrator();
        $this->db->epicApplySignal('epic', [['task' => 'c#3', 'after' => []]], null, false);
        $this->settle($c);
        $this->gh->states['c#3'] = new TaskState('c#3', ['openronin:awaiting-answer']);
        $c->poll();
        self::assertCount(1, $this->enqueued);
    }

    public function testNullMergeableIsNotNoConflicts(): void
    {
        $a = new TaskState('a#1', [], 'open', 5, 'h', 'green', TaskState::MERGEABLE_UNKNOWN);
        $b = new TaskState('a#1', [], 'open', 5, 'h', 'green', TaskState::MERGEABLE_YES);
        self::assertNotSame($a->fingerprintData(), $b->fingerprintData());
        self::assertSame('unknown', $a->fingerprintData()['mergeable']);
    }

    public function testFingerprintStoredAfterRunNotBefore(): void
    {
        $c = $this->orchestrator();
        $c->poll();
        self::assertNull($this->epic()['fingerprint']);
        // During the run the GitHub state changes (the run's own actions).
        $this->gh->states['a#1'] = new TaskState('a#1', ['openronin:do-it']);
        $c->rememberAfterRun('epic');
        $c->poll();
        $this->enqueued = [];
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testExternalChangeDuringRunQueuesTick(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        $this->now += 60;
        $c->poll();
        // The run is in progress; meanwhile the PR's CI turned red.
        $this->gh->states['a#1'] = new TaskState('a#1', [], 'open', 5, 'h2', 'failed');
        $c->rememberAfterRun('epic');
        $this->now += 60;
        $c->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
        self::assertSame(0, $this->epic()['stale_ticks']);
    }

    public function testForeignCommentDuringRunQueuesTick(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        $c->poll();
        $this->gh->states['a#1'] = new TaskState('a#1', [], comments: 1, foreignComments: 1);
        $c->rememberAfterRun('epic');
        $c->poll();
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
    }

    public function testOwnActionDoesNotWakeNextTick(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->db->epicApplySignal('epic', [], ['b#2'], false);
        $c = $this->orchestrator();
        $this->settle($c);
        $this->db->epicApplySignal('epic', [], [], false);
        $c->poll();
        self::assertSame(['b#2 openronin:do-it'], $this->gh->labelled);
        self::assertSame([], $this->enqueued);
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testHotReviewForNewHeadAfterColdReview(): void
    {
        $c = $this->orchestrator();
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true, head: 'h1');
        $c->poll();
        self::assertSame(1, $this->reviewCount());
        // openronin pushed fixes: the cold reviewer will not come on h2 (no synchronize).
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true, head: 'h2');
        $this->now += 60;
        $c->poll();
        self::assertSame(2, $this->reviewCount());
    }

    public function testAwaitingAnswerWithoutEpicCommentDoesNotBlock(): void
    {
        $this->gh->states['a#1'] = new TaskState('a#1', ['openronin:awaiting-answer'], lastEpicComment: null);
        $this->now += 1_000_000;
        $this->orchestrator(answer: 7200)->poll();
        self::assertSame(0, $this->blockedCount());
    }

    public function testPollBudgetRotatesEpics(): void
    {
        $this->db->epicCreate('epic2', 1, 3, [['task' => 'z#9', 'after' => []]], []);
        $c = $this->orchestrator(budget: 0);
        $c->poll();
        self::assertCount(1, $this->enqueued);
        $c->poll();
        self::assertEqualsCanonicalizing([['epic', 'tick', null], ['epic2', 'tick', null]], $this->enqueued);
    }

    public function testRepeatedPollFailuresNotifyOnce(): void
    {
        $this->gh->fail = true;
        $c = $this->orchestrator();
        for ($i = 0; $i < 7; ++$i) {
            $c->poll();
        }
        self::assertCount(1, $this->notified);
        self::assertSame('epic', $this->notified[0][0]);
        $this->gh->fail = false;
        $c->poll();
        $this->gh->fail = true;
        for ($i = 0; $i < 5; ++$i) {
            $c->poll();
        }
        self::assertSame(2, $this->notifiedCount());
    }

    public function testSecondRunNotQueuedWhilePending(): void
    {
        $this->db->queueAdd(1, 2, -5, 'epic', 'pm', [['slug' => 'epic', 'run' => 'tick']]);
        $this->orchestrator()->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testRunningItemAlsoBlocksSecondRun(): void
    {
        $id = (int) $this->db->queueAdd(1, 2, -5, 'epic', 'pm', [['slug' => 'epic', 'run' => 'tick']]);
        $this->db->queueMarkRunning($id);
        $this->orchestrator()->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testPausedAndBlockedEpicsGetNoTicks(): void
    {
        $this->db->epicSetStatus('epic', 'paused');
        $this->orchestrator()->poll();
        $this->db->epicBlock('epic', 'x');
        $this->orchestrator()->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testDoItLabelWhenAfterMerged(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->orchestrator()->poll();
        self::assertSame(['b#2 openronin:do-it'], $this->gh->labelled);
    }

    public function testDoItNotWhenAfterNotMerged(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'open');
        $this->orchestrator()->poll();
        self::assertNotContains('b#2 openronin:do-it', $this->gh->labelled);
    }

    public function testDoItNotWhenHold(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->db->epicApplySignal('epic', [], ['b#2'], false);
        $this->orchestrator()->poll();
        self::assertNotContains('b#2 openronin:do-it', $this->gh->labelled);
    }

    public function testDoItForAddedTask(): void
    {
        $this->db->epicApplySignal('epic', [['task' => 'c#3', 'after' => ['a#1']]], null, false);
        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->orchestrator()->poll();
        self::assertContains('c#3 openronin:do-it', $this->gh->labelled);
        $this->gh->labelled = [];
        $this->db->epicApplySignal('epic', [], ['c#3'], false);
        $this->orchestrator()->poll();
        self::assertNotContains('c#3 openronin:do-it', $this->gh->labelled);
    }

    public function testReadyOnlyWhenAllChecksGreen(): void
    {
        $c = $this->orchestrator();
        foreach (['failed', 'pending', 'none'] as $checks) {
            $this->gh->states['a#1'] = $this->pr('a#1', 'draft', $checks);
            $c->poll();
        }
        self::assertSame([], $this->gh->readied);
        $this->gh->states['a#1'] = $this->pr('a#1', 'draft', 'green');
        $c->poll();
        self::assertSame(['a#1 5'], $this->gh->readied);
    }

    /**
     * @return array<string, array{TaskState}>
     */
    public static function noReview(): array
    {
        $mk = static fn (array $o): TaskState => new TaskState(
            'a#1',
            $o['labels'] ?? [],
            $o['pr'] ?? 'open',
            5,
            'h1',
            $o['checks'] ?? 'green',
            'true',
            1,
            0,
            self::T0 - ($o['age'] ?? 3600),
            null,
            $o['reviewed'] ?? false,
            $o['cold'] ?? null,
        );

        return [
            'draft' => [$mk(['pr' => 'draft', 'checks' => 'green'])],
            'cold not done' => [$mk(['cold' => false])],
            'in progress' => [$mk(['labels' => ['openronin:in-progress']])],
            'pending' => [$mk(['checks' => 'pending'])],
            'empty checks' => [$mk(['checks' => 'none'])],
            'fresh activity' => [$mk(['age' => 60])],
            'already reviewed' => [$mk(['reviewed' => true])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noReview')]
    public function testHotReviewNotQueued(TaskState $state): void
    {
        $this->gh->states['a#1'] = $state;
        $this->orchestrator()->poll();
        self::assertNotContains(['epic', 'review', 'a#5'], $this->enqueued);
        self::assertSame(0, $this->reviewCount());
    }

    /**
     * A merge-ready PR (approved on the covered head, green, mergeable).
     */
    private function approvedPr(string $task, bool $reviewDue = false): TaskState
    {
        $this->db->epicSetTaskCounter('epic', $task, 'coverage_head', 'h1');
        if ($reviewDue) {
            $this->db->epicSetTaskCounter('epic', $task, 'cold_retry', 'h1');
        }

        return new TaskState(
            $task,
            prState: 'open',
            prNumber: 5,
            head: 'h1',
            checks: 'green',
            mergeable: 'true',
            lastActivityAt: $this->now - 3600,
            coldReviewDone: true,
            epicVerdict: 'APPROVED',
            epicReviewHead: 'h1',
        );
    }

    public function testQueuedReviewRunOfThePrHoldsItsMerge(): void
    {
        $this->gh->states['a#1'] = $this->approvedPr('a#1');
        $this->gh->states['b#2'] = $this->approvedPr('b#2');
        // a review of a#5 is in the real queue (waiting, then running): the gate must not merge it meanwhile
        $this->db->queueAdd(1, 2, -1, 'epic', 'review', [['slug' => 'epic', 'run' => 'review', 'pr' => 'a#5']]);

        $this->orchestrator()->poll();
        self::assertSame(['b#2 5'], $this->gh->merged);

        $this->gh->merged = [];
        foreach ($this->db->queueWaiting() as $item) {
            $this->db->queueMarkRunning($item['id']);
        }
        $this->now += 60;
        $this->orchestrator()->poll();
        self::assertNotContains('a#1 5', $this->gh->merged);
    }

    public function testReviewEnqueuedInThisPassHoldsItsMergeButNotOthers(): void
    {
        $this->gh->states['a#1'] = $this->approvedPr('a#1', reviewDue: true);
        $this->gh->states['b#2'] = $this->approvedPr('b#2');

        $this->orchestrator()->poll();

        self::assertContains(['epic', 'review', 'a#5'], $this->enqueued);
        // the real queue does not contain the review (the fake enqueue only records it): only reviewEnqueued holds a#1
        self::assertSame(['b#2 5'], $this->gh->merged);
    }

    public function testHotReviewQueuedWhenQuiet(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true);
        $this->orchestrator()->poll();
        self::assertSame([['epic', 'review', 'a#5']], $this->enqueued);
    }

    /**
     * @param array<string, mixed> $o
     */
    private function spoke(array $o = []): TaskState
    {
        return new TaskState(
            'a#1',
            [],
            'open',
            5,
            'h1',
            $o['checks'] ?? 'green',
            $o['mergeable'] ?? 'true',
            1,
            0,
            $this->now - ($o['age'] ?? 120),
            null,
            false,
            array_key_exists('cold', $o) ? $o['cold'] : true,
            executorLastWord: $o['last'] ?? true,
            coldSummary: $o['summary'] ?? true,
        );
    }

    public function testHotReviewQueuedOnExecutorLastWordBeforeQuietWindow(): void
    {
        $this->gh->states['a#1'] = $this->spoke();
        $this->orchestrator()->poll();
        self::assertSame([['epic', 'review', 'a#5']], $this->enqueued);
    }

    public function testHotReviewQueuedOnExecutorLastWordWithoutColdReviewer(): void
    {
        $this->gh->states['a#1'] = $this->spoke(['cold' => null, 'summary' => false]);
        $this->orchestrator()->poll();
        self::assertSame([['epic', 'review', 'a#5']], $this->enqueued);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function noEventReview(): array
    {
        return [
            'executor has no last word' => [['last' => false]],
            'ci failed' => [['checks' => 'failed']],
            'ci pending' => [['checks' => 'pending']],
            'conflicting' => [['mergeable' => 'false']],
            'no cold summary' => [['summary' => false]],
            'less than a minute' => [['age' => 59]],
        ];
    }

    /**
     * @param array<string, mixed> $o
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noEventReview')]
    public function testHotReviewNotQueuedByEventPathWhenConditionFails(array $o): void
    {
        $this->gh->states['a#1'] = $this->spoke($o);
        $this->orchestrator()->poll();
        self::assertSame(0, $this->reviewCount());
    }

    public function testHotReviewByQuietWindowWithoutExecutorComment(): void
    {
        $this->gh->states['a#1'] = $this->spoke(['last' => false, 'age' => 600]);
        $this->orchestrator()->poll();
        self::assertSame(1, $this->reviewCount());
    }

    public function testHotReviewSavesColdFindingsAndHeadBeforeQueueing(): void
    {
        $finding = ['id' => 'C1', 'source' => 'inline', 'path' => 'src/A.php', 'line' => 12, 'body' => 'null deref'];
        $this->gh->cold['a#5'] = [$finding];
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true);
        $this->orchestrator()->poll();

        self::assertSame([['epic', 'review', 'a#5']], $this->enqueued);
        $stored = ColdFindings::load($this->dir.'/epics/epic/cold-a-5.json');
        self::assertNotNull($stored);
        self::assertSame('a#1', $stored['task']);
        self::assertSame('a#5', $stored['pr']);
        self::assertSame($this->gh->states['a#1']->head, $stored['head']);
        self::assertSame([$finding], $stored['findings']);
    }

    public function testHotReviewRetryAfterCoverageFailureReviewsSameHeadAgain(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true, reviewed: true);
        $this->orchestrator()->poll();
        self::assertSame(0, $this->reviewCount(), 'a review of the head already exists');

        $head = (string) $this->gh->states['a#1']->head;
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', '');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'cold_retry', $head);
        $this->orchestrator()->poll();
        self::assertSame(1, $this->reviewCount());
    }

    public function testHotReviewRequeuedOnceWhenReviewLandedOnHeadWithoutCoverage(): void
    {
        $base = $this->pr('a#1', 'open', cold: true, reviewed: true, head: 'h2');
        $this->gh->states['a#1'] = new TaskState(
            $base->task, $base->labels, $base->prState, $base->prNumber, 'h2', $base->checks, 'true', 1, 0,
            $base->lastActivityAt, null, true, true, epicVerdict: 'APPROVED', epicReviewHead: 'h2',
        );
        // queued on h1, commit h2 arrived before the run
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', 'h1');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $this->orchestrator()->poll();
        self::assertSame(1, $this->reviewCount());

        $this->orchestrator()->poll();
        self::assertSame(1, $this->reviewCount(), 'the retry happens once');
    }

    public function testNoHotReviewWhenRequestChangesLandedOnHeadWithoutCoverage(): void
    {
        $base = $this->pr('a#1', 'open', cold: true, reviewed: true, head: 'h2');
        $this->gh->states['a#1'] = new TaskState(
            $base->task, $base->labels, $base->prState, $base->prNumber, 'h2', $base->checks, 'true', 1, 0,
            $base->lastActivityAt, null, true, true, epicVerdict: 'CHANGES_REQUESTED', epicReviewHead: 'h2',
        );
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', 'h1');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $this->orchestrator()->poll();
        self::assertSame(0, $this->reviewCount(), 'a Request changes review of the head is not repeated');
    }

    /**
     * @return TaskState approved on h2 (GitHub moved the approval), coverage on h1
     */
    private function movedApproval(bool $masterOnly): TaskState
    {
        $base = $this->pr('a#1', 'open', cold: true, reviewed: true, head: 'h2');

        return new TaskState(
            $base->task, $base->labels, $base->prState, $base->prNumber, 'h2', $base->checks, 'true', 1, 0,
            $base->lastActivityAt, null, true, true, epicVerdict: 'APPROVED', epicReviewHead: 'h2', headOnlyMasterMerge: $masterOnly,
        );
    }

    public function testNoHotReviewOnHeadAcceptedAsMasterOnlyMerge(): void
    {
        $this->gh->states['a#1'] = $this->movedApproval(true);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', 'h1');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $this->orchestrator()->poll();
        self::assertSame(0, $this->reviewCount());
    }

    public function testSnapshotPassesCoverageHeadCounterToTaskState(): void
    {
        $this->gh->states['a#1'] = $this->movedApproval(true);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', 'rev-x');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'cov-y');
        $this->orchestrator()->poll();
        self::assertSame('cov-y', $this->gh->coverageHeads['a#1']);
    }

    public function testHotReviewOnMovedApprovalWithOrdinaryCommit(): void
    {
        $this->gh->states['a#1'] = $this->movedApproval(false);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'reviewed_head', 'h1');
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $this->orchestrator()->poll();
        self::assertSame(1, $this->reviewCount());
    }

    public function testHotReviewWaitsQuietAfterDraftReady(): void
    {
        // lastActivityAt includes taking off draft: 2 minutes ago with 10 minutes of silence
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: null, age: 120);
        $this->orchestrator()->poll();
        self::assertSame(0, $this->reviewCount());
    }

    public function testClosedIssueDoesNotGetDoIt(): void
    {
        $this->gh->states['a#1'] = new TaskState('a#1', issueOpen: false);
        $this->orchestrator()->poll();
        self::assertSame([], $this->gh->labelled);
    }

    public function testHotReviewWithoutColdWorkflow(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: null);
        $this->orchestrator()->poll();
        self::assertSame([['epic', 'review', 'a#5']], $this->enqueued);
    }

    public function testHotReviewNotRepeatedForSameHead(): void
    {
        $this->gh->states['a#1'] = $this->pr('a#1', 'open', cold: true);
        $c = $this->orchestrator();
        $c->poll();
        $this->enqueued = [];
        $this->now += 60;
        $c->poll();
        self::assertSame(0, $this->reviewCount());
    }

    public function testStallBlocksAfterKTimerTicks(): void
    {
        $c = $this->orchestrator(tick: 100, stall: 2);
        $this->settle($c);
        for ($i = 0; $i < 2; ++$i) {
            $this->now += 100;
            $c->poll();
            self::assertSame([['epic', 'tick', null]], $this->enqueued);
            $this->enqueued = [];
        }
        self::assertSame(0, $this->blockedCount());
        $this->now += 100;
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertSame([], $this->enqueued);
    }

    public function testResumeAfterStagnationDoesNotBlockAgain(): void
    {
        $c = $this->orchestrator(tick: 100, stall: 2);
        $this->settle($c);
        for ($i = 0; $i < 3; ++$i) {
            $this->now += 100;
            $c->poll();
        }
        self::assertSame(1, $this->blockedCount());

        $this->db->epicResume('epic');
        self::assertSame(0, $this->epic()['stale_ticks']);
        $this->enqueued = [];
        // Database::epicResume() stamps the wall clock: the next timer tick comes one interval after it.
        $this->now = time();
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertSame([], $this->enqueued);
        $this->now += 100;
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
    }

    public function testPassThatMergesIsProgressEvenWithExhaustedStallBudget(): void
    {
        $this->gh->states['a#1'] = new TaskState('a#1', [], 'open', 5, 'h1', 'green', 'true', 1, 0, $this->now - 3600, null, true, null, epicVerdict: 'APPROVED', epicReviewHead: 'h1');
        $c = $this->orchestrator(tick: 100, stall: 2);
        $this->settle($c);
        $this->db->epicSetTaskCounter('epic', 'a#1', 'coverage_head', 'h1');
        $this->db->epicTickQueued('epic', $this->now, 2);
        $this->now += 100;
        $c->poll();
        self::assertSame(['a#1 5'], $this->gh->merged);
        self::assertSame(0, $this->blockedCount());
        self::assertSame(0, $this->epic()['stale_ticks']);
        self::assertSame([], $this->enqueued);
    }

    public function testInProgressNoTimerTickNoStallButBlocksWhenStuck(): void
    {
        $this->gh->states['a#1'] = new TaskState('a#1', ['openronin:in-progress']);
        $c = $this->orchestrator(tick: 100, stall: 3);
        $this->settle($c);
        for ($i = 0; $i < 2; ++$i) {
            $this->now += 100;
            $c->poll();
        }
        self::assertSame(0, $this->blockedCount());
        self::assertSame([], $this->enqueued);
        self::assertSame(0, $this->epic()['stale_ticks']);
        // The label hangs without activity for the whole stall budget (tick × stall): openronin has apparently crashed.
        $this->now += 100;
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        self::assertStringContainsString('in-progress', $this->blockedReason());
        self::assertSame([], $this->enqueued);
    }

    public function testRunWithoutAttentionChangeKeepsOwnAction(): void
    {
        $c = $this->orchestrator();
        $this->settle($c);
        // The run itself sets awaiting-answer: its own action gives no tick.
        $this->gh->states['a#1'] = new TaskState('a#1', ['openronin:awaiting-answer']);
        $c->rememberAfterRun('epic');
        $c->poll();
        $this->now += 60;
        $c->poll();
        self::assertSame([], $this->enqueued);
    }

    public function testChangeTickResetsStall(): void
    {
        $c = $this->orchestrator(tick: 100, stall: 1);
        $this->settle($c);
        $this->now += 100;
        $c->poll();
        $this->gh->states['a#1'] = new TaskState('a#1', ['x']);
        $this->now += 10;
        $c->poll();
        self::assertSame(0, $this->epic()['stale_ticks']);
    }

    public function testAwaitingAnswerTimeout(): void
    {
        $with = fn (?int $lastEpic): TaskState => new TaskState('a#1', ['openronin:awaiting-answer'], lastEpicComment: $lastEpic);
        $c = $this->orchestrator(answer: 7200);
        $this->gh->states['a#1'] = $with($this->now - 7000);
        $c->poll();
        self::assertSame(0, $this->blockedCount());
        $this->gh->states['a#1'] = $with($this->now - 7300);
        $c->poll();
        self::assertSame(1, $this->blockedCount());
        $task = array_values(array_filter($this->db->epicTasks('epic'), static fn (array $t): bool => $t['task'] === 'a#1'))[0];
        self::assertSame('blocked', $task['status']);
    }

    public function testAwaitingAnswerIgnoresQuietParameter(): void
    {
        // T = 10 min, answer timeout = 2 h: 30 min without a marker comment — not blocked yet.
        $this->gh->states['a#1'] = new TaskState('a#1', ['openronin:awaiting-answer'], lastEpicComment: $this->now - 1800);
        $this->orchestrator(quiet: 600, answer: 7200)->poll();
        self::assertSame(0, $this->blockedCount());
    }

    private function cardOrchestrator(?string $locale = 'en'): Orchestrator
    {
        $this->tg = new FakeTelegram();
        mkdir($this->dir.'/repo/epics', 0777, true);

        // null — do not pass a locale at all, so the constructors' own default is used
        $card = $locale === null
            ? new Card($this->db, $this->tg, $this->dir.'/repo', 'epics', 1800)
            : new Card($this->db, $this->tg, $this->dir.'/repo', 'epics', 1800, new Locale($locale));

        return $this->orchestrator(card: $card, locale: $locale);
    }

    private FakeTelegram $tg;

    private function editCount(): int
    {
        return count($this->tg->edits);
    }

    private function journal(string $body): void
    {
        file_put_contents($this->dir.'/repo/epics/epic.journal.md', $body);
    }

    public function testFirstPollSendsSilentCardAndPinsIt(): void
    {
        // Deliberately Cyrillic: proves the passed locale is used for the card (en is the built-in default)
        $c = $this->cardOrchestrator('ru');
        $c->poll();

        self::assertCount(1, $this->tg->sent);
        self::assertTrue($this->tg->sent[0]['silent']);
        self::assertSame(2, $this->tg->sent[0]['thread']);
        self::assertSame($this->tg->sent[0]['id'], $this->epic()['card_message_id']);
        self::assertSame([$this->tg->sent[0]['id']], $this->tg->pinned);
        self::assertStringContainsString('журнал ещё пуст', $this->tg->sent[0]['text']);
        self::assertStringContainsString('a#1 — ', $this->tg->sent[0]['text']);
        self::assertStringContainsString('b#2 — ⏳ ждёт a#1', $this->tg->sent[0]['text']);
        self::assertSame(self::T0, $this->epic()['last_poll_at']);
    }

    public function testDefaultLocaleCardIsEnglish(): void
    {
        $c = $this->cardOrchestrator(null);
        $c->poll();

        $text = $this->tg->sent[0]['text'];
        self::assertStringContainsString('📋 Epic epic: running', $text);
        self::assertStringContainsString('Last GitHub poll: ', $text);
        self::assertStringContainsString("Tasks:\n", $text);
        self::assertStringContainsString('b#2 — ⏳ waiting for a#1', $text);
        self::assertStringContainsString('Journal: the journal is still empty', $text);
        self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $text);
    }

    public function testDefaultLocaleOrchestratorBlockReasonIsEnglish(): void
    {
        // null — the constructor's own default locale is used
        $c = $this->orchestrator(tick: 100, stall: 2, locale: null);
        $this->settle($c);
        for ($i = 0; $i < 3; ++$i) {
            $this->now += 100;
            $c->poll();
        }

        self::assertSame(1, $this->blockedCount());
        self::assertSame('no movement: 2 timer ticks in a row without changes on GitHub', $this->blockedReason());
    }

    public function testCardShowsLastJournalEntryOnly(): void
    {
        // Deliberately Cyrillic: the only check of the Russian "Хронология" journal heading
        $c = $this->cardOrchestrator('ru');
        $this->journal("# Journal\n\n## Decisions\n\n- decision\n\n## Хронология\n\n- first entry\n- second entry\n\n## Tails\n\n- tail\n");
        $c->poll();
        $this->now += 60;
        $this->journal("## Хронология\n\n- first entry\n- second entry\n- third ".str_repeat('é', 400)."\n");
        $c->poll();

        self::assertSame(1, $this->editCount());
        $text = $this->tg->edits[0]['text'];
        self::assertStringContainsString('third', $text);
        self::assertStringNotContainsString('first', $text);
        self::assertStringNotContainsString('tail', $text);
        self::assertLessThan(500, mb_strlen(explode('Журнал: ', $text)[1]));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        // Exact multibyte cut: a byte-wise truncation would leave a different tail
        self::assertStringContainsString('third '.str_repeat('é', 293).'…', $text);
    }

    public function testCardShowsLastEntryOfTimelineSection(): void
    {
        $c = $this->cardOrchestrator();
        $this->journal("# Epic journal demo\n\n## Timeline\n- first entry\n- second entry\n\n## Failures\n- a failure\n");
        $c->poll();

        $text = $this->tg->sent[0]['text'];
        self::assertStringContainsString('second entry', $text);
        self::assertStringNotContainsString('first entry', $text);
        self::assertStringNotContainsString('a failure', $text);
    }

    public function testStageChangeEditsCardAndSameTextIsNotResent(): void
    {
        $c = $this->cardOrchestrator();
        $this->settle($c);
        $this->now += 60;
        $c->poll();
        self::assertSame(0, $this->editCount());

        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->now += 60;
        $c->poll();
        self::assertSame(1, $this->editCount());
        self::assertStringContainsString('a#1 — ✅ merged', $this->tg->edits[0]['text']);
        self::assertSame($this->tg->sent[0]['id'], $this->tg->edits[0]['message_id']);

        $this->now += 60;
        $c->poll();
        self::assertSame(1, $this->editCount());
    }

    public function testPollTimeInCardRefreshesNoMoreOftenThanEveryFiveMinutes(): void
    {
        $c = $this->cardOrchestrator();
        $this->settle($c);
        $shown = Card::formatTime(self::T0);
        self::assertStringContainsString($shown, $this->tg->sent[0]['text']);
        $this->tg->edits = [];

        $this->now += 240;
        $c->poll();
        self::assertSame(0, $this->editCount());
        self::assertSame(self::T0 + 240, $this->epic()['last_poll_at']);

        $this->now += 60;
        $c->poll();
        self::assertSame(1, $this->editCount());
        self::assertStringContainsString(Card::formatTime(self::T0 + 300), $this->tg->edits[0]['text']);
    }

    public function testDeletedCardIsResentSilentlyWithNewId(): void
    {
        $c = $this->cardOrchestrator();
        $c->poll();
        $oldId = $this->tg->sent[0]['id'];
        $this->tg->editStatus = EditStatus::Gone;
        $this->gh->states['a#1'] = $this->pr('a#1', 'merged');
        $this->now += 60;
        $c->poll();

        self::assertCount(2, $this->tg->sent);
        self::assertTrue($this->tg->sent[1]['silent']);
        self::assertNotSame($oldId, $this->epic()['card_message_id']);
        self::assertSame($this->tg->sent[1]['id'], $this->epic()['card_message_id']);
        self::assertContains($this->tg->sent[1]['id'], $this->tg->pinned);
    }

    public function testPinFailureDoesNotBreakEpic(): void
    {
        $c = $this->cardOrchestrator();
        $this->tg->pinOk = false;
        $c->poll();

        self::assertSame([], $this->tg->pinned);
        self::assertSame($this->tg->sent[0]['id'], $this->epic()['card_message_id']);
        self::assertSame([['epic', 'tick', null]], $this->enqueued);
    }

    public function testTelegramErrorDoesNotCountAsPollFailure(): void
    {
        $c = $this->cardOrchestrator();
        $this->tg->plainFails = true;
        // At least NOTIFY_AFTER_FAILURES (5) polls in a row: a card failure does not accumulate as a poll failure.
        for ($i = 0; $i < 6; ++$i) {
            $c->poll();
            $this->now += 60;
        }

        self::assertSame([], $this->notified);
        self::assertSame($this->now - 60, $this->epic()['last_poll_at']);
        self::assertNotSame([], $this->enqueued);
    }
}
