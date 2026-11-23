<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Kimi\KimiGatedPreconditions;

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

    /** Engine name of an instance that runs Kimi Code instead of Claude Code. */
    public const string ENGINE_KIMI = 'kimi';

    /**
     * Every value `ENGINE` accepts. The order is the one the startup error and the installer name them
     * in; anything else fails the start ({@see self::fromEnv()}).
     *
     * @var list<string>
     */
    public const array ENGINES = [self::DEFAULT_ENGINE_FINGERPRINT, self::ENGINE_OPENCODE, self::ENGINE_KIMI];

    /**
     * Ceiling of the memory context injected into one system prompt, per file (`MEMORY_3LAYER_CONTEXT_MAX_BYTES`).
     * The SESSION.md of a long-lived project is hundreds of KB; only its newest part is injected.
     */
    public const int DEFAULT_MEMORY_CONTEXT_MAX_BYTES = 32768;

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
        /** Agent engine the bot runs: one of {@see self::ENGINES}; anything else is rejected at startup. */
        public readonly string $engine = self::DEFAULT_ENGINE_FINGERPRINT,
        /** Path to the `opencode` binary (`OPENCODE_BIN`). */
        public readonly string $opencodeBin = 'opencode',
        /** Model of an OpenCode instance (`provider/model`, `OPENCODE_MODEL`); '' = the default model of OpenCode. */
        public readonly string $opencodeModel = '',
        /** @var list<string> extra argv for the OpenCode runner (`OPENCODE_EXTRA_ARGS`) */
        public readonly array $opencodeExtraArgs = [],
        /** Path to the `kimi` binary (`KIMI_BIN`); an absolute path is the only form that works from a service manager. */
        public readonly string $kimiBin = 'kimi',
        /** Home of the Kimi CLI (`KIMI_HOME`; `KIMI_CODE_HOME` for the engine itself); '' = the default home. */
        public readonly string $kimiHome = '',
        /** Model alias of a Kimi instance (`KIMI_MODEL`, passed as `-m`), as its own config defines it; '' = the model the engine picks. */
        public readonly string $kimiModel = '',
        /** @var list<string> extra argv for the Kimi runner (`KIMI_EXTRA_ARGS`) */
        public readonly array $kimiExtraArgs = [],
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
        /** Days the masked evidence of a failed run is kept (`FAILED_RUN_EVIDENCE_DAYS`); 0 = no age limit */
        public readonly int $failedRunEvidenceDays = 14,
        /** Absolute path of the `memory-3layer` client (`MEMORY_3LAYER_BIN`); '' = the memory integration is off */
        public readonly string $memory3LayerBin = '',
        /** Ceiling of the injected part of one memory file (`MEMORY_3LAYER_CONTEXT_MAX_BYTES`) */
        public readonly int $memory3LayerContextMaxBytes = self::DEFAULT_MEMORY_CONTEXT_MAX_BYTES,
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
        } elseif (!in_array($engine, self::ENGINES, true)) {
            throw new \RuntimeException("Invalid ENGINE={$engine} in .env: expected ".self::engineListText().'.');
        }

        $configWarnings = [];
        $forgeRepo = self::withAlias($env, 'FORGE_REPO', 'GITHUB_REPO', $configWarnings);
        $forgeOwners = self::withAlias($env, 'FORGE_OWNERS', 'GITHUB_OWNERS', $configWarnings);

        $epicsDir = trim((string) $env->get('EPICS_DIR', '.workspace-assistant/epics'), '/') ?: '.workspace-assistant/epics';

        // Path of the memory-3layer client. Empty = the feature is off, which is the default; the value
        // is used as it is, so an absolute path is the only form that works from a service manager:
        // a non-interactive shell has no `~/.local/bin` on PATH, and that is what a bare name means.
        $memory3LayerBin = trim((string) $env->get('MEMORY_3LAYER_BIN', ''));
        if ($memory3LayerBin !== '' && !str_starts_with($memory3LayerBin, '/')) {
            $configWarnings[] = "MEMORY_3LAYER_BIN={$memory3LayerBin} is not an absolute path: a non-interactive shell resolves it against PATH, where `~/.local/bin` usually is not.";
        }

        // Path of the Kimi CLI. The engine installs itself per user into `~/.kimi-code/bin`, which the
        // PATH of a non-interactive shell does not carry, so a value set here must be absolute; the bare
        // default `kimi` is left to PATH, exactly like `CLAUDE_BIN`.
        $kimiBin = trim((string) $env->get('KIMI_BIN', ''));
        if ($engine === self::ENGINE_KIMI && $kimiBin !== '' && !str_starts_with($kimiBin, '/')) {
            $configWarnings[] = "KIMI_BIN={$kimiBin} is not an absolute path: a non-interactive shell resolves it against PATH, where `~/.kimi-code/bin` usually is not.";
        }
        $kimiBin = $kimiBin !== '' ? $kimiBin : 'kimi';

        // Home of the logged-in Kimi CLI, which the run links its credentials, sessions and oauth to.
        // Unlike KIMI_BIN (a bare `kimi` can still resolve on PATH) a relative home never works: a
        // relative symlink target resolves against the link's own directory, so the links dangle and
        // the session cleanup globs a different directory. Reject it outright on Kimi.
        $kimiHome = trim((string) $env->get('KIMI_HOME', ''));
        if ($engine === self::ENGINE_KIMI && $kimiHome !== '' && !str_starts_with($kimiHome, '/')) {
            throw new \RuntimeException("KIMI_HOME={$kimiHome} in .env must be an absolute path: a relative home never works.");
        }

        $claudeExtraArgs = $env->list('CLAUDE_EXTRA_ARGS', '--dangerously-skip-permissions');
        $claudeModels = array_values(array_unique($env->list('CLAUDE_MODELS', implode(',', self::DEFAULT_CLAUDE_MODELS))));
        $claudeModelsSet = trim((string) $env->get('CLAUDE_MODELS', '')) !== '';
        $modelEpicTick = trim((string) $env->get('MODEL_EPIC_TICK', ''));
        $modelEpicFinal = trim((string) $env->get('MODEL_EPIC_FINAL', ''));
        $modelEpicReview = trim((string) $env->get('MODEL_EPIC_REVIEW', ''));
        $epicModelKeys = [
            'MODEL_EPIC_TICK' => $modelEpicTick,
            'MODEL_EPIC_FINAL' => $modelEpicFinal,
            'MODEL_EPIC_REVIEW' => $modelEpicReview,
        ];
        // CLAUDE_MODELS is a Claude Code list: an epic model key can legitimately hold a provider/model
        // value on an OpenCode instance, and on a Kimi one an alias of the engine's own `config.toml`,
        // so the membership check is skipped there — the keys are reported as not applied below instead
        // of failing the start.
        if ($engine === self::DEFAULT_ENGINE_FINGERPRINT) {
            foreach ($epicModelKeys as $key => $model) {
                if ($model !== '' && !in_array($model, $claudeModels, true)) {
                    throw new \RuntimeException("{$key}={$model} in .env is not in CLAUDE_MODELS.");
                }
            }
        }
        $setEpicModelKeys = array_keys(array_filter($epicModelKeys, static fn (string $model): bool => $model !== ''));
        // The model of an OpenCode run is OPENCODE_MODEL and of a Kimi run KIMI_MODEL, whatever the run
        // kind: the epic keys and the `/model` list are Claude-only, and an operator who set them has to
        // be told they are not applied here. One line, naming the keys that are actually set.
        if ($engine === self::ENGINE_OPENCODE) {
            if ($setEpicModelKeys !== []) {
                $configWarnings[] = self::joinKeys($setEpicModelKeys)
                    .(count($setEpicModelKeys) === 1 ? ' is not applied on OpenCode' : ' are not applied on OpenCode')
                    .': the model of every run comes from OPENCODE_MODEL.';
            }
        } elseif ($engine === self::ENGINE_KIMI) {
            $ignoredKeys = $setEpicModelKeys;
            if ($claudeModelsSet) {
                $ignoredKeys[] = 'CLAUDE_MODELS';
            }
            if ($ignoredKeys !== []) {
                $configWarnings[] = self::joinKeys($ignoredKeys)
                    .(count($ignoredKeys) === 1 ? ' is not applied on Kimi' : ' are not applied on Kimi')
                    .': /model is read-only and the model of every run comes from KIMI_MODEL.';
            }
        } elseif ($setEpicModelKeys !== [] && ClaudeRunner::modelFromArgs($claudeExtraArgs) !== null) {
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
            kimiBin: $kimiBin,
            kimiHome: $kimiHome,
            kimiModel: trim((string) $env->get('KIMI_MODEL', '')),
            kimiExtraArgs: array_values($env->list('KIMI_EXTRA_ARGS')),
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
            failedRunEvidenceDays: max(0, (int) $env->get('FAILED_RUN_EVIDENCE_DAYS', '14')),
            memory3LayerBin: $memory3LayerBin,
            memory3LayerContextMaxBytes: max(0, (int) $env->get('MEMORY_3LAYER_CONTEXT_MAX_BYTES', (string) self::DEFAULT_MEMORY_CONTEXT_MAX_BYTES)),
        );
    }

    /**
     * The accepted `ENGINE` values as one phrase — `claude, opencode or kimi`. The startup error and the
     * installer name the same list from here, so it cannot drift from {@see self::ENGINES}.
     */
    public static function engineListText(): string
    {
        $values = self::ENGINES;
        $last = (string) array_pop($values);

        return implode(', ', $values).' or '.$last;
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

    /**
     * Names of the `MODEL_EPIC_*` keys that are set, joined for a log line: `A`, `A and B`, `A, B and C`.
     *
     * @param list<string> $keys
     */
    private static function joinKeys(array $keys): string
    {
        if (count($keys) === 1) {
            return $keys[0];
        }
        $last = array_pop($keys);

        return implode(', ', $keys).' and '.$last;
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
     *
     * A Kimi instance is the same: the model is pinned by `KIMI_MODEL`, so the fingerprint is
     * `kimi:<KIMI_MODEL>`, or `kimi:default` when the key is empty (the model the engine picks itself).
     * Changing `KIMI_MODEL` therefore starts a new session with the {@see Locale} notice, like
     * changing the engine.
     */
    public function engineFingerprint(?string $model = null): string
    {
        if ($this->isOpencode()) {
            return $this->opencodeModel !== ''
                ? self::ENGINE_OPENCODE.':'.$this->opencodeModel
                : self::ENGINE_OPENCODE.':default';
        }
        if ($this->isKimi()) {
            return $this->kimiModel !== ''
                ? self::ENGINE_KIMI.':'.$this->kimiModel
                : self::ENGINE_KIMI.':default';
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
     * {@see OpencodeRunner}, the model of every run comes from `OPENCODE_MODEL` and `/model` is read-only.
     */
    public function isOpencode(): bool
    {
        return $this->engine === self::ENGINE_OPENCODE;
    }

    /**
     * Whether the instance runs Kimi Code ({@see self::ENGINE_KIMI}): `/model` is read-only, the model of
     * every run comes from `KIMI_MODEL`, and the bot's gates have no place to run yet — so the gated modes
     * and the epics are refused; lifting that refusal is a task of its own.
     */
    public function isKimi(): bool
    {
        return $this->engine === self::ENGINE_KIMI;
    }

    /**
     * Whether the engine has a Stop hook that can continue a turn: Claude Code's Stop hook blocks and
     * continues a review turn until its status is set. OpenCode's plugin hook and Kimi's Stop hook
     * cannot continue a `-p` turn (an exiting hook starts no continuation), so on those engines the
     * Bot takes the same decision after the run ({@see JobRunner::repeatReviewRun()}).
     */
    public function hasContinuableStopHook(): bool
    {
        return !$this->isOpencode() && !$this->isKimi();
    }

    /**
     * Whether the gates of the bot have nowhere to run on this engine: the PM gate of a `pm` or `review`
     * run (and of an epic run) and the review commit hook of a `review` run. A run without its gate looks
     * normal and is not, so while this answers "yes" the gated modes and the epic runs are refused
     * outright (see the queue and the /epic surface) instead of started.
     *
     * Kimi has nowhere to run those hooks yet, so the answer is a constant there: the task that lands its
     * gate shim changes this body alone, and every caller and its refusal text stay as they are.
     */
    public function gatesUnavailable(): bool
    {
        return $this->isKimi();
    }

    /**
     * Whether the engine reads no user scope, i.e. the bot itself has to deliver the memory of the
     * instance: `opencode` and `kimi` run without the `~/.claude/CLAUDE.md` and the hooks a Claude Code
     * run reads, so neither reaches the memory of the deployment on its own. Claude Code does read that
     * scope, which is why `MEMORY_3LAYER_BIN` changes nothing there.
     */
    public function readsNoUserScope(): bool
    {
        return $this->isOpencode() || $this->isKimi();
    }

    /**
     * `MEMORY_3LAYER_BIN` for the run: the path of the `memory-3layer` client on an engine that does not
     * read the user scope ({@see self::readsNoUserScope()}), '' otherwise. Claude Code takes its memory
     * from the user scope (its hooks and `~/.claude/CLAUDE.md`), so on that engine the setting changes
     * nothing — neither the injected context nor the background sync.
     */
    public function memoryBin(): string
    {
        return $this->readsNoUserScope() ? $this->memory3LayerBin : '';
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
     * @param string|null $homeDir home of the user the bot runs as; null = the real `$HOME`. A Kimi run
     *                             reads `$HOME/.agents` whatever run home it is given, so a present
     *                             directory is reported at start, not only when a gated run is refused
     *                             (the same check as the gate's, {@see KimiGatedPreconditions::homeAgentsPresent()}).
     *
     * @return list<string>
     */
    public function startupWarnings(?string $homeDir = null): array
    {
        $warnings = array_values($this->configWarnings);
        if ($this->openroninLogins === []) {
            $warnings[] = 'OPENRONIN_LOGINS is not set: the openronin marker is accepted from any author';
        }
        if ($this->isKimi() && KimiGatedPreconditions::homeAgentsPresent($homeDir ?? (string) getenv('HOME'))) {
            $warnings[] = KimiGatedPreconditions::HOME_AGENTS_REASON;
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
