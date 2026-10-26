<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Epic\Plan;
use GpsLab\WorkspaceAssistant\Epic\Signal;
use GpsLab\WorkspaceAssistant\GithubOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plan::class)]
#[CoversClass(Signal::class)]
final class PlanTest extends TestCase
{
    private static function md(string $json): string
    {
        return "# Plan\n\nText.\n\n```json\n{$json}\n```\n";
    }

    public function testParsesTasksAndReleases(): void
    {
        $plan = Plan::parse(self::md('{"tasks":[{"task":"anime-db-plugins#12"},{"task":"tg-helper#3","after":["anime-db-plugins#12"]}],'
            .'"releases":[{"repo":"anime-db-plugins","version":"v1.2.0","after":["anime-db-plugins#12"]}]}'), new GithubOwners(['anime-db']));

        self::assertSame(
            [['task' => 'anime-db-plugins#12', 'after' => []], ['task' => 'tg-helper#3', 'after' => ['anime-db-plugins#12']]],
            $plan->tasks,
        );
        self::assertSame([['repo' => 'anime-db-plugins', 'version' => 'v1.2.0', 'after' => ['anime-db-plugins#12']]], $plan->releases);
    }

    public function testParsesFullAndShortFormsOfTasksAndReleases(): void
    {
        $owners = new GithubOwners(['anime-db', 'gpslab']);
        $plan = Plan::parse(self::md('{"tasks":[{"task":"anime-db/a#1"},{"task":"gpslab/b#2","after":["a#1"]}],'
            .'"releases":[{"repo":"anime-db/a","version":"v1.0.0","after":["a#1"]},{"repo":"gpslab/b","version":"v2.0.0","after":["gpslab/b#2"]}]}'), $owners);

        self::assertSame([['task' => 'a#1', 'after' => []], ['task' => 'gpslab/b#2', 'after' => ['a#1']]], $plan->tasks);
        self::assertSame([
            ['repo' => 'a', 'version' => 'v1.0.0', 'after' => ['a#1']],
            ['repo' => 'gpslab/b', 'version' => 'v2.0.0', 'after' => ['gpslab/b#2']],
        ], $plan->releases);
    }

    public function testShortAndDefaultOwnerFormsAreOneTask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('listed twice');

        Plan::parse(self::md('{"tasks":[{"task":"a#1"},{"task":"anime-db/a#1"}]}'), new GithubOwners(['anime-db']));
    }

    public function testReleaseWithoutPlanTaskIsRejectedAcrossForms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('which is not in the plan');

        Plan::parse(self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","version":"v1.0.0","after":["gpslab/a#1"]}]}'), new GithubOwners(['anime-db', 'gpslab']));
    }

    public function testForeignOwnerIsRejected(): void
    {
        foreach ([
            '{"tasks":[{"task":"foreign/a#1"}]}',
            '{"tasks":[{"task":"a#1","after":["foreign/b#2"]}]}',
            '{"tasks":[{"task":"a#1"}],"releases":[{"repo":"foreign/x","version":"v1.0.0","after":["a#1"]}]}',
        ] as $json) {
            try {
                Plan::parse(self::md($json), new GithubOwners(['anime-db']));
                self::fail('foreign owner must be rejected: '.$json);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('GITHUB_OWNERS', $e->getMessage());
            }
        }
    }

    public function testPlanRequiresOwnersWhenCheckedAgainstConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(GithubOwners::NOT_SET);

        Plan::parse(self::md('{"tasks":[{"task":"a#1"}]}'), new GithubOwners());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPlans(): iterable
    {
        yield 'no json block' => ["# Plan without a block\n```php\necho 1;\n```\n", 'has no ```json block'];
        yield 'invalid json' => [self::md('{"tasks": [}'), 'cannot be parsed'];
        yield 'task is not repo#N' => [self::md('{"tasks":[{"task":"just-text"}]}'), 'repo#N'];
        yield 'task without a number' => [self::md('{"tasks":[{"task":"repo#0"}]}'), 'repo#N'];
        yield 'after is not from the plan' => [self::md('{"tasks":[{"task":"a#1","after":["b#2"]}]}'), 'not in the plan'];
        yield 'release after is not from the plan' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","version":"v1.0.0","after":["b#2"]}]}'), 'not in the plan'];
        yield 'release without repo' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"version":"v1.0.0","after":["a#1"]}]}'), 'repo'];
        yield 'release without version' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","after":["a#1"]}]}'), 'version'];
        yield 'release without after' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","version":"v1.0.0"}]}'), 'after'];
        yield 'release version without the v prefix' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","version":"0.27.2","after":["a#1"]}]}'), 'vX.Y.Z'];
        yield 'release version is not three numbers' => [self::md('{"tasks":[{"task":"a#1"}],"releases":[{"repo":"a","version":"v1.2","after":["a#1"]}]}'), 'vX.Y.Z'];
        yield 'cycle in after' => [self::md('{"tasks":[{"task":"a#1","after":["b#2"]},{"task":"b#2","after":["a#1"]}]}'), 'cycle in after'];
        yield 'self-dependency' => [self::md('{"tasks":[{"task":"a#1","after":["a#1"]}]}'), 'cycle in after'];
        yield 'empty task list' => [self::md('{"tasks":[]}'), 'tasks'];
        yield 'duplicate task' => [self::md('{"tasks":[{"task":"a#1"},{"task":"a#1"}]}'), 'listed twice'];
    }

    #[DataProvider('invalidPlans')]
    public function testInvalidPlanIsRejectedWithReason(string $markdown, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/u');

        Plan::parse($markdown, new GithubOwners(['anime-db']));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plan file not found');

        Plan::load(sys_get_temp_dir().'/no-such-plan-'.uniqid('', true).'.md', new GithubOwners(['anime-db']));
    }

    public function testSignalParsesAllFields(): void
    {
        $signal = Signal::parse('{"added":[{"task":"a#5","after":["a#1"]}],"hold":["a#6"],"message":"question","blocked":"stuck","done":true}', new GithubOwners(['anime-db']));

        self::assertSame([['task' => 'a#5', 'after' => ['a#1']]], $signal->added);
        self::assertSame(['a#6'], $signal->hold);
        self::assertSame('question', $signal->message);
        self::assertSame('stuck', $signal->blocked);
        self::assertTrue($signal->done);
    }

    public function testEmptySignalIsValid(): void
    {
        $signal = Signal::parse('{}', new GithubOwners(['anime-db']));

        self::assertSame([], $signal->added);
        self::assertNull($signal->hold);
        self::assertNull($signal->message);
        self::assertFalse($signal->done);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSignals(): iterable
    {
        yield 'not JSON' => ['this is not json'];
        yield 'list' => ['[1,2]'];
        yield 'added is not a list' => ['{"added":"a#1"}'];
        yield 'added without task' => ['{"added":[{"after":[]}]}'];
        yield 'added with a bad task' => ['{"added":[{"task":"x"}]}'];
        yield 'hold is not repo#N' => ['{"hold":["oops"]}'];
        yield 'message is not a string' => ['{"message":5}'];
        yield 'done is not a bool' => ['{"done":"yes"}'];
    }

    #[DataProvider('invalidSignals')]
    public function testInvalidSignalIsRejected(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Signal::parse($json, new GithubOwners(['anime-db']));
    }
}
