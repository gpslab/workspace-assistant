<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Epic\TaskState;
use GpsLab\WorkspaceAssistant\GhClient;
use GpsLab\WorkspaceAssistant\GithubOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GhClient::class)]
final class GhClientTest extends TestCase
{
    /**
     * @param list<array<string, string>> $contexts
     */
    private static function checks(array $contexts): string
    {
        return GhClient::checks($contexts);
    }

    public function testChecksEmptyIsNone(): void
    {
        self::assertSame('none', self::checks([]));
    }

    public function testChecksNeutralAndSkippedAreGreen(): void
    {
        self::assertSame('green', self::checks([
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'NEUTRAL'],
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'SKIPPED'],
            ['__typename' => 'StatusContext', 'state' => 'SUCCESS'],
        ]));
    }

    public function testChecksPendingBeatsFailed(): void
    {
        self::assertSame('pending', self::checks([
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
            ['__typename' => 'CheckRun', 'status' => 'IN_PROGRESS', 'conclusion' => ''],
        ]));
        self::assertSame('pending', self::checks([['__typename' => 'StatusContext', 'state' => 'EXPECTED']]));
        self::assertSame('pending', self::checks([['__typename' => 'StatusContext', 'state' => 'PENDING']]));
    }

    public function testChecksFailed(): void
    {
        self::assertSame('failed', self::checks([['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'CANCELLED']]));
        self::assertSame('failed', self::checks([['__typename' => 'StatusContext', 'state' => 'ERROR']]));
    }

    public function testPickPrPrefersMergedThenOpenThenHigherNumber(): void
    {
        $open = ['number' => 9, 'state' => 'OPEN'];
        $closed = ['number' => 10, 'state' => 'CLOSED'];
        $merged = ['number' => 3, 'state' => 'MERGED', 'merged' => true];
        self::assertSame(3, self::number(GhClient::pickPr([$open, $closed, $merged])));
        self::assertSame(9, self::number(GhClient::pickPr([$closed, $open])));
        self::assertSame(10, self::number(GhClient::pickPr([['number' => 4, 'state' => 'CLOSED'], $closed])));
        self::assertSame(11, self::number(GhClient::pickPr([$open, ['number' => 11, 'state' => 'OPEN']])));
        self::assertNull(GhClient::pickPr([]));
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    private static function number(?array $pr): int
    {
        return (int) ($pr['number'] ?? 0);
    }

    public function testPrState(): void
    {
        self::assertSame(TaskState::MERGED, GhClient::prState(['merged' => true, 'state' => 'MERGED']));
        self::assertSame(TaskState::CLOSED, GhClient::prState(['state' => 'CLOSED']));
        self::assertSame(TaskState::DRAFT, GhClient::prState(['state' => 'OPEN', 'isDraft' => true]));
        self::assertSame(TaskState::OPEN, GhClient::prState(['state' => 'OPEN', 'isDraft' => false]));
    }

    public function testMergeableNullIsUnknown(): void
    {
        self::assertSame('true', GhClient::mergeable('MERGEABLE'));
        self::assertSame('false', GhClient::mergeable('CONFLICTING'));
        self::assertSame('unknown', GhClient::mergeable('UNKNOWN'));
        self::assertSame('unknown', GhClient::mergeable(null));
    }

    /**
     * @param array{exit:int, stdout:string, stderr:string} $result
     */
    private static function client(array $result): GhClient
    {
        return new GhClient(new GithubOwners(['o']), 5, static fn (array $cmd): array => $result);
    }

    public function testColdReviewNoRunsOnHeadIsDone(): void
    {
        $gh = self::client(['exit' => 0, 'stdout' => '{"workflow_runs":[]}', 'stderr' => '']);
        self::assertTrue($gh->coldReviewDone('r', 'h2'));
    }

    public function testColdReviewUnfinishedRunIsNotDone(): void
    {
        $out = json_encode(['workflow_runs' => [['status' => 'completed'], ['status' => 'in_progress']]]);
        self::assertFalse(self::client(['exit' => 0, 'stdout' => (string) $out, 'stderr' => ''])->coldReviewDone('r', 'h1'));
    }

    public function testColdReviewFinishedRunsAreDone(): void
    {
        $out = json_encode(['workflow_runs' => [['status' => 'completed']]]);
        self::assertTrue(self::client(['exit' => 0, 'stdout' => (string) $out, 'stderr' => ''])->coldReviewDone('r', 'h1'));
    }

    public function testColdReview404MeansNoWorkflow(): void
    {
        $gh = self::client(['exit' => 1, 'stdout' => '', 'stderr' => 'gh: Not Found (HTTP 404)']);
        self::assertNull($gh->coldReviewDone('r', 'h1'));
    }

    public function testColdReviewOtherErrorThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        self::client(['exit' => 1, 'stdout' => '', 'stderr' => 'gh: Bad credentials (HTTP 401)'])->coldReviewDone('r', 'h1');
    }

    public function testTaskStateParsesIssueAndPr(): void
    {
        $marker = '<!-- epic:x -->';
        $payload = ['data' => ['repository' => ['issue' => [
            'state' => 'CLOSED',
            'labels' => ['nodes' => [['name' => 'openronin:in-progress']]],
            'comments' => ['totalCount' => 2, 'nodes' => [
                ['body' => "q {$marker}", 'createdAt' => '2026-01-01T10:00:00Z', 'author' => ['login' => 'epic-bot']],
                ['body' => 'human', 'createdAt' => '2026-01-01T11:00:00Z'],
            ]],
            'closedByPullRequestsReferences' => ['nodes' => [[
                'number' => 7, 'state' => 'OPEN', 'isDraft' => true, 'merged' => false, 'headRefOid' => 'abc', 'mergeable' => null,
                'comments' => ['totalCount' => 1, 'nodes' => [['body' => 'x', 'createdAt' => '2026-01-01T12:00:00Z']]],
                'timelineItems' => ['nodes' => [['createdAt' => '2026-01-01T13:00:00Z']]],
                'reviews' => ['totalCount' => 1, 'nodes' => [['body' => "r {$marker}", 'author' => ['login' => 'epic-bot'], 'commit' => ['oid' => 'abc']]]],
                'commits' => ['nodes' => [['commit' => [
                    'committedDate' => '2026-01-01T09:00:00Z',
                    'statusCheckRollup' => ['contexts' => ['nodes' => [['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS']]]],
                ]]]],
            ]]],
        ]]]];
        $gh = new GhClient(new GithubOwners(['o']), 5, static fn (array $cmd): array => $cmd === ['gh', 'api', 'user']
            ? ['exit' => 0, 'stdout' => '{"login":"Epic-Bot"}', 'stderr' => '']
            : (str_contains(implode(' ', $cmd), 'workflows')
            ? ['exit' => 0, 'stdout' => '{"workflow_runs":[]}', 'stderr' => '']
            : ['exit' => 0, 'stdout' => (string) json_encode($payload), 'stderr' => '']));

        $s = $gh->taskState('r#1', $marker);
        self::assertSame(TaskState::DRAFT, $s->prState);
        self::assertSame(7, $s->prNumber);
        self::assertSame('abc', $s->head);
        self::assertSame('green', $s->checks);
        self::assertSame('unknown', $s->mergeable);
        self::assertSame(3, $s->comments);
        self::assertSame(2, $s->foreignComments);
        self::assertTrue($s->epicReviewedHead);
        self::assertTrue($s->coldReviewDone);
        self::assertSame(strtotime('2026-01-01T10:00:00Z'), $s->lastEpicComment);
        self::assertSame(strtotime('2026-01-01T13:00:00Z'), $s->lastActivityAt);
        self::assertFalse($s->issueOpen);
        self::assertTrue($s->hasLabel('openronin:in-progress'));
    }

    public function testFullTaskUsesItsOwnerInRepoArgument(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['o', 'second']), 5, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $gh->commentPr('second/r#1', 3, 'x');
        $gh->commentPr('r#1', 3, 'x');

        self::assertContains('second/r', $calls[0]);
        self::assertContains('o/r', $calls[1]);
    }

    public function testForeignOwnerNeverReachesGh(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['o', 'second']), 5, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        });

        try {
            $gh->commentPr('foreign/r#1', 3, 'x');
            self::fail('foreign owner must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('foreign', $e->getMessage());
        }
        self::assertSame([], $calls);
    }

    public function testSeveralOwnersAreWalkedAndKeepTheirOwner(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['first', 'second']), 15, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;
            if ($cmd[1] === 'repo') {
                return ['exit' => 0, 'stdout' => $cmd[3] === 'first' ? '[{"name":"a"}]' : '[{"name":"b"}]', 'stderr' => ''];
            }

            return ['exit' => 0, 'stdout' => json_encode(['data' => [
                'r0n7' => ['pullRequest' => ['state' => 'OPEN']],
                'r1n7' => ['pullRequest' => ['state' => 'OPEN']],
            ]]), 'stderr' => ''];
        });

        $found = $gh->openPrRepos([7]);

        $lists = array_values(array_filter($calls, static fn (array $c): bool => $c[1] === 'repo'));
        self::assertSame(['first', 'second'], [$lists[0][3], $lists[1][3]]);
        $query = implode(' ', $calls === [] ? [] : $calls[array_key_last($calls)]);
        self::assertStringContainsString('owner:"first",name:"a"', $query);
        self::assertStringContainsString('owner:"second",name:"b"', $query);
        self::assertSame([7 => ['a', 'second/b']], $found);

        $gh->commentPr('second/b#7', 7, 'x');
        self::assertContains('second/b', $calls === [] ? [] : $calls[array_key_last($calls)]);
    }

    public function testSecondOwnerIsUsedByTaskStateColdFindingsMasterCiAndAddLabel(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['first', 'second']), 5, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;

            return ['exit' => 0, 'stdout' => '{"data":{"repository":{"issue":{"state":"OPEN"}}}}', 'stderr' => ''];
        });

        $gh->taskState('second/r#1', self::MARKER);
        $graphql = implode(' ', $calls[0]);
        self::assertStringContainsString('owner=second', $graphql);
        self::assertStringNotContainsString('owner=first', $graphql);

        $calls = array_slice($calls, count($calls));
        $gh->coldFindings('second/r#1');
        self::assertGreaterThan(0, count($calls));
        foreach ($calls as $cmd) {
            self::assertStringStartsWith('repos/second/r/', $cmd[2]);
        }

        $calls = array_slice($calls, count($calls));
        $gh->masterCi('second/r');
        $graphql = implode(' ', $calls[0]);
        self::assertStringContainsString('owner=second', $graphql);
        self::assertStringNotContainsString('owner=first', $graphql);

        $calls = array_slice($calls, count($calls));
        $gh->addLabel('second/r#1', 'l');
        self::assertContains('second/r', $calls[0]);
        self::assertNotContains('first/r', $calls[0]);
    }

    public function testShortTaskWithoutOwnersFails(): void
    {
        $gh = new GhClient(new GithubOwners(), 5, static fn (array $cmd): array => ['exit' => 0, 'stdout' => '', 'stderr' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GithubOwners::NOT_SET);

        $gh->addLabel('r#1', 'l');
    }

    private const string MARKER = '<!-- epic:x -->';
    private const string BOT = 'Epic-Bot';

    /**
     * @param array<int, array<string, mixed>> $comments      PR comments
     * @param array<int, array<string, mixed>> $reviews
     * @param array<int, array<string, mixed>> $issueComments
     *
     * @return array<string, mixed>
     */
    private static function approvedPayload(string $head, array $comments = [], array $reviews = [], array $issueComments = []): array
    {
        $epicReview = ['author' => ['login' => self::BOT], 'body' => 'ok '.self::MARKER, 'state' => 'APPROVED', 'submittedAt' => '2026-01-01T10:00:00Z', 'commit' => ['oid' => 'rev']];

        return ['data' => ['repository' => ['issue' => [
            'state' => 'OPEN', 'labels' => ['nodes' => []], 'comments' => ['totalCount' => count($issueComments), 'nodes' => $issueComments],
            'closedByPullRequestsReferences' => ['nodes' => [[
                'number' => 7, 'state' => 'OPEN', 'isDraft' => false, 'merged' => false, 'headRefOid' => $head,
                'mergeable' => 'MERGEABLE', 'baseRefName' => 'master',
                'comments' => ['totalCount' => count($comments), 'nodes' => $comments],
                'reviews' => ['totalCount' => 1 + count($reviews), 'nodes' => [
                    ['author' => ['login' => self::BOT], 'body' => 'changes '.self::MARKER, 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-01-01T09:00:00Z', 'commit' => ['oid' => 'old']],
                    $epicReview,
                    ...$reviews,
                ]],
                'commits' => ['nodes' => [['commit' => ['committedDate' => '2026-01-01T08:00:00Z', 'statusCheckRollup' => null]]]],
            ]]],
        ]]]];
    }

    /**
     * @param array<string, mixed>                                                              $payload
     * @param array<string, array{exit:int, stdout:string, stderr:string}|array<string, mixed>> $rest    command substring → REST response (an array is JSON, exit 0)
     */
    private static function clientFor(array $payload, array $rest = []): GhClient
    {
        return new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use ($payload, $rest): array {
            $line = implode(' ', $cmd);
            if ($cmd === ['gh', 'api', 'user']) {
                return ['exit' => 0, 'stdout' => '{"login":"'.self::BOT.'"}', 'stderr' => ''];
            }
            if (str_contains($line, 'graphql')) {
                return ['exit' => 0, 'stdout' => (string) json_encode($payload), 'stderr' => ''];
            }
            if (str_contains($line, 'workflows')) {
                return ['exit' => 0, 'stdout' => '{"workflow_runs":[]}', 'stderr' => ''];
            }
            foreach ($rest as $needle => $answer) {
                if (str_contains($line, (string) $needle)) {
                    return isset($answer['exit']) ? $answer : ['exit' => 0, 'stdout' => (string) json_encode($answer), 'stderr' => ''];
                }
            }

            return ['exit' => 1, 'stdout' => '', 'stderr' => 'gh: Not Found (HTTP 404)'];
        });
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @param list<array<string, mixed>> $reviews
     * @param list<string>               $logins
     * @param list<string>               $ready
     * @param list<array<string, mixed>> $issueComments
     */
    private static function lastWord(array $comments, array $reviews = [], ?string $commit = null, array $logins = [], array $ready = [], array $issueComments = []): TaskState
    {
        $payload = self::approvedPayload('rev', $comments, $reviews, $issueComments);
        $pr = &$payload['data']['repository']['issue']['closedByPullRequestsReferences']['nodes'][0];
        if ($commit !== null) {
            $pr['commits']['nodes'][0]['commit']['committedDate'] = $commit;
        }
        $pr['timelineItems'] = ['nodes' => array_map(static fn (string $at): array => ['createdAt' => $at], $ready)];
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use ($payload): array {
            $line = implode(' ', $cmd);

            return match (true) {
                $cmd === ['gh', 'api', 'user'] => ['exit' => 0, 'stdout' => '{"login":"'.self::BOT.'"}', 'stderr' => ''],
                str_contains($line, 'graphql') => ['exit' => 0, 'stdout' => (string) json_encode($payload), 'stderr' => ''],
                str_contains($line, 'workflows') => ['exit' => 0, 'stdout' => '{"workflow_runs":[]}', 'stderr' => ''],
                default => ['exit' => 0, 'stdout' => '{"behind_by":0}', 'stderr' => ''],
            };
        }, openroninLogins: $logins);

        return $gh->taskState('r#1', self::MARKER);
    }

    /**
     * @return array<string, mixed>
     */
    private static function executorComment(string $at, string $login = 'openronin-bot'): array
    {
        return ['body' => "<!-- openronin:bot -->\ndone", 'createdAt' => $at, 'author' => ['__typename' => 'User', 'login' => $login]];
    }

    public function testExecutorLastWordWhenNewerThanAllOtherActivity(): void
    {
        self::assertTrue(self::lastWord([self::executorComment('2026-01-01T11:00:00Z')])->executorLastWord);
        self::assertFalse(self::lastWord([])->executorLastWord);
    }

    public function testExecutorLastWordBrokenByLaterActivity(): void
    {
        $executor = self::executorComment('2026-01-01T11:00:00Z');
        $human = ['body' => 'wait', 'createdAt' => '2026-01-01T11:05:00Z', 'author' => ['__typename' => 'User', 'login' => 'human']];
        $epic = ['body' => 'conflict '.self::MARKER, 'createdAt' => '2026-01-01T11:05:00Z', 'author' => ['__typename' => 'User', 'login' => self::BOT]];
        $review = ['author' => ['login' => 'human'], 'body' => 'hm', 'state' => 'COMMENTED', 'submittedAt' => '2026-01-01T11:05:00Z', 'commit' => ['oid' => 'rev']];
        self::assertFalse(self::lastWord([$executor, $human])->executorLastWord);
        self::assertFalse(self::lastWord([$executor, $epic])->executorLastWord);
        self::assertFalse(self::lastWord([$executor], [$review])->executorLastWord);
        self::assertFalse(self::lastWord([$executor], [], '2026-01-01T11:05:00Z')->executorLastWord);
    }

    public function testExecutorLastWordBrokenByIssueActivityReadyEventAndTie(): void
    {
        $executor = self::executorComment('2026-01-01T11:00:00Z');
        $human = ['body' => 'wait', 'createdAt' => '2026-01-01T11:05:00Z', 'author' => ['__typename' => 'User', 'login' => 'human']];
        $epic = ['body' => 'conflict '.self::MARKER, 'createdAt' => '2026-01-01T11:05:00Z', 'author' => ['__typename' => 'User', 'login' => self::BOT]];
        self::assertFalse(self::lastWord([$executor], issueComments: [$human])->executorLastWord);
        self::assertFalse(self::lastWord([$executor], issueComments: [$epic])->executorLastWord);
        self::assertTrue(self::lastWord([$executor], issueComments: [['createdAt' => '2026-01-01T10:55:00Z'] + $human])->executorLastWord);
        self::assertFalse(self::lastWord([$executor], ready: ['2026-01-01T11:05:00Z'])->executorLastWord);
        self::assertTrue(self::lastWord([$executor], ready: ['2026-01-01T10:55:00Z'])->executorLastWord);
        $tie = ['body' => 'same', 'createdAt' => '2026-01-01T11:00:00Z', 'author' => ['__typename' => 'User', 'login' => 'human']];
        self::assertFalse(self::lastWord([$executor, $tie])->executorLastWord);
    }

    public function testColdSummaryInIssueDoesNotCount(): void
    {
        $summary = ['body' => 'review', 'createdAt' => '2026-01-01T10:30:00Z', 'author' => ['__typename' => 'Bot', 'login' => 'claude']];
        self::assertFalse(self::lastWord([], issueComments: [$summary])->coldSummary);
        self::assertTrue(self::lastWord([$summary], issueComments: [$summary])->coldSummary);
    }

    public function testExecutorMarkerFromUnlistedLoginIsOtherActivity(): void
    {
        $trusted = self::executorComment('2026-01-01T11:00:00Z');
        $stranger = self::executorComment('2026-01-01T11:05:00Z', 'stranger');
        self::assertTrue(self::lastWord([$trusted], logins: ['openronin-bot'])->executorLastWord);
        self::assertFalse(self::lastWord([$trusted, $stranger], logins: ['openronin-bot'])->executorLastWord);
        self::assertFalse(self::lastWord([$stranger], logins: ['openronin-bot'])->executorLastWord);
    }

    public function testColdSummaryIsRecognizedFromGraphqlBotAfterDraftExit(): void
    {
        $summary = ['body' => 'review', 'createdAt' => '2026-01-01T10:30:00Z', 'author' => ['__typename' => 'Bot', 'login' => 'claude']];
        self::assertTrue(self::lastWord([$summary])->coldSummary);
        self::assertTrue(self::lastWord([$summary], ready: ['2026-01-01T10:00:00Z'])->coldSummary);
        self::assertFalse(self::lastWord([$summary], ready: ['2026-01-01T10:45:00Z'])->coldSummary);
        self::assertFalse(self::lastWord([])->coldSummary);
        $human = ['body' => 'review', 'createdAt' => '2026-01-01T10:30:00Z', 'author' => ['__typename' => 'User', 'login' => 'claude']];
        self::assertFalse(self::lastWord([$human])->coldSummary);
        $rest = ['body' => 'review', 'createdAt' => '2026-01-01T10:30:00Z', 'author' => ['__typename' => 'Bot', 'login' => 'claude[bot]']];
        self::assertTrue(self::lastWord([$rest])->coldSummary);
    }

    public function testApprovedVerdictCountsAndSameHead(): void
    {
        $s = self::clientFor(self::approvedPayload('rev'), ['compare/master...rev' => ['behind_by' => 0]])->taskState('r#1', self::MARKER, 'rev');
        self::assertSame('APPROVED', $s->epicVerdict);
        self::assertSame('rev', $s->epicReviewHead);
        self::assertSame(1, $s->epicChangesRequested);
        // the head equals coverage_head: nothing to prove, the head is accepted by TaskState::headAccepted
        self::assertFalse($s->headOnlyMasterMerge);
        self::assertTrue($s->headAccepted('rev'));
        self::assertFalse($s->behindMaster);
        self::assertFalse($s->changedAfterReview);
    }

    public function testBehindMaster(): void
    {
        $s = self::clientFor(self::approvedPayload('rev'), ['compare/master...rev' => ['behind_by' => 3]])->taskState('r#1', self::MARKER);
        self::assertTrue($s->behindMaster);
    }

    public function testCommentsAfterApproveCountOnlyFromHumans(): void
    {
        $late = '2026-01-01T11:00:00Z';
        $human = [['body' => 'wait', 'createdAt' => $late, 'author' => ['__typename' => 'User']]];
        $epic = [['body' => 'conflict '.self::MARKER, 'createdAt' => $late, 'author' => ['__typename' => 'User', 'login' => 'epic-bot']]];
        $bot = [['body' => 'cold review', 'createdAt' => $late, 'author' => ['__typename' => 'Bot']]];
        $before = [['body' => 'early', 'createdAt' => '2026-01-01T09:30:00Z', 'author' => ['__typename' => 'User']]];

        $changed = static fn (array $comments, array $reviews = []): bool => self::clientFor(self::approvedPayload('rev', $comments, $reviews), ['compare/' => ['behind_by' => 0]])
            ->taskState('r#1', self::MARKER)->changedAfterReview;
        self::assertTrue($changed($human));
        self::assertFalse($changed($epic));
        self::assertFalse($changed($bot));
        $executor = [['body' => "<!-- openronin:bot -->\nread the remark, no changes needed", 'createdAt' => $late, 'author' => ['__typename' => 'User']]];
        self::assertFalse($changed($executor));
        $padded = [['body' => "\n  <!-- openronin:bot -->\nok", 'createdAt' => $late, 'author' => ['__typename' => 'User']]];
        self::assertFalse($changed($padded));
        self::assertTrue($changed([...$executor, ...$human]));
        $quoted = [['body' => 'wait <!-- openronin:bot -->', 'createdAt' => $late, 'author' => ['__typename' => 'User']]];
        self::assertTrue($changed($quoted));
        self::assertFalse($changed($before));
        self::assertTrue($changed([], [['body' => 'manual', 'state' => 'COMMENTED', 'submittedAt' => $late, 'commit' => ['oid' => 'rev']]]));
        self::assertFalse($changed([], [['author' => ['login' => self::BOT], 'body' => 'old '.self::MARKER, 'state' => 'COMMENTED', 'submittedAt' => $late, 'commit' => ['oid' => 'rev']]]));
    }

    /**
     * @param list<string> $logins
     */
    private static function executorChanged(string $author, array $logins): bool
    {
        $comment = [['body' => "<!-- openronin:bot -->\nreply", 'createdAt' => '2026-01-01T11:00:00Z', 'author' => ['__typename' => 'User', 'login' => $author]]];
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd): array {
            return match (true) {
                $cmd === ['gh', 'api', 'user'] => ['exit' => 0, 'stdout' => '{"login":"Epic-Bot"}', 'stderr' => ''],
                str_contains(implode(' ', $cmd), 'graphql') => ['exit' => 0, 'stdout' => (string) json_encode(self::approvedPayload('rev', self::$pending)), 'stderr' => ''],
                str_contains(implode(' ', $cmd), 'workflows') => ['exit' => 0, 'stdout' => '{"workflow_runs":[]}', 'stderr' => ''],
                default => ['exit' => 0, 'stdout' => '{"behind_by":0}', 'stderr' => ''],
            };
        }, openroninLogins: $logins);
        self::$pending = $comment;

        return $gh->taskState('r#1', self::MARKER)->changedAfterReview;
    }

    /** @var array<int, array<string, mixed>> */
    private static array $pending = [];

    public function testOpenroninMarkerCountsOnlyFromListedLogins(): void
    {
        self::assertFalse(self::executorChanged('Anime-DB-Bot', ['anime-db-bot']));
        self::assertTrue(self::executorChanged('stranger', ['anime-db-bot']));
    }

    public function testOpenroninMarkerFromAnyoneWithoutLogins(): void
    {
        self::assertFalse(self::executorChanged('stranger', []));
    }

    public function testEpicMarkerFromStrangerIsForeignAndKeepsLastEpicComment(): void
    {
        $payload = self::approvedPayload('rev');
        $payload['data']['repository']['issue']['comments'] = ['totalCount' => 2, 'nodes' => [
            ['body' => 'q '.self::MARKER, 'createdAt' => '2026-01-01T10:00:00Z', 'author' => ['login' => 'epic-bot']],
            ['body' => 'fake '.self::MARKER, 'createdAt' => '2026-01-01T12:00:00Z', 'author' => ['login' => 'stranger']],
        ]];
        $s = self::clientFor($payload, ['compare/' => ['behind_by' => 0]])->taskState('r#1', self::MARKER);
        self::assertSame(strtotime('2026-01-01T10:00:00Z'), $s->lastEpicComment);
        self::assertSame(1, $s->foreignComments);
    }

    public function testEpicMarkerFromStrangerInPrCommentIsForeign(): void
    {
        $fake = ['body' => 'fake '.self::MARKER, 'createdAt' => '2026-01-01T12:00:00Z', 'author' => ['login' => 'stranger']];
        $s = self::clientFor(self::approvedPayload('rev', [$fake]), ['compare/' => ['behind_by' => 0]])->taskState('r#1', self::MARKER);
        self::assertSame(1, $s->foreignComments);
        self::assertTrue($s->changedAfterReview);
    }

    public function testChangesRequestedAreCountedPerReviewedCommit(): void
    {
        $cr = static fn (string $oid, string $at): array => ['author' => ['login' => self::BOT], 'body' => 'changes '.self::MARKER, 'state' => 'CHANGES_REQUESTED', 'submittedAt' => $at, 'commit' => ['oid' => $oid]];
        $payload = self::approvedPayload('rev', [], [
            $cr('c1', '2026-01-01T12:00:00Z'),
            $cr('c1', '2026-01-01T11:00:00Z'),
            $cr('c2', '2026-01-01T13:00:00Z'),
        ]);
        $s = self::clientFor($payload, ['compare/' => ['behind_by' => 0]])->taskState('r#1', self::MARKER);
        // the fixture's own review on 'old' (09:00) is a third commit; c1 and c2 add two more rounds
        self::assertSame(3, $s->epicChangesRequested);
        self::assertSame([
            strtotime('2026-01-01T09:00:00Z'),
            strtotime('2026-01-01T12:00:00Z'),
            strtotime('2026-01-01T13:00:00Z'),
        ], $s->epicChangesRequestedAt);
    }

    public function testChangesRequestedWithUnknownCommitAreCountedSeparately(): void
    {
        $noCommit = ['author' => ['login' => self::BOT], 'body' => 'changes '.self::MARKER, 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-01-01T12:00:00Z'];
        $nullCommit = ['commit' => null, 'submittedAt' => '2026-01-01T13:00:00Z'] + $noCommit;
        $s = self::clientFor(self::approvedPayload('rev', [], [$noCommit, $nullCommit]), ['compare/' => ['behind_by' => 0]])->taskState('r#1', self::MARKER);
        // the fixture's own review on 'old' plus two reviews of an unknown commit
        self::assertSame(3, $s->epicChangesRequested);
    }

    public function testEpicMarkerReviewFromStrangerIsNotAVerdict(): void
    {
        $fake = ['author' => ['login' => 'stranger'], 'body' => 'no '.self::MARKER, 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-01-01T12:00:00Z', 'commit' => ['oid' => 'rev']];
        $s = self::clientFor(self::approvedPayload('rev', [], [$fake]), ['compare/' => ['behind_by' => 0]])->taskState('r#1', self::MARKER);
        self::assertSame('APPROVED', $s->epicVerdict);
        self::assertSame(1, $s->epicChangesRequested);
        self::assertTrue($s->changedAfterReview);
    }

    public function testSelfLoginIsFetchedOnce(): void
    {
        $calls = 0;
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use (&$calls): array {
            if ($cmd === ['gh', 'api', 'user']) {
                ++$calls;

                return ['exit' => 0, 'stdout' => '{"login":"epic-bot"}', 'stderr' => ''];
            }

            return ['exit' => 0, 'stdout' => (string) json_encode(self::approvedPayload('rev')), 'stderr' => ''];
        });
        $gh->taskState('r#1', self::MARKER);
        $gh->taskState('r#1', self::MARKER);
        self::assertSame(1, $calls);
    }

    public function testSelfLoginFailureThrows(): void
    {
        $gh = new GhClient(new GithubOwners(['o']), 5, static fn (array $cmd): array => $cmd === ['gh', 'api', 'user']
            ? ['exit' => 1, 'stdout' => '', 'stderr' => 'bad credentials']
            : ['exit' => 0, 'stdout' => (string) json_encode(self::approvedPayload('rev')), 'stderr' => '']);
        $this->expectException(\RuntimeException::class);
        $gh->taskState('r#1', self::MARKER);
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private static function cmp(array $files = [], string $status = 'ahead'): array
    {
        return ['status' => $status, 'files' => array_map(static fn (string $p, string $blob): array => ['filename' => $p, 'status' => 'modified', 'sha' => $blob], array_keys($files), $files)];
    }

    /**
     * @param array<string, mixed> $rest
     */
    private function masterOnly(array $rest, ?string $coverage = 'rev'): bool
    {
        $rest += ['compare/master...m1' => ['behind_by' => 0]];

        return self::clientFor(self::approvedPayload('m1'), $rest)->taskState('r#1', self::MARKER, $coverage)->headOnlyMasterMerge;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mergeRest(string $mergeFile, string $p2File): array
    {
        return [
            'compare/rev...m1' => ['status' => 'ahead', 'commits' => [['sha' => 'm1', 'parents' => [['sha' => 'rev'], ['sha' => 'p2']]]]] + self::cmp([$mergeFile => 'B']),
            'compare/p2...master' => ['status' => 'ahead'],
            'compare/rev...p2' => self::cmp([$p2File => 'B']),
            'compare/p2...rev' => self::cmp(['other' => 'X']),
            'compare/p2...m1' => self::cmp([]),
        ];
    }

    public function testCleanMasterMergeIsRecognised(): void
    {
        // the merge commit brought in exactly file f with the same blob as in p2
        self::assertTrue($this->masterOnly(self::mergeRest('f', 'f')));
    }

    public function testReviewHeadEqualToHeadIsNotMasterOnlyWithoutProofFromCoverageHead(): void
    {
        // GitHub moved the approval to the head (review commit == head), but content after coverage_head is not from master
        $rest = self::mergeRest('evil', 'f');
        $payload = self::approvedPayload('m1');
        $payload['data']['repository']['issue']['closedByPullRequestsReferences']['nodes'][0]['reviews']['nodes'][1]['commit']['oid'] = 'm1';
        $gh = self::clientFor($payload, $rest + ['compare/master...m1' => ['behind_by' => 0]]);
        $s = $gh->taskState('r#1', self::MARKER, 'rev');
        self::assertSame('m1', $s->epicReviewHead);
        self::assertFalse($s->headOnlyMasterMerge);
        self::assertFalse($s->headAccepted('rev'));
    }

    public function testNoCoverageHeadIsNeverMasterOnly(): void
    {
        self::assertFalse($this->masterOnly(self::mergeRest('f', 'f'), null));
    }

    public function testMergeWithForeignContentIsNotMasterOnly(): void
    {
        // a file that is not among the master changes — an edit inside the merge commit
        self::assertFalse($this->masterOnly(self::mergeRest('evil', 'f')));
    }

    public function testMergeWithDifferentBlobIsNotMasterOnly(): void
    {
        $rest = self::mergeRest('f', 'f');
        $rest['compare/rev...p2'] = self::cmp(['f' => 'C']);
        self::assertFalse($this->masterOnly($rest));
    }

    public function testSecondParentOffMasterIsNotMasterOnly(): void
    {
        $rest = self::mergeRest('f', 'f');
        $rest['compare/p2...master'] = ['status' => 'diverged'];
        self::assertFalse($this->masterOnly($rest));
    }

    public function testPlainCommitAfterApproveIsNotMasterOnly(): void
    {
        $rest = self::mergeRest('f', 'f');
        $rest['compare/rev...m1']['commits'] = [['sha' => 'm1', 'parents' => [['sha' => 'rev']]]];
        self::assertFalse($this->masterOnly($rest));
    }

    /**
     * Clean merge m1 of rev with master commits (a plain one and a merge of a PR into master) listed by compare.
     *
     * @return array<string, mixed>
     */
    private static function mergeWithMasterCommitsRest(): array
    {
        $rest = self::mergeRest('f', 'f');
        $rest['compare/rev...m1']['commits'] = [
            ['sha' => 'c1', 'parents' => [['sha' => 'c0']]],
            ['sha' => 'p2', 'parents' => [['sha' => 'c1'], ['sha' => 'c2']]],
            ['sha' => 'c2', 'parents' => [['sha' => 'c0']]],
            ['sha' => 'm1', 'parents' => [['sha' => 'rev'], ['sha' => 'p2']]],
        ];

        return $rest;
    }

    public function testMasterCommitsInCompareDoNotBreakTheChain(): void
    {
        $rest = self::mergeWithMasterCommitsRest();
        $rest += ['compare/master...m1' => ['behind_by' => 0]];
        $s = self::clientFor(self::approvedPayload('m1'), $rest)->taskState('r#1', self::MARKER, 'rev');
        self::assertTrue($s->headOnlyMasterMerge);
        self::assertTrue($s->headAccepted('rev'));
    }

    /**
     * Chain `rev <- x1 <- m1` where m1 is a clean master merge with p2 and the first parent of m1 is $first.
     *
     * @param list<array{sha: string, parents: list<array{sha: string}>}> $firstCommits commits describing $first
     *
     * @return array<string, mixed>
     */
    private static function chainRest(string $first, array $firstCommits): array
    {
        $rest = self::mergeWithMasterCommitsRest();
        $rest['compare/rev...m1']['commits'] = [
            ['sha' => 'c1', 'parents' => [['sha' => 'c0']]],
            ['sha' => 'p2', 'parents' => [['sha' => 'c1'], ['sha' => 'c2']]],
            ['sha' => 'c2', 'parents' => [['sha' => 'c0']]],
            ['sha' => 'm1', 'parents' => [['sha' => $first], ['sha' => 'p2']]],
            ...$firstCommits,
        ];
        $rest += [
            "compare/{$first}...m1" => self::cmp(['f' => 'B']),
            "compare/{$first}...p2" => self::cmp(['f' => 'B']),
            "compare/p2...{$first}" => self::cmp([]),
        ];

        return $rest;
    }

    public function testPlainCommitOnFirstParentChainAmongMasterCommitsIsNotMasterOnly(): void
    {
        // m1 is a clean master merge, the walk has to go on to x1 and fail there
        $rest = self::chainRest('x1', [['sha' => 'x1', 'parents' => [['sha' => 'rev']]]]);
        self::assertFalse($this->masterOnly($rest));
    }

    public function testMasterMergeOnFirstParentChainAmongMasterCommitsIsMasterOnly(): void
    {
        // the same layout, x1 is a master merge itself: the walk reaches the second step and succeeds
        $rest = self::chainRest('x1', [['sha' => 'x1', 'parents' => [['sha' => 'rev'], ['sha' => 'p2']]]]);
        $rest += ['compare/rev...x1' => self::cmp(['f' => 'B'])];
        self::assertTrue($this->masterOnly($rest));
    }

    public function testChainNotReachingCoverageHeadIsNotMasterOnly(): void
    {
        // m1 is a clean merge, its first parent is not in the compare list
        self::assertFalse($this->masterOnly(self::chainRest('gone', [])));
    }

    public function testChainWithUnknownFirstParentAndNoStepsLeftIsNotMasterOnly(): void
    {
        // the only commit is a clean merge: steps run out on a sha other than coverage_head
        $rest = self::chainRest('gone', []);
        $rest['compare/rev...m1']['commits'] = [['sha' => 'm1', 'parents' => [['sha' => 'gone'], ['sha' => 'p2']]]];
        self::assertFalse($this->masterOnly($rest));
    }

    public function testReversedParentsAreNotMasterOnly(): void
    {
        $rest = self::mergeWithMasterCommitsRest();
        $rest['compare/rev...m1']['commits'][3] = ['sha' => 'm1', 'parents' => [['sha' => 'p2'], ['sha' => 'rev']]];
        $rest['compare/rev...master'] = ['status' => 'diverged'];
        self::assertFalse($this->masterOnly($rest));
    }

    public function testTwoConsecutiveMasterMergesAreMasterOnly(): void
    {
        $rest = self::mergeWithMasterCommitsRest();
        $rest['compare/rev...m1']['commits'] = [
            ['sha' => 'c1', 'parents' => [['sha' => 'c0']]],
            ['sha' => 'q1', 'parents' => [['sha' => 'c1']]],
            ['sha' => 'n1', 'parents' => [['sha' => 'rev'], ['sha' => 'q1']]],
            ['sha' => 'q2', 'parents' => [['sha' => 'q1']]],
            ['sha' => 'm1', 'parents' => [['sha' => 'n1'], ['sha' => 'q2']]],
        ];
        $rest += [
            'compare/q1...master' => ['status' => 'ahead'],
            'compare/rev...q1' => self::cmp(['f' => 'B']),
            'compare/q1...rev' => self::cmp(['other' => 'X']),
            'compare/q1...n1' => self::cmp([]),
            'compare/rev...n1' => self::cmp(['f' => 'B']),
            'compare/n1...m1' => self::cmp(['f' => 'B']),
            'compare/q2...master' => ['status' => 'ahead'],
            'compare/n1...q2' => self::cmp(['f' => 'B']),
            'compare/q2...n1' => self::cmp(['other' => 'X']),
            'compare/q2...m1' => self::cmp([]),
        ];
        self::assertTrue($this->masterOnly($rest));
    }

    public function testCompareFailureAfterForcePushDoesNotThrow(): void
    {
        // compare 404 (force-push): no REST responses at all — the epic poll does not fail
        $s = self::clientFor(self::approvedPayload('m1'))->taskState('r#1', self::MARKER);
        self::assertFalse($s->headOnlyMasterMerge);
        self::assertFalse($s->behindMaster);
        self::assertSame('APPROVED', $s->epicVerdict);
    }

    public function testColdFindingsCollectOnlyColdReviewerWithPaginationAndIds(): void
    {
        $bot = ['login' => 'claude[bot]'];
        $human = ['login' => 'jane-doe'];
        $page1 = [];
        for ($i = 1; $i <= 100; ++$i) {
            $page1[] = ['user' => $i === 1 ? $bot : $human, 'path' => 'src/A.php', 'line' => 10, 'body' => "inline {$i}"];
        }
        $pages = [
            'repos/anime-db/a/pulls/5/comments?per_page=100&page=1' => $page1,
            'repos/anime-db/a/pulls/5/comments?per_page=100&page=2' => [
                ['user' => $bot, 'path' => 'src/B.php', 'line' => null, 'original_line' => 7, 'body' => 'second page'],
                ['user' => ['login' => 'openronin'], 'path' => 'src/B.php', 'line' => 8, 'body' => 'addressed'],
            ],
            'repos/anime-db/a/pulls/5/reviews?per_page=100&page=1' => [
                ['user' => $bot, 'body' => 'review summary'],
                ['user' => $bot, 'body' => ''],
                ['user' => $human, 'body' => 'LGTM'],
            ],
            'repos/anime-db/a/issues/5/comments?per_page=100&page=1' => [
                ['user' => $bot, 'body' => 'issue-level note'],
                ['user' => $human, 'body' => 'thanks'],
            ],
        ];
        $calls = [];
        $gh = new GhClient(new GithubOwners(['anime-db']), 15, function (array $cmd) use ($pages, &$calls): array {
            $calls[] = $cmd;

            return ['exit' => 0, 'stdout' => json_encode($pages[$cmd[2]] ?? []), 'stderr' => ''];
        });

        $found = $gh->coldFindings('a#5');

        self::assertSame([
            ['id' => 'C1', 'source' => 'inline', 'path' => 'src/A.php', 'line' => 10, 'body' => 'inline 1'],
            ['id' => 'C2', 'source' => 'inline', 'path' => 'src/B.php', 'line' => 7, 'body' => 'second page'],
            ['id' => 'C3', 'source' => 'review', 'path' => null, 'line' => null, 'body' => 'review summary'],
            ['id' => 'C4', 'source' => 'comment', 'path' => null, 'line' => null, 'body' => 'issue-level note'],
        ], $found);
        self::assertCount(4, $calls);
        foreach ($calls as $cmd) {
            self::assertSame(['gh', 'api'], array_slice($cmd, 0, 2));
        }
    }

    /**
     * @param list<array{exit:int, stdout:string, stderr:string}> $graphql responses to graphql calls in order
     * @param list<list<string>>                                  $calls
     */
    private function prResolver(array $graphql, array &$calls, int &$now): GhClient
    {
        return new GhClient(new GithubOwners(['anime-db']), 15, function (array $cmd) use (&$graphql, &$calls): array {
            $calls[] = $cmd;
            if ($cmd[1] === 'repo') {
                return ['exit' => 0, 'stdout' => '[{"name":"a"},{"name":"b"}]', 'stderr' => ''];
            }

            return array_shift($graphql) ?? ['exit' => 1, 'stdout' => '', 'stderr' => 'no fixture'];
        }, static function () use (&$now): int {
            return $now;
        });
    }

    public function testOpenPrReposTreatsNotFoundWithNonZeroExitAsNormal(): void
    {
        $body = json_encode([
            'data' => [
                'r0n5' => ['pullRequest' => ['state' => 'OPEN']],
                'r1n5' => ['pullRequest' => null],
                'r0n6' => ['pullRequest' => ['state' => 'MERGED']],
                'r1n6' => ['pullRequest' => null],
            ],
            'errors' => [
                ['type' => 'NOT_FOUND', 'path' => ['r1n5', 'pullRequest'], 'message' => 'Could not resolve to a PullRequest'],
                ['type' => 'NOT_FOUND', 'path' => ['r1n6', 'pullRequest'], 'message' => 'Could not resolve to a PullRequest'],
            ],
        ]);
        $calls = [];
        $now = 1000;
        $gh = $this->prResolver([['exit' => 1, 'stdout' => (string) $body, 'stderr' => 'gh: Could not resolve']], $calls, $now);

        self::assertSame([5 => ['a'], 6 => []], $gh->openPrRepos([5, 6]));
        $graphql = array_values(array_filter($calls, static fn (array $c): bool => $c[1] === 'api'));
        self::assertCount(1, $graphql);
        self::assertSame(['gh', 'repo', 'list', 'anime-db', '--limit', '1000', '--no-archived', '--json', 'name'], $calls[0]);
        self::assertSame(['gh', 'api', 'graphql', '-f'], array_slice($graphql[0], 0, 4));
        self::assertStringContainsString('r0n5:repository(owner:"anime-db",name:"a"){pullRequest(number:5){state}}', $graphql[0][4]);
        self::assertStringContainsString('r1n6:repository(owner:"anime-db",name:"b"){pullRequest(number:6){state}}', $graphql[0][4]);
    }

    public function testOpenPrReposFailsOnOtherErrorsOrNoData(): void
    {
        $now = 1000;
        $calls = [];
        $other = json_encode(['data' => ['r0n5' => null], 'errors' => [['type' => 'FORBIDDEN', 'path' => ['r0n5'], 'message' => 'x']]]);
        $gh = $this->prResolver([['exit' => 1, 'stdout' => (string) $other, 'stderr' => '']], $calls, $now);
        try {
            $gh->openPrRepos([5]);
            self::fail('expected failure');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $calls = [];
        $gh = $this->prResolver([['exit' => 1, 'stdout' => '', 'stderr' => 'network down']], $calls, $now);
        $this->expectException(\RuntimeException::class);
        $gh->openPrRepos([5]);
    }

    public function testRepoListIsCachedForAnHour(): void
    {
        $empty = ['exit' => 0, 'stdout' => '{"data":{}}', 'stderr' => ''];
        $calls = [];
        $now = 1000;
        $gh = $this->prResolver([$empty, $empty, $empty], $calls, $now);

        $gh->openPrRepos([1]);
        $now = 1000 + 3599;
        $gh->openPrRepos([2]);
        $lists = static function () use (&$calls): int {
            return count(array_filter($calls, static fn (array $c): bool => $c[1] === 'repo'));
        };
        self::assertSame(1, $lists());

        $now = 1000 + 3600;
        $gh->openPrRepos([3]);
        self::assertSame(2, $lists());
    }

    /**
     * @param array<string, mixed>                          $head
     * @param array{exit:int, stdout:string, stderr:string} $delete
     *
     * @return list<list<string>> executed commands
     */
    private static function mergeCalls(array $head, array $delete = ['exit' => 0, 'stdout' => '', 'stderr' => ''], bool $merged = true): array
    {
        /** @var list<list<string>> $calls */
        $calls = [];
        $pr = ['merged' => $merged, 'head' => $head, 'base' => ['repo' => ['full_name' => 'o/r', 'default_branch' => 'master']]];
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use (&$calls, $pr, $delete): array {
            $calls[] = array_values($cmd);
            if (in_array('DELETE', $cmd, true)) {
                return $delete;
            }

            return ['exit' => 0, 'stdout' => $cmd[1] === 'api' ? (string) json_encode($pr) : '', 'stderr' => ''];
        });

        $gh->merge('r#1', 7);

        return $calls;
    }

    public function testMergeDeletesBranchOfSameRepoPr(): void
    {
        $calls = self::mergeCalls(['ref' => 'openronin/7-x y', 'repo' => ['full_name' => 'o/r']]);

        self::assertSame(['gh', 'pr', 'merge', '7', '--merge', '--repo', 'o/r'], $calls[0]);
        self::assertSame(['gh', 'api', 'repos/o/r/pulls/7'], $calls[1]);
        self::assertSame(['gh', 'api', '-X', 'DELETE', 'repos/o/r/git/refs/heads/openronin/7-x%20y'], $calls[2]);
    }

    public function testMergeKeepsBranchOfForkPr(): void
    {
        self::assertCount(2, self::mergeCalls(['ref' => 'feature', 'repo' => ['full_name' => 'fork/r']]));
        self::assertCount(2, self::mergeCalls(['ref' => 'feature', 'repo' => null]));
    }

    public function testMergeKeepsBranchOfNotYetMergedPr(): void
    {
        $head = ['ref' => 'openronin/7-x', 'repo' => ['full_name' => 'o/r']];

        self::assertCount(2, self::mergeCalls($head, ['exit' => 0, 'stdout' => '', 'stderr' => ''], false));
    }

    public function testMergeKeepsDefaultBranch(): void
    {
        self::assertCount(2, self::mergeCalls(['ref' => 'master', 'repo' => ['full_name' => 'o/r']]));
    }

    public function testMergeIgnoresDeleteErrors(): void
    {
        $head = ['ref' => 'openronin/7-x', 'repo' => ['full_name' => 'o/r']];

        self::assertCount(3, self::mergeCalls($head, ['exit' => 1, 'stdout' => '', 'stderr' => 'gh: Reference does not exist (HTTP 422)']));
        self::assertCount(3, self::mergeCalls($head, ['exit' => 1, 'stdout' => '', 'stderr' => 'gh: boom (HTTP 500)']));
        self::assertCount(3, self::mergeCalls($head, ['exit' => 1, 'stdout' => '', 'stderr' => 'gh: Not Found (HTTP 404)']));
    }

    public function testMergeIgnoresPrLookupFailure(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;

            return $cmd[1] === 'api' ? ['exit' => 1, 'stdout' => '', 'stderr' => 'down'] : ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $gh->merge('r#1', 7);

        self::assertCount(2, $calls);
    }

    public function testMergeFailureSkipsBranchDeletion(): void
    {
        $calls = [];
        $gh = new GhClient(new GithubOwners(['o']), 5, static function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;

            return ['exit' => 1, 'stdout' => '', 'stderr' => 'not mergeable'];
        });

        try {
            $gh->merge('r#1', 7);
            self::fail('merge error must propagate');
        } catch (\RuntimeException) {
            self::assertCount(1, $calls);
        }
    }
}
