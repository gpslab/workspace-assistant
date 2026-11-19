<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Builds the runner for the configured engine.
 *
 * The engine is a property of the instance (see {@see Config::$engine}), not of a
 * chat: it is set once in `.env` and never switched at runtime. Only `claude` is
 * supported for now, so every branch builds a {@see ClaudeRunner}. The match keeps
 * adding a second engine a one-line change; an unsupported value can no longer
 * reach here because {@see Config::fromEnv()} rejects anything but `claude` at
 * startup, but the default arm still fails loudly rather than falling back.
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
            'claude' => $this->createClaudeRunner($mode, $settingsJson, $extraEnv, $localeCode, $withAgents, $updateOnResume, $savedPromptHash, $model),
            default => throw new \RuntimeException("Unsupported ENGINE={$this->config->engine}."),
        };
    }

    /**
     * The Claude Code runner for the current configuration, extracted so that a test can still build
     * a ClaudeRunner for a non-default engine name. The engine name is what the stored-session
     * fingerprint tracks (see {@see Config::engineFingerprint()}); only `claude` has a runner
     * implementation, so the runner selection and the fingerprint are decoupled.
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
        );
    }
}
