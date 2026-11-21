<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    private string $repoDir;
    private string $envFile;

    protected function setUp(): void
    {
        $this->repoDir = sys_get_temp_dir().'/tgrepo_'.uniqid('', true);
        mkdir($this->repoDir.'/.git', 0777, true);
        $this->envFile = $this->repoDir.'/.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        @rmdir($this->repoDir.'/.git');
        @rmdir($this->repoDir.'/state/outbox');
        @rmdir($this->repoDir.'/state/inbox');
        @rmdir($this->repoDir.'/state');
        @rmdir($this->repoDir);
    }

    private function writeEnv(string $extra = ''): void
    {
        $base = <<<ENV
            BOT_TOKEN=secret-token
            ALLOWED_USER_IDS=111, 222
            REPO_PATH={$this->repoDir}
            GITHUB_REPO=owner/repo
            CLAUDE_BIN=/usr/bin/claude
            STATE_DIR={$this->repoDir}/state
            ENV;
        file_put_contents($this->envFile, $base."\n".$extra);
    }

    public function testAutoCommitDefaultsToOn(): void
    {
        $this->writeEnv();
        self::assertTrue(Config::load($this->envFile)->autoCommit);

        $this->writeEnv('AUTO_COMMIT=1');
        self::assertTrue(Config::load($this->envFile)->autoCommit);
    }

    public function testSecretScanExcludeIsAListOfGlobsEmptyByDefault(): void
    {
        $this->writeEnv();
        self::assertSame([], Config::load($this->envFile)->secretScanExclude);

        $this->writeEnv('SECRET_SCAN_EXCLUDE=tests/fixtures/*, docs/*.md');
        self::assertSame(['tests/fixtures/*', 'docs/*.md'], Config::load($this->envFile)->secretScanExclude);
    }

    public function testAutoCommitZeroTurnsItOff(): void
    {
        $this->writeEnv('AUTO_COMMIT=0');
        self::assertFalse(Config::load($this->envFile)->autoCommit);
    }

    public function testInvalidAutoCommitFailsTheStart(): void
    {
        $this->writeEnv('AUTO_COMMIT=yes');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid AUTO_COMMIT=yes');
        Config::load($this->envFile);
    }

    public function testFailedRunEvidenceDaysDefaultsToFourteenAndClampsNegativeValues(): void
    {
        $this->writeEnv();
        self::assertSame(14, Config::load($this->envFile)->failedRunEvidenceDays);

        $this->writeEnv('FAILED_RUN_EVIDENCE_DAYS=3');
        self::assertSame(3, Config::load($this->envFile)->failedRunEvidenceDays);

        $this->writeEnv('FAILED_RUN_EVIDENCE_DAYS=-1');
        self::assertSame(0, Config::load($this->envFile)->failedRunEvidenceDays);
    }

    /** Off unless the key is set, and never handed to a run of the engine that reads the user scope itself. */
    public function testTheMemorySettingIsOffByDefaultAndIgnoredOnClaude(): void
    {
        $this->writeEnv();
        $default = Config::load($this->envFile);
        self::assertSame('', $default->memory3LayerBin);
        self::assertSame('', $default->memoryBin());
        self::assertSame(Config::DEFAULT_MEMORY_CONTEXT_MAX_BYTES, $default->memory3LayerContextMaxBytes);

        $this->writeEnv("MEMORY_3LAYER_BIN=/usr/local/bin/memory-3layer\nMEMORY_3LAYER_CONTEXT_MAX_BYTES=1024");
        $config = Config::load($this->envFile);
        self::assertSame('/usr/local/bin/memory-3layer', $config->memory3LayerBin);
        self::assertSame(1024, $config->memory3LayerContextMaxBytes);
        self::assertSame('', $config->memoryBin(), 'a Claude Code run takes its memory from the user scope');

        $this->writeEnv('MEMORY_3LAYER_CONTEXT_MAX_BYTES=-5');
        self::assertSame(0, Config::load($this->envFile)->memory3LayerContextMaxBytes);
    }

    public function testTheMemorySettingReachesAnOpencodeInstance(): void
    {
        $this->writeEnv("ENGINE=opencode\nMEMORY_3LAYER_BIN=/usr/local/bin/memory-3layer");

        self::assertSame('/usr/local/bin/memory-3layer', Config::load($this->envFile)->memoryBin());
    }

    /** The client is started without a shell, so a name that is not an absolute path is a startup warning. */
    public function testARelativeMemoryBinIsWarnedAboutAtStartup(): void
    {
        $this->writeEnv('MEMORY_3LAYER_BIN=memory-3layer');
        $warnings = implode("\n", Config::load($this->envFile)->startupWarnings());

        self::assertStringContainsString('MEMORY_3LAYER_BIN=memory-3layer is not an absolute path', $warnings);
    }

    public function testEpicQuietMinutesDefaultsToTen(): void
    {
        $this->writeEnv();
        self::assertSame(10, Config::load($this->envFile)->epicQuietMinutes);
    }

    public function testEpicPollSecondsDefaultsAndClamps(): void
    {
        $this->writeEnv();
        self::assertSame(30, Config::load($this->envFile)->epicPollSeconds);

        $this->writeEnv('EPIC_POLL_SECONDS=45');
        self::assertSame(45, Config::load($this->envFile)->epicPollSeconds);

        $this->writeEnv('EPIC_POLL_SECONDS=3');
        self::assertSame(10, Config::load($this->envFile)->epicPollSeconds);
    }

    public function testLoadsValidConfig(): void
    {
        $this->writeEnv();

        $config = Config::load($this->envFile);

        self::assertSame('secret-token', $config->botToken);
        self::assertSame([111, 222], $config->allowedUserIds);
        self::assertSame($this->repoDir, $config->repoPath);
        self::assertSame('owner/repo', $config->forgeRepo);
        self::assertSame('/usr/bin/claude', $config->claudeBin);
        self::assertSame(['--dangerously-skip-permissions'], $config->claudeExtraArgs);
        self::assertSame(0, $config->claudeTimeout);
    }

    public function testForgeOwnersAreParsedWithDefaultFirst(): void
    {
        $this->writeEnv("GITHUB_OWNERS= anime-db , gpslab,\n");

        self::assertSame(['anime-db', 'gpslab'], Config::load($this->envFile)->forgeOwners->all());
        self::assertSame('anime-db', Config::load($this->envFile)->forgeOwners->default());
    }

    public function testForgeDefaultsToGithub(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertSame('github', $config->forge);
        self::assertSame('github.com', $config->forgeHost);
        self::assertTrue($config->epicsAvailable());
        self::assertFalse($config->forgeOwners->isNested());
    }

    public function testGitlabForgeDefaultsHostAndAllowsNamespaceOwners(): void
    {
        $this->writeEnv("FORGE=gitlab\nFORGE_OWNERS=group/sub,top\n");
        $config = Config::load($this->envFile);

        self::assertSame('gitlab', $config->forge);
        self::assertSame('gitlab.com', $config->forgeHost);
        self::assertFalse($config->epicsAvailable());
        self::assertSame(['group/sub', 'proj', 12], $config->forgeOwners->parse('group/sub/proj#12'));
    }

    public function testForgeNameAndCliFollowTheForge(): void
    {
        $this->writeEnv();
        $github = Config::load($this->envFile);
        self::assertSame('GitHub', $github->forgeName());
        self::assertSame('gh', $github->forgeCli());

        $this->writeEnv("FORGE=gitlab\n");
        $gitlab = Config::load($this->envFile);
        self::assertSame('GitLab', $gitlab->forgeName());
        self::assertSame('glab', $gitlab->forgeCli());
    }

    public function testExplicitForgeHostOverridesTheDefault(): void
    {
        $this->writeEnv("FORGE=gitlab\nFORGE_HOST=git.example.org\n");

        self::assertSame('git.example.org', Config::load($this->envFile)->forgeHost);
    }

    public function testInvalidForgeFailsTheStart(): void
    {
        $this->writeEnv('FORGE=bitbucket');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid FORGE=bitbucket');
        Config::load($this->envFile);
    }

    public function testEngineDefaultsToClaude(): void
    {
        $this->writeEnv();
        self::assertSame('claude', Config::load($this->envFile)->engine);
    }

    public function testEngineClaudeIsAccepted(): void
    {
        $this->writeEnv('ENGINE=claude');
        self::assertSame('claude', Config::load($this->envFile)->engine);
    }

    public function testEngineOpencodeIsAccepted(): void
    {
        $this->writeEnv('ENGINE=opencode');
        self::assertSame('opencode', Config::load($this->envFile)->engine);
    }

    public function testUnknownEngineFailsTheStart(): void
    {
        $this->writeEnv('ENGINE=gemini');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid ENGINE=gemini in .env: expected claude or opencode.');
        Config::load($this->envFile);
    }

    public function testNewForgeKeysWinOverTheAliasesAndWarnOnce(): void
    {
        $this->writeEnv("FORGE_OWNERS=new\nGITHUB_OWNERS=old\nFORGE_REPO=new/repo\nGITHUB_REPO=old/repo\nOPENRONIN_LOGINS=bot\n");
        $config = Config::load($this->envFile);

        self::assertSame(['new'], $config->forgeOwners->all());
        self::assertSame('new/repo', $config->forgeRepo);
        $warnings = $config->startupWarnings();
        self::assertCount(2, $warnings);
        self::assertStringContainsString('FORGE_REPO', $warnings[0]);
        self::assertStringContainsString('FORGE_OWNERS', $warnings[1]);
    }

    public function testAliasesAloneAreAcceptedWithoutWarning(): void
    {
        $this->writeEnv("GITHUB_OWNERS=old\nOPENRONIN_LOGINS=bot\n");
        $config = Config::load($this->envFile);

        self::assertSame(['old'], $config->forgeOwners->all());
        self::assertSame('owner/repo', $config->forgeRepo);
        self::assertSame([], $config->startupWarnings());
    }

    public function testOpenroninLoginsAreParsedAndSilenceTheWarning(): void
    {
        $this->writeEnv("OPENRONIN_LOGINS= anime-db-bot , other,\n");
        $config = Config::load($this->envFile);

        self::assertSame(['anime-db-bot', 'other'], $config->openroninLogins);
        self::assertSame([], $config->startupWarnings());
    }

    public function testOpenroninLoginsUnsetWarnsOnce(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertSame([], $config->openroninLogins);
        self::assertCount(1, $config->startupWarnings());
        self::assertStringContainsString('OPENRONIN_LOGINS', $config->startupWarnings()[0]);
    }

    public function testForgeOwnersUnsetHasNoDefault(): void
    {
        $this->writeEnv();

        self::assertTrue(Config::load($this->envFile)->forgeOwners->isEmpty());
    }

    public function testIsAllowed(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertTrue($config->isAllowed(111));
        self::assertFalse($config->isAllowed(999));
    }

    public function testCreatesStateDir(): void
    {
        $this->writeEnv();
        Config::load($this->envFile);

        self::assertDirectoryExists($this->repoDir.'/state');
    }

    public function testRelativeStateDirIsResolvedAgainstInstallDirNotCwd(): void
    {
        $relative = 'state/configtest_'.uniqid('', true);
        $expected = dirname(__DIR__).'/'.$relative;
        $this->writeEnv("STATE_DIR={$relative}");
        $cwd = (string) getcwd();
        chdir($this->repoDir);
        try {
            $config = Config::load($this->envFile);
        } finally {
            chdir($cwd);
        }

        try {
            self::assertSame($expected, $config->stateDir);
            self::assertDirectoryExists($expected.'/outbox');
            self::assertDirectoryDoesNotExist($this->repoDir.'/'.$relative);
        } finally {
            @rmdir($expected.'/outbox');
            @rmdir($expected.'/inbox');
            @rmdir($expected);
        }
    }

    public function testResolvePathKeepsAbsoluteAndEmptyValues(): void
    {
        self::assertSame('/var/lib/bot', Config::resolvePath('/var/lib/bot', '/opt/app'));
        self::assertSame('', Config::resolvePath('', '/opt/app'));
        self::assertSame('/opt/app/data', Config::resolvePath('data', '/opt/app/'));
    }

    public function testCreatesOutboxDir(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertDirectoryExists($this->repoDir.'/state/outbox');
        self::assertSame($this->repoDir.'/state/outbox', $config->outboxDir);
    }

    public function testCreatesInboxDir(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertDirectoryExists($this->repoDir.'/state/inbox');
        self::assertSame($this->repoDir.'/state/inbox', $config->inboxDir);
    }

    public function testRejectsPlaceholderToken(): void
    {
        file_put_contents(
            $this->envFile,
            "BOT_TOKEN=PASTE_YOUR_BOT_TOKEN_HERE\nALLOWED_USER_IDS=1\nREPO_PATH={$this->repoDir}\n"
        );

        $this->expectException(\RuntimeException::class);
        Config::load($this->envFile);
    }

    public function testRejectsEmptyAllowedUsers(): void
    {
        file_put_contents(
            $this->envFile,
            "BOT_TOKEN=t\nALLOWED_USER_IDS=\nREPO_PATH={$this->repoDir}\n"
        );

        $this->expectException(\RuntimeException::class);
        Config::load($this->envFile);
    }

    public function testRejectsNonGitRepoPath(): void
    {
        file_put_contents(
            $this->envFile,
            "BOT_TOKEN=t\nALLOWED_USER_IDS=1\nREPO_PATH=/tmp/definitely-not-a-repo-".uniqid()."\n"
        );

        $this->expectException(\RuntimeException::class);
        Config::load($this->envFile);
    }

    public function testEpicsDirDefaultsToNeutralPath(): void
    {
        $this->writeEnv();

        self::assertSame('.workspace-assistant/epics', Config::load($this->envFile)->epicsDir);
    }

    public function testEpicsDirIsConfigurable(): void
    {
        $this->writeEnv("EPICS_DIR=/context/epics/\n");

        self::assertSame('context/epics', Config::load($this->envFile)->epicsDir);
    }

    public function testSubreposDirDisabledByDefault(): void
    {
        $this->writeEnv();

        self::assertSame('', Config::load($this->envFile)->subreposDir);
    }

    public function testSubreposDirIsConfigurable(): void
    {
        $this->writeEnv("SUBREPOS_DIR=docs/\n");

        self::assertSame('docs', Config::load($this->envFile)->subreposDir);
    }

    public function testTimezoneDefaultsToUtc(): void
    {
        $this->writeEnv();

        self::assertSame('UTC', Config::load($this->envFile)->timezone);
    }

    public function testTimezoneIsConfigurable(): void
    {
        $this->writeEnv("TIMEZONE=Europe/Moscow\n");

        self::assertSame('Europe/Moscow', Config::load($this->envFile)->timezone);
    }

    public function testInvalidTimezoneFails(): void
    {
        $this->writeEnv("TIMEZONE=Mars/Olympus\n");

        $this->expectException(\RuntimeException::class);
        Config::load($this->envFile);
    }

    public function testLocaleDefaultsToEnglishAndLocalesDirIsUnset(): void
    {
        $this->writeEnv();

        $config = Config::load($this->envFile);

        self::assertSame('en', $config->botLocale);
        self::assertSame('', $config->localesDir);
    }

    public function testReadsBotLocaleAndLocalesDir(): void
    {
        $this->writeEnv("BOT_LOCALE= ru \nLOCALES_DIR=/instance/locales/\n");

        $config = Config::load($this->envFile);

        self::assertSame('ru', $config->botLocale);
        self::assertSame('instance/locales', $config->localesDir);
    }

    public function testClaudeModelsDefaultAndOverride(): void
    {
        $this->writeEnv();
        self::assertSame(['fable', 'opus[1m]', 'opus', 'sonnet', 'haiku'], Config::load($this->envFile)->claudeModels);

        $this->writeEnv('CLAUDE_MODELS=sonnet, claude-haiku-4-5-20251001');
        self::assertSame(['sonnet', 'claude-haiku-4-5-20251001'], Config::load($this->envFile)->claudeModels);
    }

    public function testEpicModelKeysDefaultEmpty(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertSame('', $config->modelEpicTick);
        self::assertSame('', $config->modelEpicFinal);
        self::assertSame('', $config->modelEpicReview);
    }

    public function testEpicModelKeysAreRead(): void
    {
        $this->writeEnv("MODEL_EPIC_TICK= sonnet \nMODEL_EPIC_FINAL=opus\nMODEL_EPIC_REVIEW=opus[1m]\n");
        $config = Config::load($this->envFile);

        self::assertSame('sonnet', $config->modelEpicTick);
        self::assertSame('opus', $config->modelEpicFinal);
        self::assertSame('opus[1m]', $config->modelEpicReview);
    }

    public function testEpicModelKeyOutsideClaudeModelsFailsTheStart(): void
    {
        $this->writeEnv('MODEL_EPIC_TICK=nosuchmodel');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MODEL_EPIC_TICK=nosuchmodel in .env is not in CLAUDE_MODELS.');
        Config::load($this->envFile);
    }

    public function testEpicModelKeyOutsideClaudeModelsFailsForEveryKey(): void
    {
        foreach (['MODEL_EPIC_TICK', 'MODEL_EPIC_FINAL', 'MODEL_EPIC_REVIEW'] as $key) {
            $this->writeEnv($key.'=nosuchmodel');
            try {
                Config::load($this->envFile);
                self::fail($key.' outside CLAUDE_MODELS must fail the start');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($key.'=nosuchmodel', $e->getMessage());
            }
        }
    }

    public function testEpicModelKeysAreIgnoredWhenExtraArgsModelPinsTheInstance(): void
    {
        foreach (["MODEL_EPIC_TICK=sonnet\nCLAUDE_EXTRA_ARGS=--model,haiku", "MODEL_EPIC_TICK=sonnet\nCLAUDE_EXTRA_ARGS=--model=haiku"] as $env) {
            $this->writeEnv("OPENRONIN_LOGINS=bot\n".$env);
            self::assertSame(['MODEL_EPIC_TICK, MODEL_EPIC_FINAL and MODEL_EPIC_REVIEW are ignored: a --model in CLAUDE_EXTRA_ARGS pins the model for every run.'], Config::load($this->envFile)->startupWarnings());
        }
    }

    public function testEachEpicModelKeyIsReportedAsNotAppliedOnOpencode(): void
    {
        foreach (['MODEL_EPIC_TICK', 'MODEL_EPIC_FINAL', 'MODEL_EPIC_REVIEW'] as $key) {
            $this->writeEnv("OPENRONIN_LOGINS=bot\nENGINE=opencode\n{$key}=sonnet\n");
            self::assertSame(
                [$key.' is not applied on OpenCode: the model of every run comes from OPENCODE_MODEL.'],
                Config::load($this->envFile)->startupWarnings(),
            );
        }
    }

    public function testEverySetEpicModelKeyIsNamedInTheSingleOpencodeWarning(): void
    {
        $this->writeEnv("OPENRONIN_LOGINS=bot\nENGINE=opencode\nMODEL_EPIC_TICK=sonnet\nMODEL_EPIC_FINAL=opus\nMODEL_EPIC_REVIEW=opus\n");

        self::assertSame(
            ['MODEL_EPIC_TICK, MODEL_EPIC_FINAL and MODEL_EPIC_REVIEW are not applied on OpenCode: the model of every run comes from OPENCODE_MODEL.'],
            Config::load($this->envFile)->startupWarnings(),
        );
    }

    public function testEpicModelKeyOutsideClaudeModelsDoesNotFailOnOpencode(): void
    {
        // On OpenCode CLAUDE_MODELS does not apply: a provider/model value (the natural form there)
        // must not stop the start — it is reported as not applied instead.
        $this->writeEnv("OPENRONIN_LOGINS=bot\nENGINE=opencode\nMODEL_EPIC_TICK=openai/gpt-5\n");

        self::assertSame(
            ['MODEL_EPIC_TICK is not applied on OpenCode: the model of every run comes from OPENCODE_MODEL.'],
            Config::load($this->envFile)->startupWarnings(),
        );
    }

    public function testEpicModelKeyOutsideClaudeModelsIsOnlyWarnedOnOpencodeForEveryKey(): void
    {
        foreach (['MODEL_EPIC_TICK', 'MODEL_EPIC_FINAL', 'MODEL_EPIC_REVIEW'] as $key) {
            $this->writeEnv("OPENRONIN_LOGINS=bot\nENGINE=opencode\n{$key}=openai/gpt-5\n");
            $config = Config::load($this->envFile);
            self::assertStringContainsString($key, $config->startupWarnings()[0]);
            self::assertStringContainsString('is not applied on OpenCode', $config->startupWarnings()[0]);
        }
    }

    public function testNoOpencodeWarningWithoutAnEpicModelKey(): void
    {
        $this->writeEnv("OPENRONIN_LOGINS=bot\nENGINE=opencode\n");

        self::assertSame([], Config::load($this->envFile)->startupWarnings());
    }

    public function testNoOpencodeWarningOnClaude(): void
    {
        $this->writeEnv("OPENRONIN_LOGINS=bot\nMODEL_EPIC_TICK=sonnet\n");

        self::assertSame([], Config::load($this->envFile)->startupWarnings());
    }

    public function testNoEpicWarningWhenNoEpicKeyIsSetOrNoExtraArgsModel(): void
    {
        $this->writeEnv("OPENRONIN_LOGINS=bot\n");
        self::assertSame([], Config::load($this->envFile)->startupWarnings());

        $this->writeEnv("OPENRONIN_LOGINS=bot\nCLAUDE_EXTRA_ARGS=--model,haiku");
        self::assertSame([], Config::load($this->envFile)->startupWarnings());
    }

    public function testSecretEnvFilesAreAList(): void
    {
        $this->writeEnv();
        self::assertSame([], Config::load($this->envFile)->secretEnvFiles);

        $this->writeEnv('SECRET_ENV_FILES=~/.secrets.env, /etc/app/prod.env');
        self::assertSame(['~/.secrets.env', '/etc/app/prod.env'], Config::load($this->envFile)->secretEnvFiles);
    }

    public function testProviderSettingsAndPassthroughKeysAreCollected(): void
    {
        $this->writeEnv(<<<'ENV'
            ANTHROPIC_BASE_URL=https://api.openrouter.ai/v1
            ANTHROPIC_MODEL=sonnet
            ANTHROPIC_AUTH_TOKEN=provider-token
            CLAUDE_CODE_SUBAGENT_MODEL=haiku
            NOT_A_PROVIDER_KEY=value
            ENV);

        $config = Config::load($this->envFile);

        self::assertSame('https://api.openrouter.ai/v1', $config->anthropicBaseUrl);
        self::assertSame('sonnet', $config->anthropicModel);
        self::assertSame([
            'ANTHROPIC_BASE_URL' => 'https://api.openrouter.ai/v1',
            'ANTHROPIC_MODEL' => 'sonnet',
            'ANTHROPIC_AUTH_TOKEN' => 'provider-token',
            'CLAUDE_CODE_SUBAGENT_MODEL' => 'haiku',
        ], $config->providerEnv);
    }

    public function testProviderSettingsUnsetAreNullAndPassthroughIsEmpty(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertNull($config->anthropicBaseUrl);
        self::assertNull($config->anthropicModel);
        self::assertSame([], $config->providerEnv);
    }

    /** A minimal Config for the fingerprint helpers, with the provider-relevant fields overridden. */
    private function fingerprintConfig(?string $anthropicBaseUrl = null, ?string $anthropicModel = null, string $engine = Config::DEFAULT_ENGINE_FINGERPRINT, string $opencodeModel = ''): Config
    {
        return new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: sys_get_temp_dir(),
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 30,
            anthropicBaseUrl: $anthropicBaseUrl,
            anthropicModel: $anthropicModel,
            engine: $engine,
            opencodeModel: $opencodeModel,
        );
    }

    public function testEngineFingerprintWithoutBaseUrlIsTheDefault(): void
    {
        $config = $this->fingerprintConfig();

        self::assertSame(Config::DEFAULT_ENGINE_FINGERPRINT, $config->engineFingerprint());
        // `NULL` (the "no model" argument) maps to the same default.
        self::assertSame($config->engineFingerprint(null), $config->engineFingerprint());
    }

    public function testModelDoesNotChangeFingerprintOnAnthropicHost(): void
    {
        $config = $this->fingerprintConfig('https://api.anthropic.com', 'sonnet');

        self::assertSame('claude|api.anthropic.com', $config->engineFingerprint());
        // Neither ANTHROPIC_MODEL nor the --model argument changes the fingerprint on Anthropic.
        self::assertSame('claude|api.anthropic.com', $config->engineFingerprint('opus'));

        $other = $this->fingerprintConfig('https://api.anthropic.com', 'haiku');
        self::assertSame($config->engineFingerprint(), $other->engineFingerprint());
    }

    public function testAnthropicModelChangesFingerprintOnForeignHost(): void
    {
        $sonnet = $this->fingerprintConfig('https://openrouter.ai', 'sonnet');
        $haiku = $this->fingerprintConfig('https://openrouter.ai', 'haiku');

        self::assertSame('claude|openrouter.ai|sonnet', $sonnet->engineFingerprint());
        self::assertNotSame($sonnet->engineFingerprint(), $haiku->engineFingerprint());
    }

    public function testModelArgumentChangesFingerprintOnForeignHost(): void
    {
        $config = $this->fingerprintConfig('https://openrouter.ai', 'sonnet');

        self::assertSame('claude|openrouter.ai|sonnet', $config->engineFingerprint());
        self::assertSame('claude|openrouter.ai|opus', $config->engineFingerprint('opus'));
    }

    public function testBaseUrlHostChangeChangesFingerprint(): void
    {
        $a = $this->fingerprintConfig('https://openrouter.ai');
        $b = $this->fingerprintConfig('https://api.deepseek.com');

        self::assertSame('claude|openrouter.ai', $a->engineFingerprint());
        self::assertSame('claude|api.deepseek.com', $b->engineFingerprint());
        self::assertNotSame($a->engineFingerprint(), $b->engineFingerprint());
    }

    public function testAnthropicHostDetection(): void
    {
        self::assertTrue($this->fingerprintConfig()->isAnthropicBaseUrl());
        self::assertFalse($this->fingerprintConfig()->usesForeignProvider());

        self::assertTrue($this->fingerprintConfig('https://api.anthropic.com')->isAnthropicBaseUrl());
        self::assertTrue($this->fingerprintConfig('https://eu.anthropic.com')->isAnthropicBaseUrl());

        self::assertFalse($this->fingerprintConfig('https://openrouter.ai')->isAnthropicBaseUrl());
        self::assertTrue($this->fingerprintConfig('https://openrouter.ai')->usesForeignProvider());
    }

    public function testOpencodeKeysKeepTheirDefaultsWhenUnset(): void
    {
        $this->writeEnv();
        $config = Config::load($this->envFile);

        self::assertSame('opencode', $config->opencodeBin);
        self::assertSame('', $config->opencodeModel);
        self::assertSame([], $config->opencodeExtraArgs);
        self::assertFalse($config->isOpencode());
        self::assertSame('claude', $config->engine);
    }

    public function testOpencodeKeysAreParsedFromEnv(): void
    {
        $this->writeEnv("OPENCODE_BIN=/usr/bin/opencode\nOPENCODE_MODEL=anthropic/sonnet\nOPENCODE_EXTRA_ARGS=--foo, bar\n");
        $config = Config::load($this->envFile);

        self::assertSame('/usr/bin/opencode', $config->opencodeBin);
        self::assertSame('anthropic/sonnet', $config->opencodeModel);
        self::assertSame(['--foo', 'bar'], $config->opencodeExtraArgs);
    }

    public function testEmptyOpencodeBinFallsBackToTheDefaultBinary(): void
    {
        // An empty OPENCODE_BIN must resolve to `opencode` exactly as the installer probes it:
        // otherwise the installer check passes but every run starts an empty argv[0].
        $this->writeEnv("ENGINE=opencode\nOPENCODE_BIN=\n");

        self::assertSame('opencode', Config::load($this->envFile)->opencodeBin);
    }

    public function testEmptyClaudeBinFallsBackToTheDefaultBinary(): void
    {
        $this->writeEnv("CLAUDE_BIN=\n");

        self::assertSame('claude', Config::load($this->envFile)->claudeBin);
    }

    public function testOpencodeEngineIsAcceptedFromEnv(): void
    {
        $this->writeEnv("ENGINE=opencode\nOPENCODE_MODEL=anthropic/sonnet\n");
        $config = Config::load($this->envFile);

        self::assertTrue($config->isOpencode());
        self::assertSame(Config::ENGINE_OPENCODE, $config->engine);
        self::assertSame('anthropic/sonnet', $config->opencodeModel);
    }

    public function testOpencodeFingerprintCarriesTheInstanceModel(): void
    {
        $config = $this->fingerprintConfig(engine: Config::ENGINE_OPENCODE, opencodeModel: 'anthropic/sonnet');

        // A Claude Code alias never reaches the fingerprint: the model of the instance does.
        self::assertSame('opencode:anthropic/sonnet', $config->engineFingerprint());
        self::assertSame('opencode:anthropic/sonnet', $config->engineFingerprint('opus'));
    }

    public function testOpencodeFingerprintWithoutAModelIsTheDefault(): void
    {
        $config = $this->fingerprintConfig(engine: Config::ENGINE_OPENCODE);

        self::assertSame('opencode:default', $config->engineFingerprint());
        self::assertNotSame(
            $config->engineFingerprint(),
            $this->fingerprintConfig(engine: Config::ENGINE_OPENCODE, opencodeModel: 'anthropic/opus')->engineFingerprint(),
        );
    }

    public function testOpencodeEngineSetsTheGateAndKeepsTheConfiguredFields(): void
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: $this->repoDir,
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->repoDir.'/state',
            outboxDir: $this->repoDir.'/outbox',
            inboxDir: $this->repoDir.'/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            engine: Config::ENGINE_OPENCODE,
            opencodeModel: 'anthropic/sonnet',
        );

        self::assertTrue($config->isOpencode());
        self::assertSame('anthropic/sonnet', $config->opencodeModel);
    }
}
