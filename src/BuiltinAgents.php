<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Read-only sub-agents shipped with the bot (`prompts/agents/<name>.md.dist`) and handed to every
 * Claude run through `--agents <file>`, and to every OpenCode run through the per-run config.
 *
 * An agent the instance defines with the same `name:` wins: the CLI would let an `--agents` entry
 * shadow it, so the built-in one is left out. Which files those are is the engine's business
 * ({@see InstanceAgents::directories()}): on Claude only the directories that CLI loads itself, on
 * OpenCode all of them, since there the bot passes the instance's agents on. A local
 * `prompts/agents/<name>.md` replaces the whole file (frontmatter included) and must keep the `name:`.
 * The agents are not in {@see PromptFiles::CONTRACT}: replacing a body is a setting, not a contract.
 */
final class BuiltinAgents
{
    private const string PROMPT_DIR = 'agents';

    /**
     * Built-in agents and their fixed tools; no agent can write (no Write/Edit).
     *
     * @var array<string, list<string>>
     */
    public const array TOOLS = [
        'critic' => ['Read', 'Grep', 'Glob', 'Bash'],
        'verifier' => ['Read', 'Grep', 'Glob', 'Bash'],
        'editor' => ['Read', 'Grep', 'Glob'],
        'scope-check' => ['Read', 'Grep', 'Glob', 'Bash'],
        'acceptor' => ['Read', 'Grep', 'Glob', 'Bash'],
        'security' => ['Read', 'Grep', 'Glob', 'Bash'],
    ];

    /**
     * Agent definitions in the `--agents` JSON shape, keyed by name.
     *
     * @param list<string> $warnings
     *
     * @return array<string, array{description: string, prompt: string, tools: list<string>, model: string}>
     *
     * @throws \RuntimeException when a `.dist` file is missing (a broken installation)
     */
    public static function build(string $repoPath, string $homeDir, string $engine, array &$warnings = []): array
    {
        $taken = array_keys(InstanceAgents::load($repoPath, $homeDir, $engine));
        $agents = [];
        foreach (self::TOOLS as $name => $tools) {
            if (in_array($name, $taken, true)) {
                continue;
            }
            $path = self::PROMPT_DIR.'/'.$name;
            $loadWarnings = [];
            $parsed = AgentFrontMatter::parse(PromptFiles::load($path, $loadWarnings));
            // Agent bodies are not rendered, so a placeholder mismatch is irrelevant here.
            foreach ($loadWarnings as $warning) {
                if (!str_contains($warning, 'placeholders')) {
                    $warnings[] = $warning;
                }
            }
            if (($parsed['name'] ?? null) !== $name) {
                $warnings[] = 'Agent file '.PromptFiles::DIR.'/'.$path.'.md must keep "name: '.$name.'" — '.PromptFiles::DIR.'/'.$path.'.md.dist was used.';
                $parsed = AgentFrontMatter::parse(PromptFiles::loadDist($path));
            }
            $agents[$name] = [
                'description' => $parsed['description'] ?? '',
                'prompt' => $parsed['body'],
                'tools' => $tools,
                'model' => $parsed['model'] ?? 'inherit',
            ];
        }

        return $agents;
    }
}
