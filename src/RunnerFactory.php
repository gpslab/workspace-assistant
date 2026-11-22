<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Memory\MemoryContext;

/**
 * Builds the runner for the configured engine.
 *
 * The engine is a property of the instance (see {@see Config::$engine}), not of a
 * chat: it is set once in `.env` and never switched at runtime. `claude` builds a
 * {@see ClaudeRunner} and `opencode` an {@see OpencodeRunner}; every other value is
 * rejected by {@see Config::fromEnv()} at startup, and the default arm fails loudly
 * rather than falling back to an engine the instance did not ask for.
 *
 * `kimi` is still rejected by {@see create()}. Its runner is reachable only through
 * {@see createKimiRunner()}, a test hook: the engine becomes selectable in the task that adds its
 * keys, fingerprint, installer check and docs.
 */
class RunnerFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly Locale $locale,
    ) {
    }

    /**
     * @param array<string, string> $extraEnv
     */
    public function create(
        string $mode,
        string $settingsJson,
        array $extraEnv,
        string $localeCode,
        bool $withAgents,
        bool $updateOnResume,
        ?string $savedPromptHash,
        ?string $model,
    ): AgentRunner {
        return match ($this->config->engine) {
            Config::DEFAULT_ENGINE_FINGERPRINT => $this->createClaudeRunner($mode, $settingsJson, $extraEnv, $localeCode, $withAgents, $updateOnResume, $savedPromptHash, $model),
            Config::ENGINE_OPENCODE => $this->createOpencodeRunner($mode, $localeCode, $extraEnv, $withAgents),
            default => throw new \RuntimeException("Unsupported ENGINE={$this->config->engine}."),
        };
    }

    /**
     * The Claude Code runner for the current configuration, extracted so that a test can still build
     * a ClaudeRunner for an engine name that has no runner implementation: the engine name is what the
     * stored-session fingerprint tracks (see {@see Config::engineFingerprint()}), so the runner
     * selection and the fingerprint are decoupled there.
     *
     * @param array<string, string> $extraEnv
     */
    protected function createClaudeRunner(
        string $mode,
        string $settingsJson,
        array $extraEnv,
        string $localeCode,
        bool $withAgents,
        bool $updateOnResume,
        ?string $savedPromptHash,
        ?string $model,
    ): ClaudeRunner {
        return new ClaudeRunner(
            $this->config->claudeBin,
            $this->config->repoPath,
            $this->config->claudeTimeout,
            $this->config->claudeExtraArgs,
            $this->config->claudeStallTimeout,
            $this->config->claudeAppendSystemPrompt,
            $mode,
            settingsJson: $settingsJson,
            extraEnv: $extraEnv,
            promptsDir: $this->config->promptsDir,
            locale: $this->locale->with($localeCode),
            stateDir: $this->config->stateDir,
            withAgents: $withAgents,
            homeDir: (string) getenv('HOME'),
            updateOnResume: $updateOnResume,
            savedPromptHash: $savedPromptHash,
            forge: $this->config->forge,
            model: $model,
            providerEnv: $this->config->providerEnv,
            failedRunEvidenceDays: $this->config->failedRunEvidenceDays,
        );
    }

    /**
     * The OpenCode runner for the current configuration.
     *
     * Three arguments of a Claude run are deliberately dropped. `$model` is a Claude Code alias
     * (`sonnet`, `opus[1m]`): the model of an OpenCode run is pinned by the instance in
     * `OPENCODE_MODEL`, so the topic's `/model` never reaches the engine. `$settingsJson` and
     * `$savedPromptHash` are Claude Code mechanics; OpenCode takes its system prompt from its own
     * config renderer and applies a changed instructions file itself, so `updateOnResume` is not
     * wired either. The timeouts and `CLAUDE_APPEND_SYSTEM_PROMPT` are shared by both engines.
     *
     * The memory of the instance is the one thing the Claude runner does NOT need: OpenCode reads no user
     * scope, so with `MEMORY_3LAYER_BIN` set the bot reads the memory files and injects their content
     * ({@see MemoryContext}). That runner is never built on Claude Code, which is why the setting cannot
     * reach a Claude run ({@see Config::memoryBin()}).
     *
     * @param array<string, string> $extraEnv
     */
    protected function createOpencodeRunner(string $mode, string $localeCode, array $extraEnv, bool $withAgents): OpencodeRunner
    {
        return new OpencodeRunner(
            opencodeBin: $this->config->opencodeBin,
            repoPath: $this->config->repoPath,
            timeout: $this->config->claudeTimeout,
            extraArgs: $this->config->opencodeExtraArgs,
            stallTimeout: $this->config->claudeStallTimeout,
            appendSystemPrompt: $this->config->claudeAppendSystemPrompt,
            mode: $mode,
            extraEnv: $extraEnv,
            promptsDir: $this->config->promptsDir,
            locale: $this->locale->with($localeCode),
            stateDir: $this->config->stateDir,
            withAgents: $withAgents,
            homeDir: (string) getenv('HOME'),
            forge: $this->config->forge,
            opencodeModel: $this->config->opencodeModel,
            failedRunEvidenceDays: $this->config->failedRunEvidenceDays,
            memory: $this->memory(),
        );
    }

    /**
     * The Kimi Code runner for the current configuration.
     *
     * A test hook, not a selectable engine yet: {@see create()} still rejects `kimi`. The topic's
     * `$model` is a Claude Code alias and is ignored — the model of a Kimi run is pinned by
     * `KIMI_MODEL` ({@see Config::$kimiModel}). `$settingsJson` is Claude Code mechanics; Kimi takes
     * its hooks from the rendered run home, so it is not wired either.
     *
     * Like OpenCode, Kimi reads no user scope, so the memory of the instance reaches it as the same
     * injected block ({@see memory()}): its run home renders no `~/.claude/CLAUDE.md` and no hooks to
     * read the memory from.
     *
     * @param array<string, string> $extraEnv
     */
    protected function createKimiRunner(
        string $mode,
        array $extraEnv,
        string $localeCode,
        bool $updateOnResume,
        ?string $savedPromptHash,
    ): KimiRunner {
        return new KimiRunner(
            kimiBin: $this->config->kimiBin,
            repoPath: $this->config->repoPath,
            timeout: $this->config->claudeTimeout,
            extraArgs: $this->config->kimiExtraArgs,
            stallTimeout: $this->config->claudeStallTimeout,
            appendSystemPrompt: $this->config->claudeAppendSystemPrompt,
            mode: $mode,
            extraEnv: $extraEnv,
            promptsDir: $this->config->promptsDir,
            locale: $this->locale->with($localeCode),
            stateDir: $this->config->stateDir,
            homeDir: (string) getenv('HOME'),
            updateOnResume: $updateOnResume,
            savedPromptHash: $savedPromptHash,
            forge: $this->config->forge,
            kimiModel: $this->config->kimiModel,
            kimiHome: $this->config->kimiHome,
            memory: $this->memory(),
        );
    }

    /**
     * The memory of the instance for a run of an engine that reads no user scope (OpenCode, Kimi), or
     * null when `MEMORY_3LAYER_BIN` is not set ({@see Config::DEFAULT_MEMORY_CONTEXT_MAX_BYTES} caps the
     * injected part of one file; 0 turns the injection off as well).
     */
    private function memory(): ?MemoryContext
    {
        if ($this->config->memoryBin() === '') {
            return null;
        }

        return new MemoryContext(
            $this->config->memoryBin(),
            $this->config->repoPath,
            $this->config->memory3LayerContextMaxBytes,
        );
    }
}
