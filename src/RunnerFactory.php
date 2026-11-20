<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Builds the runner for the configured engine.
 *
 * The engine is a property of the instance (see {@see Config::$engine}), not of a
 * chat: it is set once in `.env` and never switched at runtime. `claude` builds a
 * {@see ClaudeRunner} and `opencode` an {@see OpencodeRunner}; every other value is
 * rejected by {@see Config::fromEnv()} at startup, and the default arm fails loudly
 * rather than falling back to an engine the instance did not ask for.
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
        );
    }
}
