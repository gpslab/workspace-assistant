<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Loader of the agent files the instance defines itself, shared by both engines.
 *
 * Three directories are read: the user-level `~/.claude/agents` of the bot's user, the
 * engine-neutral `<repo>/.agents/agents` and the Claude `<repo>/.claude/agents` of the managed
 * repository. A file is addressed by the `name:` of its front matter, not by its file name, and a
 * file in a later directory wins over one of the same name in an earlier one. Directories and files
 * are read through symlinks, so `.claude/agents` may itself be a symlink to a directory.
 *
 * Which directories belong to an engine is part of the engine, not of the file layout
 * ({@see directories()}): Claude Code loads `.claude/agents` and `~/.claude/agents` itself and
 * never reads `.agents/agents`. An agent there must therefore not shadow a built-in agent on
 * Claude — that engine would end up with no agent of that name at all, since nothing passes the
 * instance's file and the built-in is left out. On OpenCode the bot loads all three directories
 * itself (the engine reads none of them for agents) and renders them into the per-run config.
 */
final class InstanceAgents
{
    /** Engine-neutral agent directory of the managed repository; Claude Code never reads it. */
    public const string NEUTRAL_DIR = '.agents/agents';

    /** Agent directory of Claude Code, which that CLI loads on its own. */
    public const string CLAUDE_DIR = '.claude/agents';

    /**
     * The agent directories the engine loads, in precedence order: a file in a later directory
     * wins over one with the same `name:` in an earlier one.
     *
     * @return list<string>
     */
    public static function directories(string $repoPath, string $homeDir, string $engine): array
    {
        $dirs = [];
        if ($homeDir !== '') {
            $dirs[] = $homeDir.'/'.self::CLAUDE_DIR;
        }
        if ($engine === Config::ENGINE_OPENCODE) {
            $dirs[] = $repoPath.'/'.self::NEUTRAL_DIR;
        }
        $dirs[] = $repoPath.'/'.self::CLAUDE_DIR;

        return $dirs;
    }

    /**
     * The agents of the instance, keyed by the `name:` of their front matter.
     *
     * `tools` is null when the file has no `tools:` key: that agent is unrestricted, as it is on
     * Claude, where an agent without `tools:` inherits every tool — an empty `tools:` list is a
     * restriction to nothing. `model` is deliberately not returned: OpenCode ignores a per-agent
     * model, and Claude reads the file itself.
     *
     * @return array<string, array{description: string, prompt: string, tools: ?list<string>, path: string}>
     */
    public static function load(string $repoPath, string $homeDir, string $engine): array
    {
        $agents = [];
        foreach (self::directories($repoPath, $homeDir, $engine) as $dir) {
            $files = glob($dir.'/*.md') ?: [];
            sort($files);
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $text = @file_get_contents($file);
                if ($text === false) {
                    Log::line('Agent file '.$file.' is unreadable; it is ignored.');

                    continue;
                }
                $parsed = AgentFrontMatter::parse($text);
                $name = trim($parsed['name'] ?? '');
                if ($name === '') {
                    Log::line('Agent file '.$file.' has no usable "name:" in its frontmatter; it is ignored.');

                    continue;
                }
                $agents[$name] = [
                    'description' => $parsed['description'] ?? '',
                    'prompt' => $parsed['body'],
                    'tools' => $parsed['tools'] ?? null,
                    'path' => $file,
                ];
            }
        }

        return $agents;
    }
}
