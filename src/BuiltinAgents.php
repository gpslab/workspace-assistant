<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Read-only sub-agents shipped with the bot (`prompts/agents/<name>.md.dist`) and handed to every
 * Claude run through `--agents <file>`.
 *
 * An agent with the same `name:` in `<repo>/.claude/agents/*.md` or `~/.claude/agents/*.md` wins:
 * the CLI would let an `--agents` entry shadow it, so the built-in one is left out. A local
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
    public static function build(string $repoPath, string $homeDir, array &$warnings = []): array
    {
        $taken = self::instanceNames($repoPath, $homeDir);
        $agents = [];
        foreach (self::TOOLS as $name => $tools) {
            if (in_array($name, $taken, true)) {
                continue;
            }
            $path = self::PROMPT_DIR.'/'.$name;
            $loadWarnings = [];
            $parsed = self::parse(PromptFiles::load($path, $loadWarnings));
            // Agent bodies are not rendered, so a placeholder mismatch is irrelevant here.
            foreach ($loadWarnings as $warning) {
                if (!str_contains($warning, 'placeholders')) {
                    $warnings[] = $warning;
                }
            }
            if (($parsed['name'] ?? null) !== $name) {
                $warnings[] = 'Agent file '.PromptFiles::DIR.'/'.$path.'.md must keep "name: '.$name.'" — '.PromptFiles::DIR.'/'.$path.'.md.dist was used.';
                $parsed = self::parse(PromptFiles::loadDist($path));
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

    /**
     * `name:` values of the agents the instance defines itself.
     *
     * @return list<string>
     */
    public static function instanceNames(string $repoPath, string $homeDir): array
    {
        $dirs = [$repoPath.'/.claude/agents'];
        if ($homeDir !== '') {
            $dirs[] = $homeDir.'/.claude/agents';
        }
        $names = [];
        foreach ($dirs as $dir) {
            foreach (glob($dir.'/*.md') ?: [] as $file) {
                $text = @file_get_contents($file);
                $name = $text === false ? null : (self::parse($text)['name'] ?? null);
                if ($name === null || $name === '') {
                    Log::line('Agent file '.$file.' has no usable "name:" in its frontmatter; it does not shadow a built-in agent.');

                    continue;
                }
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Splits a markdown agent file into frontmatter keys and body; a file without a frontmatter has only a body.
     *
     * @return array{name?: string, description?: string, model?: string, body: string}
     */
    private static function parse(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        if (preg_match('/\A---\n(.*?)\n---(?:\n(.*))?\z/s', $text, $m) !== 1) {
            return ['body' => $text];
        }
        $out = ['body' => $m[2] ?? ''];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(name|description|model):\s*(.*?)\s*$/', $line, $kv) === 1) {
                $out[$kv[1]] = trim($kv[2], '"\'');
            }
        }

        return $out;
    }
}
