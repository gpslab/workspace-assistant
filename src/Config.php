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

    /**
     * Engine fingerprint of the default Claude configuration (no `ANTHROPIC_BASE_URL`). A `NULL`
     * `sessions.engine_fingerprint` means a session stored by this configuration.
     */
    public const string DEFAULT_ENGINE_FINGERPRINT = 'claude';

    /** @var list<string> */
    public const array DEFAULT_CLAUDE_MODELS = ['fable', 'opus[1m]', 'opus', 'sonnet', 'haiku'];

    /** Engine name of an instance that runs OpenCode instead of Claude Code. */
    public const string ENGINE_OPENCODE = 'opencode';

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
        /** Agent engine the bot runs: `claude` or `opencode`; anything else is rejected at startup. */
        public readonly string $engine = self::DEFAULT_ENGINE_FINGERPRINT,
        /** Path to the `opencode` binary (`OPENCODE_BIN`). */
        public readonly string $opencodeBin = 'opencode',
        /** Model of an OpenCode instance (`provider/model`, `OPENCODE_MODEL`); '' = the default model of OpenCode. */
        public readonly string $opencodeModel = '',
        /** @var list<string> extra argv for the OpenCode runner (`OPENCODE_EXTRA_ARGS`) */
        public readonly array $opencodeExtraArgs = [],
        public readonly array $configWarnings = [],
        /** @var list<string> models selectable with /model, passed to `claude --model` as they are */
        public readonly array $claudeModels = self::DEFAULT_CLAUDE_MODELS,
        /** Model of the Orchestrator `tick` epic run; empty = fall back to the model for all topics. */
        public readonly string $modelEpicTick = '',
        /** Model of the `final` (acceptance) epic run; empty = fall back to the model for all topics. */
        public readonly string $modelEpicFinal = '',
        /** Model of the `review` epic run; empty = fall back to the model for all topics. */
        public readonly string $modelEpicReview = '',
        /** @var list<string> files whose values are all masked in outgoing messages, `~` not yet expanded */
        public readonly array $secretEnvFiles = [],
        /** @var list<string> path globs where the commit gate does not apply shape patterns (known values still apply) */
        public readonly array $secretScanExclude = [],
        /** @var array<string, string> `.env` keys prefixed ANTHROPIC_/CLAUDE_CODE_, passed to the claude process env */
        public readonly array $providerEnv = [],
        /** `ANTHROPIC_BASE_URL` (real env over `.env`); null/'' = the default Anthropic endpoint */
        public readonly ?string $anthropicBaseUrl = null,
        /** `ANTHROPIC_MODEL` (real env over `.env`); null/'' = not set */
        public readonly ?string $anthropicModel = null,
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

        // The engine is a property of the instance, set once in .env, never switched from chat.
        $engine = trim((string) $env->get('ENGINE', ''));
        if ($engine === '') {
            $engine = self::DEFAULT_ENGINE_FINGERPRINT;
        } elseif ($engine !== self::DEFAULT_ENGINE_FINGERPRINT && $engine !== self::ENGINE_OPENCODE) {
            throw new \RuntimeException("Invalid ENGINE={$engine} in .env: expected ".self::DEFAULT_ENGINE_FINGERPRINT.' or '.self::ENGINE_OPENCODE.'.');
        }

        $configWarnings = [];
        $forgeRepo = self::withAlias($env, 'FORGE_REPO', 'GITHUB_REPO', $configWarnings);
        $forgeOwners = self::withAlias($env, 'FORGE_OWNERS', 'GITHUB_OWNERS', $configWarnings);

        $epicsDir = trim((string) $env->get('EPICS_DIR', '.workspace-assistant/epics'), '/') ?: '.workspace-assistant/epics';

        $claudeExtraArgs = $env->list('CLAUDE_EXTRA_ARGS', '--dangerously-skip-permissions');
        $claudeModels = array_values(array_unique($env->list('CLAUDE_MODELS', implode(',', self::DEFAULT_CLAUDE_MODELS))));
        $modelEpicTick = trim((string) $env->get('MODEL_EPIC_TICK', ''));
        $modelEpicFinal = trim((string) $env->get('MODEL_EPIC_FINAL', ''));
        $modelEpicReview = trim((string) $env->get('MODEL_EPIC_REVIEW', ''));
        foreach ([
            'MODEL_EPIC_TICK' => $modelEpicTick,
            'MODEL_EPIC_FINAL' => $modelEpicFinal,
            'MODEL_EPIC_REVIEW' => $modelEpicReview,
        ] as $key => $model) {
            if ($model !== '' && !in_array($model, $claudeModels, true)) {
                throw new \RuntimeException("{$key}={$model} in .env is not in CLAUDE_MODELS.");
            }
        }
        if (($modelEpicTick !== '' || $modelEpicFinal !== '' || $modelEpicReview !== '')
            && ClaudeRunner::modelFromArgs($claudeExtraArgs) !== null) {
            $configWarnings[] = 'MODEL_EPIC_TICK, MODEL_EPIC_FINAL and MODEL_EPIC_REVIEW are ignored: a --model in CLAUDE_EXTRA_ARGS pins the model for every run.';
        }

        return new self(
            botToken: $token,
            allowedUserIds: $allowed,
            repoPath: rtrim($repoPath, '/'),
            forgeRepo: trim($forgeRepo, '/'),
            claudeBin: trim((string) $env->get('CLAUDE_BIN', 'claude')) ?: 'claude',
            claudeTimeout: (int) $env->get('CLAUDE_TIMEOUT', '0'),
            claudeStallTimeout: (int) $env->get('CLAUDE_STALL_TIMEOUT', '900'),
            claudeExtraArgs: $claudeExtraArgs,
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
            engine: $engine,
            opencodeBin: trim((string) $env->get('OPENCODE_BIN', 'opencode')) ?: 'opencode',
            opencodeModel: trim((string) $env->get('OPENCODE_MODEL', '')),
            opencodeExtraArgs: array_values($env->list('OPENCODE_EXTRA_ARGS')),
            configWarnings: $configWarnings,
            claudeModels: $claudeModels,
            modelEpicTick: $modelEpicTick,
            modelEpicFinal: $modelEpicFinal,
            modelEpicReview: $modelEpicReview,
            secretEnvFiles: array_values($env->list('SECRET_ENV_FILES')),
            secretScanExclude: array_values($env->list('SECRET_SCAN_EXCLUDE')),
            providerEnv: $env->providerEnv(),
            anthropicBaseUrl: trim((string) $env->get('ANTHROPIC_BASE_URL', '')) !== '' ? trim((string) $env->get('ANTHROPIC_BASE_URL')) : null,
            anthropicModel: trim((string) $env->get('ANTHROPIC_MODEL', '')) !== '' ? trim((string) $env->get('ANTHROPIC_MODEL')) : null,
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

    /**
     * Engine fingerprint of the current configuration. A stored session is resumed only when its
     * `sessions.engine_fingerprint` (or `NULL`, meaning {@see self::DEFAULT_ENGINE_FINGERPRINT}) equals
     * this. It is the engine name plus the host of `ANTHROPIC_BASE_URL` (when that is set); the model
     * is appended only when the base URL points to a non-Anthropic host, because there a model switch
     * is a provider switch. `$model` is the `--model` the run passes (null when none), otherwise
     * `ANTHROPIC_MODEL` is used.
     *
     * An OpenCode instance has no `ANTHROPIC_BASE_URL` fingerprint: its model is pinned by the instance
     * in `OPENCODE_MODEL`, so the fingerprint is `opencode:<OPENCODE_MODEL>`, or `opencode:default`
     * when the key is empty. Changing `OPENCODE_MODEL` therefore starts a new session with the
     * {@see Locale} notice, like changing the engine. `$model` is a Claude Code alias and is ignored
     * here — the model of an OpenCode run comes from the instance, not from the topic.
     */
    public function engineFingerprint(?string $model = null): string
    {
        if ($this->isOpencode()) {
            return $this->opencodeModel !== ''
                ? self::ENGINE_OPENCODE.':'.$this->opencodeModel
                : self::ENGINE_OPENCODE.':default';
        }

        $host = $this->anthropicBaseHost();
        if ($host === null) {
            return $this->engine;
        }

        $fingerprint = $this->engine.'|'.$host;
        if (!$this->isAnthropicBaseUrl()) {
            $effective = $model !== null && trim($model) !== '' ? $model : $this->anthropicModel;
            if ($effective !== null && trim($effective) !== '') {
                $fingerprint .= '|'.$effective;
            }
        }

        return $fingerprint;
    }

    /**
     * Host of `ANTHROPIC_BASE_URL`, lowercased; null when the URL is unset or its host cannot be
     * parsed (both count as the default Anthropic endpoint).
     */
    public function anthropicBaseHost(): ?string
    {
        $url = trim((string) $this->anthropicBaseUrl);
        if ($url === '') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Whether the configured endpoint is Anthropic itself: `ANTHROPIC_BASE_URL` unset or unparseable
     * (the default subscription), or its host is `api.anthropic.com` / `*.anthropic.com`.
     */
    public function isAnthropicBaseUrl(): bool
    {
        $host = $this->anthropicBaseHost();
        if ($host === null) {
            return true;
        }

        return $host === 'api.anthropic.com' || str_ends_with($host, '.anthropic.com');
    }

    /**
     * Whether runs go to a foreign (non-Anthropic) endpoint: the base URL is set to a host that is
     * neither `api.anthropic.com` nor `*.anthropic.com`. Only then do foreign-provider limits apply.
     */
    public function usesForeignProvider(): bool
    {
        return !$this->isAnthropicBaseUrl();
    }

    /**
     * Whether the instance runs OpenCode ({@see self::ENGINE_OPENCODE}): the factory then builds an
     * {@see OpencodeRunner}, gated modes are refused, epics are unavailable and `/model` is read-only.
     */
    public function isOpencode(): bool
    {
        return $this->engine === self::ENGINE_OPENCODE;
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
