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
}
