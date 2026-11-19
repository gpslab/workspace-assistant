<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * Token counters of one OpenCode run, summed over its `step_finish` events.
 *
 * OpenCode reports the tokens of each step in `step_finish.part.tokens` (`input`, `output`,
 * `reasoning`, `cache.read`, `cache.write`). Unlike Claude Code's cumulative `result` event there is
 * no per-run snapshot, so the run figure is the sum over all its steps.
 */
final class OpencodeTokens
{
    public function __construct(
        public readonly int $input,
        public readonly int $output,
        public readonly int $reasoning,
        public readonly int $cacheRead,
        public readonly int $cacheWrite,
    ) {
    }
}
