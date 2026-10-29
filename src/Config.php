<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Typed bot configuration loaded from .env.
 */
final class Config
{
    public const string FORGE_GITHUB = 'github';
    public const string FORGE_GITLAB = 'gitlab';

    public const array DEFAULT_HOSTS = [
        self::FORGE_GITHUB => 'github.com',
        self::FORGE_GITLAB => 'gitlab.com',
    ];

    /**
     * @param int[]    $allowedUserIds
     * @param string[] $claudeExtraArgs
     * @param string[] $configWarnings  warnings found while reading `.env`, logged once at startup
     */
    public function __construct(
        public readonly string $botToken,
        public readonly array $allowedUserIds,
        public readonly string $repoPath,
        public readonly string $forgeRepo,
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
        public readonly ForgeOwners $forgeOwners = new ForgeOwners(),
        public readonly string $subreposDir = '',
        public readonly string $timezone = Timezone::DEFAULT,
        public readonly string $botLocale = Locale::DEFAULT,
        public readonly string $localesDir = '',
        /** @var list<string> */
        public readonly array $openroninLogins = [],
        public readonly bool $autoCommit = true,
        public readonly string $forge = self::FORGE_GITHUB,
        public readonly string $forgeHost = 'github.com',
        public readonly array $configWarnings = [],
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
        // Bot::tryStartQueueHead, Inbox::downloadIncomingFile). Files are deleted after the run.
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

        $autoCommit = trim((string) $env->get('AUTO_COMMIT', '1'));
        if ($autoCommit === '') {
            $autoCommit = '1';
        }
        if (!in_array($autoCommit, ['0', '1'], true)) {
            throw new \RuntimeException("Invalid AUTO_COMMIT={$autoCommit} in .env: expected 1 or 0.");
        }

        $forge = trim((string) $env->get('FORGE', ''));
        if ($forge === '') {
            $forge = self::FORGE_GITHUB;
        } elseif (!isset(self::DEFAULT_HOSTS[$forge])) {
            throw new \RuntimeException("Invalid FORGE={$forge} in .env: expected github or gitlab.");
        }
        $forgeHost = trim((string) $env->get('FORGE_HOST', '')) ?: self::DEFAULT_HOSTS[$forge];

        $configWarnings = [];
        $forgeRepo = self::withAlias($env, 'FORGE_REPO', 'GITHUB_REPO', $configWarnings);
        $forgeOwners = self::withAlias($env, 'FORGE_OWNERS', 'GITHUB_OWNERS', $configWarnings);

        $epicsDir = trim((string) $env->get('EPICS_DIR', '.workspace-assistant/epics'), '/') ?: '.workspace-assistant/epics';

        return new self(
            botToken: $token,
            allowedUserIds: $allowed,
            repoPath: rtrim($repoPath, '/'),
            forgeRepo: trim($forgeRepo, '/'),
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
            forgeOwners: new ForgeOwners(explode(',', $forgeOwners), $forge === self::FORGE_GITLAB),
            subreposDir: trim((string) $env->get('SUBREPOS_DIR', ''), '/'),
            timezone: $timezone,
            botLocale: trim((string) $env->get('BOT_LOCALE', '')) ?: Locale::DEFAULT,
            localesDir: trim((string) $env->get('LOCALES_DIR', ''), '/'),
            openroninLogins: array_values($env->list('OPENRONIN_LOGINS')),
            autoCommit: $autoCommit === '1',
            forge: $forge,
            forgeHost: $forgeHost,
            configWarnings: $configWarnings,
        );
    }

    /**
     * Value of a renamed key: the new key wins over its deprecated alias; both set is a warning.
     *
     * @param list<string> $warnings
     */
    private static function withAlias(Env $env, string $key, string $alias, array &$warnings): string
    {
        $value = (string) $env->get($key, '');
        $old = (string) $env->get($alias, '');
        if ($value !== '' && $old !== '') {
            $warnings[] = "Both {$key} and its deprecated alias {$alias} are set: {$key} is used, {$alias} is ignored.";
        }

        return $value !== '' ? $value : $old;
    }

    /** Display name of the forge for texts: GitHub or GitLab. */
    public function forgeName(): string
    {
        return $this->forge === self::FORGE_GITLAB ? 'GitLab' : 'GitHub';
    }

    /** Command line client of the forge: gh or glab. */
    public function forgeCli(): string
    {
        return $this->forge === self::FORGE_GITLAB ? 'glab' : 'gh';
    }

    /** Epics need a forge client; none exists for GitLab yet. */
    public function epicsAvailable(): bool
    {
        return $this->forge === self::FORGE_GITHUB;
    }

    /**
     * Warnings to log once at startup.
     *
     * @return list<string>
     */
    public function startupWarnings(): array
    {
        $warnings = array_values($this->configWarnings);
        if ($this->openroninLogins === []) {
            $warnings[] = 'OPENRONIN_LOGINS is not set: the openronin marker is accepted from any author';
        }

        return $warnings;
    }

    /** Absolute path of `PROMPTS_DIR` inside the managed repository (null - not set). */
    public function absolutePromptsDir(): ?string
    {
        return $this->promptsDir !== '' ? $this->repoPath.'/'.$this->promptsDir : null;
    }

    public function isAllowed(int $userId): bool
    {
        return in_array($userId, $this->allowedUserIds, true);
    }
}
