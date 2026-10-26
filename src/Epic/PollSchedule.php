<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

/**
 * Timing of the epic poll: the Telegram long-poll timeout and the slow-pass log line.
 * Pure functions — the current time is a parameter.
 */
final class PollSchedule
{
    /** A poll pass longer than this many seconds is logged. */
    public const int SLOW_PASS_SEC = 10;

    /**
     * getUpdates timeout: 0 if there is something to do without waiting; otherwise a long-poll,
     * shortened to the nearest timer (limit pause / retry) and, while an epic is running,
     * to the next epic poll. The epic cap never produces 0: the zero branches keep their `usleep`,
     * a cap of 0 would spin the loop.
     *
     * @param int      $blockedUntil  unix time the queue is paused until (0 — not paused)
     * @param int|null $epicPollDueAt unix time the next epic poll is due; null — no running epic, no cap
     */
    public static function longPollTimeout(
        bool $busy,
        bool $queueEmpty,
        int $blockedUntil,
        bool $treePaused,
        int $pollTimeout,
        ?int $epicPollDueAt,
        int $now,
    ): int {
        if ($busy) {
            return 0;
        }
        $cap = static fn (int $timeout): int => $epicPollDueAt === null ? $timeout : min($timeout, max(1, $epicPollDueAt - $now));

        if ($queueEmpty) {
            return $cap($pollTimeout);
        }
        if ($blockedUntil > 0) {
            return $cap(max(1, min($pollTimeout, $blockedUntil - $now)));
        }

        return $treePaused ? $cap($pollTimeout) : 0;
    }

    /** The log line for a poll pass, or null if the pass was not slow. */
    public static function slowPassMessage(float $seconds, int $epics): ?string
    {
        if ($seconds <= self::SLOW_PASS_SEC) {
            return null;
        }

        return sprintf('epic poll pass took %.1f s for %d epic(s)', $seconds, $epics);
    }
}
