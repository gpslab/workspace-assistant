<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Config as BotConfig;
use GpsLab\WorkspaceAssistant\Env;

/** Independent settings of the explicitly invoked local coder worker. */
final readonly class Config
{
    public const int BASH_DEFAULT_TIMEOUT_MS = 600000;
    public const int BASH_MAX_TIMEOUT_MS = 1200000;

    /** @param list<string> $claudeExtraArgs
     * @param array<string, string> $providerEnv
     */
    public function __construct(
        public string $repository,
        public string $stateDir,
        public string $worktreeDir,
        public string $authorName,
        public string $authorEmail,
        public string $claudeBin = 'claude',
        public array $claudeExtraArgs = ['--dangerously-skip-permissions'],
        public int $runTimeout = 3600,
        public int $leaseSeconds = 300,
        public int $checkTimeout = 1200,
        public string $envFile = '',
        public array $providerEnv = [],
    ) {
        if ($runTimeout < 1 || $leaseSeconds < 1 || $checkTimeout < 1) {
            throw new \InvalidArgumentException('Coder timeouts and leases must be positive.');
        }
        if ($authorName === '' || $authorEmail === '') {
            throw new \InvalidArgumentException('CODER_GIT_USER_NAME and CODER_GIT_USER_EMAIL are required.');
        }
    }

    public static function fromEnv(Env $env, string $baseDir): self
    {
        $repository = BotConfig::resolvePath($env->require('REPO_PATH'), $baseDir);
        $stateDir = BotConfig::resolvePath((string) $env->get('STATE_DIR', $baseDir.'/state'), $baseDir);
        $worktreeDir = BotConfig::resolvePath((string) $env->get('CODER_WORKTREE_DIR', dirname($repository).'/coder-worktrees'), $baseDir);

        return new self(
            repository: rtrim($repository, '/'),
            stateDir: rtrim($stateDir, '/'),
            worktreeDir: rtrim($worktreeDir, '/'),
            authorName: $env->require('CODER_GIT_USER_NAME'),
            authorEmail: $env->require('CODER_GIT_USER_EMAIL'),
            claudeBin: (string) $env->get('CODER_CLAUDE_BIN', $env->get('CLAUDE_BIN', 'claude')),
            claudeExtraArgs: array_values($env->list('CODER_CLAUDE_EXTRA_ARGS', '--dangerously-skip-permissions')),
            runTimeout: (int) $env->get('CODER_RUN_TIMEOUT', '3600'),
            leaseSeconds: (int) $env->get('CODER_LEASE_SECONDS', '300'),
            checkTimeout: (int) $env->get('CODER_CHECK_TIMEOUT', '1200'),
            envFile: $baseDir.'/.env',
            providerEnv: $env->providerEnv(),
        );
    }

    /** @return array<string, string> */
    public function engineEnvironment(): array
    {
        return [
            'BASH_DEFAULT_TIMEOUT_MS' => (string) self::BASH_DEFAULT_TIMEOUT_MS,
            'BASH_MAX_TIMEOUT_MS' => (string) self::BASH_MAX_TIMEOUT_MS,
        ];
    }
}
