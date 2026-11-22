<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Renders the files of a Kimi run home as in-memory data (relative path -> content).
 *
 * Pure: it returns the contents the future runner places in its isolated `KIMI_CODE_HOME` and
 * writes to disk itself. No file is written and no process is started. The run home has three
 * artefacts: `config.toml` (the user's config filtered and extended with the bot's agents
 * directory, hook tables and disabled tools), `agent.md` (the `--agent-file` prompt) and
 * `agents/<name>.md` (the bot's built-in and the instance's agents).
 *
 * The credentials, sessions and oauth directories are not part of this data: the runner links
 * them to the logged-in home so the refresh token is never copied.
 */
final class KimiRunHomeRenderer
{
    public const string CONFIG_FILE = 'config.toml';
    public const string AGENT_FILE = 'agent.md';
    public const string AGENTS_DIR = 'agents';

    /**
     * @param string $sourceConfig       the text of the user's `config.toml`
     * @param string $runHome            absolute path of the run home the files are destined for;
     *                                   the rendered agents directory is referenced from the config
     * @param string $hookShimPath       absolute path of the gate shim (`bin/kimi-hook.php`); empty
     *                                   renders no hook tables (the shim is not installed yet)
     * @param string $systemPrompt       the mode prompt (SystemPrompt::build)
     * @param string $memory             the memory block of the instance (`MEMORY_3LAYER_BIN`), injected
     *                                   right after the mode prompt: this engine reads no user scope, so
     *                                   the memory comes from the bot; '' = the feature is off
     * @param string $appendSystemPrompt the instance addition (CLAUDE_APPEND_SYSTEM_PROMPT), or ''
     * @param string $languageLine       the chat-language line, or ''
     * @param string $repoRoot           absolute root of the managed repository
     * @param string $homeDir            home directory of the bot's user, for `~/.claude/agents`
     */
    public function __construct(
        private readonly string $sourceConfig,
        private readonly string $runHome,
        private readonly string $hookShimPath,
        private readonly string $systemPrompt,
        private readonly string $memory,
        private readonly string $appendSystemPrompt,
        private readonly string $languageLine,
        private readonly string $repoRoot,
        private readonly string $homeDir,
    ) {
    }

    /**
     * The complete contents of the run home, keyed by path relative to it.
     *
     * @param list<string> $warnings agent files that could not be used
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException when a non-empty hook shim path does not exist, the source config
     *                           cannot be parsed or the run home is empty
     */
    public function render(array &$warnings = []): array
    {
        $runHome = rtrim($this->runHome, '/');
        if ($runHome === '') {
            throw new \RuntimeException('The run home of a Kimi run must not be empty.');
        }
        $agentsDir = $runHome.'/'.self::AGENTS_DIR;
        $configRenderer = new KimiConfigRenderer();
        $files = [
            self::CONFIG_FILE => $this->hookShimPath !== ''
                ? $configRenderer->render($this->sourceConfig, $agentsDir, (new KimiHooksRenderer($this->hookShimPath))->render())
                : $configRenderer->renderWithoutHooks($this->sourceConfig, $agentsDir),
            self::AGENT_FILE => (new KimiMainAgentRenderer(
                $this->systemPrompt,
                $this->memory,
                $this->appendSystemPrompt,
                $this->languageLine,
            ))->render(),
        ];
        foreach ((new KimiAgentsRenderer())->render($this->repoRoot, $this->homeDir, $warnings) as $name => $markdown) {
            $files[self::AGENTS_DIR.'/'.$name.'.md'] = $markdown;
        }

        return $files;
    }
}
