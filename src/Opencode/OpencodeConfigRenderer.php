<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * Renders the per-run OpenCode config directory as in-memory data (relative path -> content).
 *
 * Pure: no filesystem or network access. It returns the contents the future engine task places
 * in `state/opencode-<runId>/` and writes to disk itself. Four artefacts: the system-prompt file,
 * `opencode.json` (the `instructions` list), `agents/<name>.md` (OpenCode V2 markdown agents) and
 * `explicit.json` (the agent permissions, passed to the engine via `OPENCODE_CONFIG`).
 */
final class OpencodeConfigRenderer
{
    /** Name of the assembled system prompt inside the run directory. */
    public const string SYSTEM_PROMPT_FILE = 'system-prompt.md';

    /**
     * @param string              $systemPrompt     the assembled system prompt (SystemPrompt::build +
     *                                              CLAUDE_APPEND_SYSTEM_PROMPT + the language line)
     * @param string              $systemPromptPath absolute path the runner writes the system prompt
     *                                              to; referenced from `opencode.json`'s `instructions`
     * @param list<OpencodeAgent> $builtinAgents    the built-in sub-agents
     * @param list<OpencodeAgent> $overrides        the managed repository's `.claude/agents/`
     *                                              overrides; an override shadows a built-in of the
     *                                              same name, one with a new name is added
     * @param string              $repoRoot         absolute root of the managed repository
     * @param bool                $hasClaudeMd      whether `<repo>/CLAUDE.md` exists
     * @param bool                $hasAgentsMd      whether `<repo>/AGENTS.md` exists
     */
    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $systemPromptPath,
        private readonly array $builtinAgents,
        private readonly array $overrides,
        private readonly string $repoRoot,
        private readonly bool $hasClaudeMd,
        private readonly bool $hasAgentsMd,
    ) {
    }

    /**
     * The complete contents of the run directory, keyed by path relative to it.
     *
     * @return array<string, string>
     */
    public function render(): array
    {
        $files = [
            self::SYSTEM_PROMPT_FILE => $this->systemPrompt,
            'opencode.json' => $this->opencodeJson(),
            'explicit.json' => $this->explicitJson(),
        ];
        foreach ($this->agents() as $name => $agent) {
            $files['agents/'.$name.'.md'] = $this->agentMarkdown($agent);
        }

        return $files;
    }

    /**
     * Built-in agents merged with the overrides: an override wins over a built-in of the same
     * name, and an override with a new name is added. Sorted by name for a stable output.
     *
     * @return array<string, OpencodeAgent>
     */
    private function agents(): array
    {
        $agents = [];
        foreach ($this->builtinAgents as $agent) {
            $agents[$agent->name] = $agent;
        }
        foreach ($this->overrides as $agent) {
            $agents[$agent->name] = $agent;
        }
        ksort($agents);

        return $agents;
    }

    private function opencodeJson(): string
    {
        // OpenCode has no system-prompt flag; the system prompt reaches the model through
        // `instructions`. CLAUDE.md is added only when AGENTS.md is absent: OpenCode V2 reads
        // only AGENTS.md and never uses CLAUDE.md on its own.
        $instructions = [$this->systemPromptPath];
        if ($this->hasClaudeMd && !$this->hasAgentsMd) {
            $instructions[] = rtrim($this->repoRoot, '/').'/CLAUDE.md';
        }

        return $this->json(['instructions' => $instructions]);
    }

    private function explicitJson(): string
    {
        $agents = [];
        foreach ($this->agents() as $name => $agent) {
            $agents[$name] = ['permissions' => ToolPermissions::rules($agent->tools)];
        }

        return $this->json(['agents' => $agents]);
    }

    private function agentMarkdown(OpencodeAgent $agent): string
    {
        return "---\nmode: subagent\ndescription: ".$this->yamlString($agent->description)."\n---\n\n".$agent->system."\n";
    }

    /** A YAML double-quoted string: backslashes, double quotes and newlines are escaped. */
    private static function yamlString(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \JsonException when a value cannot be encoded
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
