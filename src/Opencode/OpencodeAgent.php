<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * One sub-agent of a run, in the engine-neutral shape the OpenCode renderer consumes.
 *
 * `name` is the agent id OpenCode addresses the sub-agent by. `description` and `system`
 * become the `description` and the system prompt of the rendered markdown agent. `tools`
 * are Claude Code tool names (Read, Grep, Glob, Bash, Write, Edit); the renderer maps them
 * to OpenCode permissions. There is deliberately no `model`: OpenCode ignores a per-agent
 * model, every sub-agent inherits the run's model.
 */
final class OpencodeAgent
{
    /**
     * @param list<string> $tools Claude Code tool names
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $system,
        public readonly array $tools,
    ) {
    }
}
