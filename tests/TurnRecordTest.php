<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\TurnRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The neutral turn record: what the parser of an engine stream hands to the post-run checks, and
 * what it drops on the way.
 */
#[CoversClass(TurnRecord::class)]
final class TurnRecordTest extends TestCase
{
    public function testOnlyShellCallsWithACommandBecomeCallsOfTheTurn(): void
    {
        $turn = TurnRecord::fromToolCalls([
            ['tool' => 'read', 'command' => null, 'status' => 'completed'],
            ['tool' => 'shell', 'command' => 'ls', 'status' => 'completed'],
            ['tool' => 'shell', 'command' => null, 'status' => 'completed'],
            ['tool' => 'shell', 'command' => '', 'status' => 'completed'],
            ['tool' => 'grep', 'command' => 'ls', 'status' => 'completed'],
        ], 'done');

        self::assertSame(['ls'], $turn->commands());
        self::assertSame('done', $turn->finalText);
    }

    public function testAFailedCallStaysInTheRecordButIsNotSuccessful(): void
    {
        $turn = TurnRecord::fromToolCalls([
            ['tool' => 'shell', 'command' => 'first', 'status' => TurnRecord::STATUS_ERROR],
            ['tool' => 'shell', 'command' => 'second', 'status' => 'completed'],
        ], '');

        self::assertSame(['first', 'second'], $turn->commands(), 'the call order is kept');
        self::assertSame(['second'], $turn->successfulCommands());
        self::assertSame(
            [['command' => 'first', 'status' => TurnRecord::STATUS_ERROR]],
            [$turn->shellCalls[0]],
        );
    }

    public function testACallTheStreamGivesNoStatusForIsNotSuccessful(): void
    {
        $turn = TurnRecord::fromToolCalls([['tool' => 'shell', 'command' => 'ls', 'status' => null]], '');

        self::assertSame(['ls'], $turn->commands());
        self::assertSame([], $turn->successfulCommands(), 'an unknown status produced nothing, so it is not a published status');
    }
}
