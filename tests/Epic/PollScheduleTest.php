<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Epic\PollSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PollSchedule::class)]
final class PollScheduleTest extends TestCase
{
    private const int NOW = 1000;

    public function testEmptyQueueIsCappedByNextPoll(): void
    {
        self::assertSame(12, PollSchedule::longPollTimeout(false, true, 0, false, 30, self::NOW + 12, self::NOW));
    }

    public function testEmptyQueueKeepsOwnValueWhenCapIsLarger(): void
    {
        self::assertSame(30, PollSchedule::longPollTimeout(false, true, 0, false, 30, self::NOW + 100, self::NOW));
    }

    public function testRateLimitPauseReturnsMinOfOwnValueAndCap(): void
    {
        self::assertSame(5, PollSchedule::longPollTimeout(false, false, self::NOW + 20, false, 30, self::NOW + 5, self::NOW));
        self::assertSame(8, PollSchedule::longPollTimeout(false, false, self::NOW + 8, false, 30, self::NOW + 25, self::NOW));
    }

    public function testTreePausedIsCapped(): void
    {
        self::assertSame(7, PollSchedule::longPollTimeout(false, false, 0, true, 30, self::NOW + 7, self::NOW));
    }

    public function testNoRunningEpicKeepsValuesAsToday(): void
    {
        self::assertSame(30, PollSchedule::longPollTimeout(false, true, 0, false, 30, null, self::NOW));
        self::assertSame(20, PollSchedule::longPollTimeout(false, false, self::NOW + 20, false, 30, null, self::NOW));
        self::assertSame(30, PollSchedule::longPollTimeout(false, false, 0, true, 30, null, self::NOW));
    }

    public function testZeroBranchesStayZero(): void
    {
        self::assertSame(0, PollSchedule::longPollTimeout(true, false, 0, false, 30, self::NOW + 5, self::NOW));
        self::assertSame(0, PollSchedule::longPollTimeout(false, false, 0, false, 30, self::NOW + 5, self::NOW));
    }

    public function testCapNeverProducesZero(): void
    {
        foreach ([self::NOW, self::NOW - 50] as $due) {
            self::assertSame(1, PollSchedule::longPollTimeout(false, true, 0, false, 30, $due, self::NOW));
            self::assertSame(1, PollSchedule::longPollTimeout(false, false, self::NOW + 20, false, 30, $due, self::NOW));
            self::assertSame(1, PollSchedule::longPollTimeout(false, false, 0, true, 30, $due, self::NOW));
        }
    }

    public function testSlowPassIsLogged(): void
    {
        self::assertSame('epic poll pass took 12.5 s for 7 epic(s)', PollSchedule::slowPassMessage(12.46, 7));
    }

    public function testFastPassIsNotLogged(): void
    {
        self::assertNull(PollSchedule::slowPassMessage(10.0, 3));
        self::assertNull(PollSchedule::slowPassMessage(0.2, 1));
    }
}
