<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

use GpsLab\WorkspaceAssistant\InstanceAgents;

/**
 * Renders the per-run OpenCode config directory as in-memory data (relative path -> content).
 *
 * Pure: no filesystem or network access. It returns the contents the future engine task places
 * in `state/opencode-<runId>/` and writes to disk itself. Four artefacts: the system-prompt file,
 * `opencode.json` (the `instructions` list), `agents/<name>.md` (OpenCode V2 markdown agents) and
 * `explicit.json` (the agent permissions, passed to the engine via `OPENCODE_CONFIG`; a gated run also
 * lists the gate shim there in `plugins`).
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
     * @param list<OpencodeAgent> $overrides        the agents the instance defines itself
     *                                              ({@see InstanceAgents}); an override shadows a
     *                                              built-in of the same name, one with a new name is
     *                                              added
     * @param string              $repoRoot         absolute root of the managed repository
     * @param bool                $hasClaudeMd      whether `<repo>/CLAUDE.md` exists
     * @param bool                $hasAgentsMd      whether `<repo>/AGENTS.md` exists
     * @param string|null         $gatePlugin       directory of the gate shim (`opencode-plugin/`) when the
     *                                              run is gated (a `pm` or `review` mode run, which covers the
     *                                              epic runs); null for a run with no gate (`arch`, `/compact`)
     */
    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $systemPromptPath,
        private readonly array $builtinAgents,
        private readonly array $overrides,
        private readonly string $repoRoot,
        private readonly bool $hasClaudeMd,
        private readonly bool $hasAgentsMd,
        private readonly ?string $gatePlugin = null,
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
            if ($this->isValidName($agent->name)) {
                $agents[$agent->name] = $agent;
            }
        }
        foreach ($this->overrides as $agent) {
            if ($this->isValidName($agent->name)) {
                $agents[$agent->name] = $agent;
            }
        }
        ksort($agents);

        return $agents;
    }

    /**
     * An agent name becomes a path segment (`agents/<name>.md`) and an `agents.<name>` key in
     * `explicit.json`, so it must be one safe segment: letters, digits, `_` and `-`, starting with
     * a letter. That rules out `..`, `/`, `.`, an empty name and a purely numeric name — a
     * numeric-string key would otherwise be cast by PHP to an int, turning the `agents` map in
     * `explicit.json` into a JSON list and dropping the name -> permissions binding.
     *
     * Every built-in name (critic, scope-check, ...) matches; an override name from the managed
     * repository that does not is skipped rather than rendered outside `agents/`.
     */
    private function isValidName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*$/i', $name) === 1;
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
            if ($agent->tools === null) {
                // An agent without `tools:` is unrestricted, as it is on Claude: it gets no entry, so
                // no rule applies to it. Every rule list rendered here starts with a deny-all, so an
                // empty one would turn "no restriction" into "no permission".
                continue;
            }
            $agents[$name] = ['permissions' => ToolPermissions::rules($agent->tools)];
        }

        // Belt and braces on top of name validation: a stdClass always encodes as a JSON object,
        // so even a name that slipped through as a numeric string could not turn the agents map
        // into a JSON list and drop the name -> permissions binding.
        $data = ['agents' => (object) $agents];
        if ($this->gatePlugin !== null) {
            // OpenCode has no command hooks: the PM gate and the review commit hook of a gated run reach
            // it through this plugin, which starts the same PHP hooks (no gate logic in JS). A run with no
            // gate kind gets no plugin, so it does not pay a hook call on every shell call.
            $data['plugins'] = [$this->gatePlugin];
        }

        return $this->json($data);
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
