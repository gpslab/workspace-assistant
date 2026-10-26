<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Typed bot configuration loaded from .env.
 */
final class Config
{
    /**
     * @param int[]    $allowedUserIds
     * @param string[] $claudeExtraArgs
     */
    public function __construct(
        public readonly string $botToken,
        public readonly array $allowedUserIds,
        public readonly string $repoPath,
        public readonly string $githubRepo,
        public readonly string $claudeBin,
        public readonly int $claudeTimeout,
        public readonly int $claudeStallTimeout,
        public readonly array $claudeExtraArgs,
        public readonly string $claudeAppendSystemPrompt,
        public readonly string $stateDir,
        public readonly string $outboxDir,
        public readonly string $inboxDir,
        public readonly int $pollTimeout,
        public readonly int $gitTimeout,
        public readonly string $epicsDir = '.workspace-assistant/epics',
        public readonly string $promptsDir = '',
        public readonly int $epicPollSeconds = 30,
        public readonly int $epicTickMinutes = 30,
        public readonly int $epicStallTicks = 6,
        public readonly int $epicQuietMinutes = 10,
        public readonly int $epicAnswerTimeoutMinutes = 120,
        public readonly int $epicMasterRedHours = 6,
        public readonly GithubOwners $githubOwners = new GithubOwners(),
        public readonly string $subreposDir = '',
        public readonly string $timezone = Timezone::DEFAULT,
        public readonly string $botLocale = Locale::DEFAULT,
        public readonly string $localesDir = '',
        /** @var list<string> */
        public readonly array $openroninLogins = [],
    ) {
    }

    public static function load(string $envPath): self
    {
        return self::fromEnv(Env::load($envPath));
    }

    /**
     * A relative path from `.env` is taken from the installation directory, not from the current working
     * directory: the same `.env` must mean the same place whether the bot is started by a service manager
     * or by `bin/ctl.sh` from any directory. `bin/ctl.sh` resolves `STATE_DIR` the same way.
     */
    public static function resolvePath(string $path, string $baseDir): string
    {
        return $path === '' || str_starts_with($path, '/') ? $path : rtrim($baseDir, '/').'/'.$path;
    }

    /**
     * @param string|null $installDir base of a relative STATE_DIR; the code directory by default (the
     *                                installer passes the directory it installs into)
     */
    public static function fromEnv(Env $env, ?string $installDir = null): self
    {
        $token = $env->require('BOT_TOKEN');
        if ($token === 'PASTE_YOUR_BOT_TOKEN_HERE') {
            throw new \RuntimeException('BOT_TOKEN is not set in .env.');
        }

        $allowed = array_map('intval', $env->list('ALLOWED_USER_IDS'));
        if ($allowed === []) {
            throw new \RuntimeException('ALLOWED_USER_IDS is empty in .env — the bot would let nobody in.');
        }

        $repoPath = $env->require('REPO_PATH');
        if (!is_dir($repoPath.'/.git')) {
            throw new \RuntimeException("REPO_PATH={$repoPath} does not look like a git repository.");
        }

        $installDir ??= dirname(__DIR__);
        $stateDir = self::resolvePath((string) $env->get('STATE_DIR', $installDir.'/state'), $installDir);
        if (!is_dir($stateDir) && !mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
            throw new \RuntimeException("Failed to create STATE_DIR={$stateDir}.");
        }

        // Outbox directory: Claude puts files here (screenshots etc.) and the bot
        // sends them to the chat after the run (see Delivery::flushOutbox).
        $outboxDir = $stateDir.'/outbox';
        if (!is_dir($outboxDir) && !mkdir($outboxDir, 0775, true) && !is_dir($outboxDir)) {
            throw new \RuntimeException("Failed to create the outbox directory {$outboxDir}.");
        }

        // Inbox directory: the bot downloads files sent in Telegram here,
        // before attaching their absolute paths to the Claude prompt (see
        // Bot::startFileJob). Files are deleted after the run.
        $inboxDir = $stateDir.'/inbox';
        if (!is_dir($inboxDir) && !mkdir($inboxDir, 0775, true) && !is_dir($inboxDir)) {
            throw new \RuntimeException("Failed to create the inbox directory {$inboxDir}.");
        }

        $timezone = trim((string) $env->get('TIMEZONE', ''));
        if ($timezone === '') {
            $timezone = Timezone::DEFAULT;
        } elseif (!Timezone::isValid($timezone)) {
            throw new \RuntimeException("Invalid TIMEZONE={$timezone} in .env.");
        }

        $epicsDir = trim((string) $env->get('EPICS_DIR', '.workspace-assistant/epics'), '/') ?: '.workspace-assistant/epics';

        return new self(
            botToken: $token,
            allowedUserIds: $allowed,
            repoPath: rtrim($repoPath, '/'),
            githubRepo: trim((string) $env->get('GITHUB_REPO', ''), '/'),
            claudeBin: (string) $env->get('CLAUDE_BIN', 'claude'),
            claudeTimeout: (int) $env->get('CLAUDE_TIMEOUT', '0'),
            claudeStallTimeout: (int) $env->get('CLAUDE_STALL_TIMEOUT', '900'),
            claudeExtraArgs: $env->list('CLAUDE_EXTRA_ARGS', '--dangerously-skip-permissions'),
            claudeAppendSystemPrompt: (string) $env->get('CLAUDE_APPEND_SYSTEM_PROMPT', ''),
            stateDir: rtrim($stateDir, '/'),
            outboxDir: rtrim($outboxDir, '/'),
            inboxDir: rtrim($inboxDir, '/'),
            pollTimeout: (int) $env->get('POLL_TIMEOUT', '30'),
            gitTimeout: (int) $env->get('GIT_TIMEOUT', '120'),
            epicsDir: $epicsDir,
            promptsDir: trim((string) $env->get('PROMPTS_DIR', ''), '/'),
            epicPollSeconds: max(10, (int) $env->get('EPIC_POLL_SECONDS', '30')),
            epicTickMinutes: max(1, (int) $env->get('EPIC_TICK_MINUTES', '30')),
            epicStallTicks: max(1, (int) $env->get('EPIC_STALL_TICKS', '6')),
            epicQuietMinutes: max(0, (int) $env->get('EPIC_QUIET_MINUTES', '10')),
            epicAnswerTimeoutMinutes: max(1, (int) $env->get('EPIC_ANSWER_TIMEOUT_MINUTES', '120')),
            epicMasterRedHours: max(1, (int) $env->get('EPIC_MASTER_RED_HOURS', '6')),
            githubOwners: new GithubOwners($env->list('GITHUB_OWNERS')),
            subreposDir: trim((string) $env->get('SUBREPOS_DIR', ''), '/'),
            timezone: $timezone,
            botLocale: trim((string) $env->get('BOT_LOCALE', '')) ?: Locale::DEFAULT,
            localesDir: trim((string) $env->get('LOCALES_DIR', ''), '/'),
            openroninLogins: array_values($env->list('OPENRONIN_LOGINS')),
        );
    }

    /**
     * Warnings to log once at startup.
     *
     * @return list<string>
     */
    public function startupWarnings(): array
    {
        $warnings = $this->openroninLogins === [] ? ['OPENRONIN_LOGINS is not set: the openronin marker is accepted from any author'] : [];

        return $warnings;
    }

    public function isAllowed(int $userId): bool
    {
        return in_array($userId, $this->allowedUserIds, true);
    }
}
