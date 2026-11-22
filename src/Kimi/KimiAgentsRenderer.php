<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

use GpsLab\WorkspaceAssistant\BuiltinAgents;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\InstanceAgents;

/**
 * Renders the agent files of a Kimi run's agents directory.
 *
 * Two sources feed it, both as the engine sees them: the bot's built-in agents
 * ({@see BuiltinAgents}) and the agents the instance defines itself ({@see InstanceAgents}).
 * Kimi reads project `.agents/agents` on its own, so that directory is not copied; it reads
 * neither `.claude/agents` nor `~/.claude/agents`, and those are rendered here. An instance agent
 * with the name of a built-in shadows it (Kimi reads the rendered directory before
 * `$HOME/.agents/agents` because `extra_agent_dirs` wins).
 *
 * The Claude `tools:` list is written as it is: Kimi's allowlist uses the same tool names. `model:`
 * is ignored: Kimi has no per-agent model field. The output files are relative to the agents
 * directory, keyed by the agent name `<name>.md`.
 *
 * Pure: it returns text and writes nothing.
 */
final class KimiAgentsRenderer
{
    /**
     * @param list<string> $warnings agent files that could not be used, collected by the loaders
     *
     * @return array<string, string> agent markdown keyed by the agent name, sorted by name
     *
     * @throws \RuntimeException when a shipped built-in agent file is missing (a broken installation)
     */
    public function render(string $repoPath, string $homeDir, array &$warnings = []): array
    {
        $builtin = BuiltinAgents::build($repoPath, $homeDir, Config::ENGINE_KIMI, $warnings);
        $instance = InstanceAgents::load($repoPath, $homeDir, Config::ENGINE_KIMI);

        $agents = [];
        foreach ($builtin as $name => $agent) {
            if (self::isValidName($name)) {
                $agents[$name] = self::markdown($name, $agent['description'], $agent['tools'], $agent['prompt']);
            }
        }
        foreach ($instance as $name => $agent) {
            if (self::isValidName($name)) {
                $agents[$name] = self::markdown($name, $agent['description'], $agent['tools'], $agent['prompt']);
            }
        }
        ksort($agents);

        return $agents;
    }

    /**
     * An agent name becomes a path segment (`<name>.md`) and a name Kimi addresses the sub-agent by,
     * so it must be one safe segment: letters, digits, `_` and `-`, starting with a letter. Every
     * built-in name matches; an instance name that does not is skipped rather than rendered outside
     * the directory.
     */
    private static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*$/i', $name) === 1;
    }

    /**
     * @param list<string>|null $tools Claude tool names; null means the agent is unrestricted, as an
     *                                 agent without `tools:` is on Claude
     */
    private static function markdown(string $name, string $description, ?array $tools, string $body): string
    {
        $text = "---\nname: ".$name."\ndescription: ".self::yamlString($description)."\n";
        if ($tools !== null) {
            // Kimi's agent front matter is YAML: a list, not the Claude comma-separated scalar.
            // The names are the same as Claude's, unchanged.
            $text .= "tools:\n";
            foreach ($tools as $tool) {
                $text .= '  - '.$tool."\n";
            }
        }

        return $text."---\n\n".trim($body)."\n";
    }

    /** A YAML double-quoted string: backslashes, double quotes and newlines are escaped. */
    private static function yamlString(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }
}
