<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Logging to STDERR with a timestamp.
 *
 * Format: `[2026-06-24 19:00:00+03:00] [workspace-assistant] message`.
 * The offset in the stamp (`P`) makes the line unambiguous regardless of the
 * PHP date.timezone setting — logs can be correlated by time.
 */
final class Log
{
    public static function line(string $message): void
    {
        fwrite(STDERR, '['.date('Y-m-d H:i:sP').'] [workspace-assistant] '.$message."\n");
    }
}
