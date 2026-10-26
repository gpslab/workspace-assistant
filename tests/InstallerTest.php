<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Env;
use GpsLab\WorkspaceAssistant\Installer;
use GpsLab\WorkspaceAssistant\InstallerServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Installer::class)]
#[CoversClass(Env::class)]
#[CoversClass(Config::class)]
final class InstallerTest extends TestCase
{
    private const TOKEN = '123456:ABCdefGhIjKlMnOpQrStUvWxYz';

    private string $root;
    private string $repo;
    private string $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/installer_'.uniqid('', true);
        $this->repo = $this->root.'/repo';
        $this->state = $this->root.'/state';
        mkdir($this->repo.'/.git', 0777, true);
        copy(dirname(__DIR__).'/composer.json', $this->root.'/composer.json');
        // The real .env.dist plus a STATE_DIR inside the temporary directory.
        file_put_contents(
            $this->root.'/.env.dist',
            (string) file_get_contents(dirname(__DIR__).'/.env.dist')."\nSTATE_DIR={$this->state}\n",
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testWritesLoadableEnvFromDist(): void
    {
        [$code, $out, $err] = $this->install($this->required());

        self::assertSame(0, $code, $err);
        $config = Config::load($this->root.'/.env');
        self::assertSame(self::TOKEN, $config->botToken);
        self::assertSame([1001, 1002], $config->allowedUserIds);
        self::assertSame($this->repo, $config->repoPath);
        self::assertSame(0600, $this->mode($this->root.'/.env'));
        self::assertFileDoesNotExist($this->root.'/.env.bak');
        self::assertDirectoryExists($this->state.'/inbox');
        self::assertDirectoryExists($this->state.'/outbox');
        self::assertSame([], glob($this->root.'/.env.tmp*'));
        self::assertStringContainsString('Wrote '.$this->root.'/.env', $out);
        self::assertStringContainsString('BOT_TOKEN=***WxYz (new)', $out);
        self::assertStringNotContainsString(self::TOKEN, $out.$err);
        self::assertStringContainsString('ALLOWED_USER_IDS=1001,1002 (new)', $out);
        self::assertStringContainsString('bin/bot.php', $out);
        self::assertStringContainsString('Installing as user: tester (euid=1000)', $out);
    }

    public function testRejectsRelativeRepoPath(): void
    {
        [$code, , $err] = $this->install(['--bot-token='.self::TOKEN, '--allowed-user-ids=1', '--repo-path=relative/kb']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('REPO_PATH: must be an absolute path', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testOptionWithoutValueDoesNotSwallowNextOption(): void
    {
        [$code, $out, $err] = $this->install(['--github-repo', '--bot-token='.self::TOKEN, '--allowed-user-ids=1', '--repo-path='.$this->repo]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('--github-repo requires a value', $err);
        self::assertStringNotContainsString(self::TOKEN, $out.$err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testValueStartingWithDashesIsAllowedForUnknownNames(): void
    {
        [$code, , $err] = $this->install([...$this->required(), '--claude-extra-args', '--permission-mode,acceptEdits']);

        self::assertSame(0, $code, $err);
    }

    public function testAcceptsSpaceSeparatedValues(): void
    {
        [$code, , $err] = $this->install(['--bot-token', self::TOKEN, '--allowed-user-ids', '5', '--repo-path', $this->repo]);

        self::assertSame(0, $code, $err);
        self::assertSame('5', Env::load($this->root.'/.env', true)->get('ALLOWED_USER_IDS'));
    }

    public function testBothParsersReadTheSameValues(): void
    {
        $args = [...$this->required(), '--github-repo=owner/repo', '--timezone=Europe/London', '--claude-extra-args=--permission-mode,acceptEdits'];
        [$code, , $err] = $this->install($args);
        self::assertSame(0, $code, $err);

        $path = $this->root.'/.env';
        $env = Env::load($path, true);
        $script = 'source '.escapeshellarg(dirname(__DIR__).'/bin/env.sh').'; load_env_file '.escapeshellarg($path)
            .'; for k in BOT_TOKEN ALLOWED_USER_IDS REPO_PATH GITHUB_REPO TIMEZONE CLAUDE_EXTRA_ARGS CLAUDE_BIN BOT_LOCALE; do printf "%s=%s\n" "$k" "${!k-}"; done';
        $bash = [];
        foreach (explode("\n", trim((string) shell_exec('env -i bash -c '.escapeshellarg($script)))) as $line) {
            [$k, $v] = explode('=', $line, 2);
            $bash[$k] = $v;
        }

        self::assertSame(self::TOKEN, $bash['BOT_TOKEN']);
        foreach ($bash as $key => $value) {
            self::assertSame($env->get($key, ''), $value, $key);
        }
    }

    public function testBashParserKeepsFirstOccurrenceAndExportedVariables(): void
    {
        $path = $this->root.'/fixture.env';
        file_put_contents($path, "A=first\nA=second\nB=file\n");
        $script = 'source '.escapeshellarg(dirname(__DIR__).'/bin/env.sh').'; load_env_file '.escapeshellarg($path).'; echo "$A $B"';

        self::assertSame("first kept\n", shell_exec('env -i B=kept bash -c '.escapeshellarg($script)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'line break' => ['--github-repo', "owner/repo\nINJECTED=1"];
        yield 'carriage return' => ['--github-repo', "owner/repo\rX"];
        yield 'hash' => ['--github-repo', 'owner/repo#1'];
        yield 'leading space' => ['--claude-bin', ' claude'];
        yield 'trailing space' => ['--claude-bin', 'claude '];
        yield 'double quotes' => ['--claude-bin', '"claude"'];
        yield 'single quotes' => ['--claude-bin', "'claude'"];
        yield 'trailing equals' => ['--claude-bin', 'claude='];
        yield 'non-digit user id' => ['--allowed-user-ids', '1001,abc'];
        yield 'user ids with space' => ['--allowed-user-ids', '1001, 1002'];
        yield 'empty user id item' => ['--allowed-user-ids', '1001,'];
        yield 'token placeholder' => ['--bot-token', 'PASTE_YOUR_BOT_TOKEN_HERE'];
        yield 'user ids placeholder' => ['--allowed-user-ids', '123456789'];
        yield 'repo placeholder' => ['--repo-path', '/path/to/your/repo'];
        yield 'unknown option' => ['--state-dir', '/tmp/x'];
    }

    #[DataProvider('invalidValueProvider')]
    public function testRejectsInvalidValuesWithoutWriting(string $option, string $value): void
    {
        $this->writeEnv("BOT_TOKEN=old\nOTHER=1\n");
        $before = (string) file_get_contents($this->root.'/.env');

        [$code, , $err] = $this->install([...$this->required(), "{$option}={$value}"]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('Nothing was written', $err);
        self::assertSame($before, (string) file_get_contents($this->root.'/.env'));
        self::assertSame([], glob($this->root.'/.env.tmp*'));
        self::assertFileDoesNotExist($this->root.'/.env.bak');
    }

    public function testReportsAllErrorsAtOnce(): void
    {
        [$code, , $err] = $this->install(['--allowed-user-ids=x', '--github-repo=a#b']);

        self::assertSame(1, $code);
        self::assertStringContainsString('BOT_TOKEN is required', $err);
        self::assertStringContainsString('ALLOWED_USER_IDS: must contain only digits', $err);
        self::assertStringContainsString('REPO_PATH is required', $err);
        self::assertStringContainsString('GITHUB_REPO: the value must not contain "#"', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testBarrierFailureLeavesEnvUntouched(): void
    {
        $this->writeEnv("BOT_TOKEN=old\nSTATE_DIR={$this->state}\n");
        $before = (string) file_get_contents($this->root.'/.env');

        [$code, , $err] = $this->install([...$this->required(), '--timezone=Not/AZone']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Invalid TIMEZONE=Not/AZone', $err);
        self::assertSame($before, (string) file_get_contents($this->root.'/.env'));
        self::assertSame([], glob($this->root.'/.env.tmp*'));
    }

    public function testBarrierRejectsRepoWithoutGit(): void
    {
        mkdir($this->root.'/plain');

        [$code, , $err] = $this->install(['--bot-token='.self::TOKEN, '--allowed-user-ids=1', '--repo-path='.$this->root.'/plain']);

        self::assertSame(1, $code);
        self::assertStringContainsString('does not look like a git repository', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
        self::assertSame([], glob($this->root.'/.env.tmp*'));
    }

    public function testMergeReplacesLastOccurrenceAndDropsEarlierDuplicates(): void
    {
        $this->writeEnv("# head\nBOT_TOKEN=one\nFOO=bar\nBOT_TOKEN=two\n\nBOT_TOKEN = \"three\"\n# tail\nSTATE_DIR={$this->state}\n");

        [$code, , $err] = $this->install($this->required());

        self::assertSame(0, $code, $err);
        $content = (string) file_get_contents($this->root.'/.env');
        self::assertSame(1, preg_match_all('/^\s*BOT_TOKEN\s*=/m', $content));
        self::assertStringStartsWith("# head\nFOO=bar\n\n", $content);
        self::assertStringContainsString("\n\nBOT_TOKEN=".self::TOKEN."\n# tail\n", $content);
        self::assertStringContainsString("FOO=bar\n", $content);
    }

    public function testMergeUncommentsKeyInPlaceAndAppendsNewOnes(): void
    {
        $this->writeEnv("# Repo of the workspace\n# GITHUB_REPO=owner/repo\nUNKNOWN_KEY=keep # not mine\n# a comment\nBOT_TOKEN=x\nSTATE_DIR={$this->state}\n");

        [$code, , $err] = $this->install([...$this->required(), '--github-repo=acme/work', '--bot-locale=ru']);

        self::assertSame(0, $code, $err);
        $lines = explode("\n", (string) file_get_contents($this->root.'/.env'));
        self::assertSame('# Repo of the workspace', $lines[0]);
        self::assertSame('GITHUB_REPO=acme/work', $lines[1]);
        self::assertSame('UNKNOWN_KEY=keep # not mine', $lines[2]);
        self::assertSame('# a comment', $lines[3]);
        self::assertSame('BOT_LOCALE=ru', $lines[count($lines) - 2]);
        self::assertSame('', $lines[count($lines) - 1]);
    }

    public function testDistIsTheBaseWithoutEnv(): void
    {
        $this->install([...$this->required(), '--github-owners=acme', '--bot-locale=de']);

        $content = (string) file_get_contents($this->root.'/.env');
        self::assertStringContainsString('# GitHub owners (users/orgs)', $content);
        self::assertMatchesRegularExpression('/^# Language of bot texts.*\n(?:#.*\n)*BOT_LOCALE=de$/m', $content);
        self::assertStringContainsString("\nGIT_TIMEOUT=120\n", $content);
        self::assertStringNotContainsString('PASTE_YOUR_BOT_TOKEN_HERE', $content);
        self::assertSame(1, preg_match_all('/^GITHUB_OWNERS=acme$/m', $content));
    }

    public function testExistingEnvSuppliesDefaultsAndIsBackedUp(): void
    {
        $this->writeEnv('BOT_TOKEN='.self::TOKEN."\nALLOWED_USER_IDS=7\nREPO_PATH={$this->repo}\nSTATE_DIR={$this->state}\n");
        $old = (string) file_get_contents($this->root.'/.env');

        [$code, $out, $err] = $this->install(['--allowed-user-ids=8']);

        self::assertSame(0, $code, $err);
        $env = Env::load($this->root.'/.env', true);
        self::assertSame('8', $env->get('ALLOWED_USER_IDS'));
        self::assertSame(self::TOKEN, $env->get('BOT_TOKEN'));
        self::assertSame($old, (string) file_get_contents($this->root.'/.env.bak'));
        self::assertSame(0600, $this->mode($this->root.'/.env.bak'));
        self::assertSame(0600, $this->mode($this->root.'/.env'));
        self::assertStringContainsString('ALLOWED_USER_IDS=8 (changed)', $out);
        self::assertStringContainsString('BOT_TOKEN=***WxYz (unchanged)', $out);
    }

    public function testBackupIsSingleAndOverwrittenEachRun(): void
    {
        $this->install($this->required());
        $second = (string) file_get_contents($this->root.'/.env');
        $this->install([...$this->required(), '--bot-locale=fr']);

        self::assertSame($second, (string) file_get_contents($this->root.'/.env.bak'));
        self::assertSame(['.env', '.env.bak', '.env.dist'], array_values(array_filter(scandir($this->root) ?: [], static fn (string $f): bool => str_starts_with($f, '.env'))));
    }

    public function testBackupPermissionsAreTightenedWhenItAlreadyExists(): void
    {
        $this->writeEnv("BOT_TOKEN=old\nSTATE_DIR={$this->state}\n");
        file_put_contents($this->root.'/.env.bak', 'stale');
        chmod($this->root.'/.env.bak', 0644);

        $this->install($this->required());

        self::assertSame(0600, $this->mode($this->root.'/.env.bak'));
    }

    public function testEnvironmentVariablesDoNotLeakIntoDefaultsOrBarrier(): void
    {
        $this->writeEnv("BOT_TOKEN={$this->tokenOther()}\nALLOWED_USER_IDS=7\nREPO_PATH={$this->repo}\nTIMEZONE=Europe/London\nSTATE_DIR={$this->state}\n");
        putenv('TIMEZONE=Not/AZone');
        putenv('ALLOWED_USER_IDS=');
        try {
            [$code, , $err] = $this->install(['--bot-locale=de']);
        } finally {
            putenv('TIMEZONE');
            putenv('ALLOWED_USER_IDS');
        }

        self::assertSame(0, $code, $err);
        $env = Env::load($this->root.'/.env', true);
        self::assertSame('Europe/London', $env->get('TIMEZONE'));
        self::assertSame('7', $env->get('ALLOWED_USER_IDS'));
    }

    public function testEnvFileOnlyIgnoresRealEnvironment(): void
    {
        $path = $this->root.'/f.env';
        file_put_contents($path, "INSTALLER_TEST_KEY=from-file\n");
        putenv('INSTALLER_TEST_KEY=from-env');
        try {
            self::assertSame('from-file', Env::load($path, true)->get('INSTALLER_TEST_KEY'));
            self::assertSame('from-env', Env::load($path)->get('INSTALLER_TEST_KEY'));
        } finally {
            putenv('INSTALLER_TEST_KEY');
        }
    }

    public function testConfigFromEnvMatchesLoad(): void
    {
        $this->install($this->required());
        $path = $this->root.'/.env';

        self::assertEquals(Config::load($path), Config::fromEnv(Env::load($path)));
    }

    public function testTokenFileTrimsTrailingNewline(): void
    {
        file_put_contents($this->root.'/token', self::TOKEN."\n");

        [$code, , $err] = $this->install(['--bot-token-file='.$this->root.'/token', '--allowed-user-ids=1', '--repo-path='.$this->repo]);

        self::assertSame(0, $code, $err);
        self::assertSame(self::TOKEN, Env::load($this->root.'/.env', true)->get('BOT_TOKEN'));
    }

    public function testTokenFromEnvironmentVariable(): void
    {
        [$code, , $err] = $this->install(['--allowed-user-ids=1', '--repo-path='.$this->repo], ['BOT_TOKEN' => self::TOKEN]);

        self::assertSame(0, $code, $err);
        self::assertSame(self::TOKEN, Env::load($this->root.'/.env', true)->get('BOT_TOKEN'));
    }

    public function testEnvironmentVariableConflictsWithTokenOption(): void
    {
        [$code, , $err] = $this->install($this->required(), ['BOT_TOKEN' => 'from-env']);

        self::assertSame(1, $code);
        self::assertStringContainsString('only one source', $err);
        self::assertStringContainsString('the BOT_TOKEN environment variable', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testEnvironmentVariableConflictsWithTokenFile(): void
    {
        file_put_contents($this->root.'/token', self::TOKEN);

        [$code, , $err] = $this->install(['--bot-token-file='.$this->root.'/token', '--allowed-user-ids=1', '--repo-path='.$this->repo], ['BOT_TOKEN' => 'from-env']);

        self::assertSame(1, $code);
        self::assertStringContainsString('only one source', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testTwoTokenOptionsConflict(): void
    {
        file_put_contents($this->root.'/token', self::TOKEN);

        [$code, , $err] = $this->install([...$this->required(), '--bot-token-file='.$this->root.'/token']);

        self::assertSame(1, $code);
        self::assertStringContainsString('only one source, got: --bot-token-file, --bot-token', $err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testMissingTokenFileIsAnError(): void
    {
        [$code, , $err] = $this->install(['--bot-token-file='.$this->root.'/nope', '--allowed-user-ids=1', '--repo-path='.$this->repo]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read the token file', $err);
    }

    public function testRefusesToRunAsRoot(): void
    {
        [$code, $out, $err] = $this->install($this->required(), [], 0, 'root');

        self::assertSame(1, $code);
        self::assertStringContainsString('refusing to run as root', $err);
        self::assertStringContainsString('root (euid=0)', $out.$err);
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testAllowRootOverridesTheRefusal(): void
    {
        [$code, $out, $err] = $this->install([...$this->required(), '--allow-root'], [], 0, 'root');

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Installing as user: root (euid=0)', $out);
    }

    public function testWarnsAboutDangerousDefault(): void
    {
        [, $out, $err] = $this->install($this->required());

        self::assertStringContainsString('--dangerously-skip-permissions', $err);
        self::assertStringContainsString('Warnings:', $out);
        self::assertStringContainsString('--dangerously-skip-permissions', $out);
    }

    public function testWarnsAboutExplicitDangerousValue(): void
    {
        [, $out, $err] = $this->install([...$this->required(), '--claude-extra-args=--model,opus,--dangerously-skip-permissions']);

        self::assertStringContainsString('--dangerously-skip-permissions', $err);
        self::assertStringContainsString('Warnings:', $out);
    }

    public function testNoWarningForStricterValue(): void
    {
        [$code, $out, $err] = $this->install([...$this->required(), '--claude-extra-args=--permission-mode,acceptEdits']);

        self::assertSame(0, $code);
        self::assertStringNotContainsString('dangerously', $out.$err);
        self::assertSame('--permission-mode,acceptEdits', Env::load($this->root.'/.env', true)->get('CLAUDE_EXTRA_ARGS'));
    }

    public function testGitignoreCoversBackupAndTemporaryFiles(): void
    {
        $lines = file(dirname(__DIR__).'/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];

        self::assertContains('.env.bak', $lines);
        self::assertContains('.env.tmp*', $lines);
    }

    public function testMaskHidesSecrets(): void
    {
        self::assertSame('***WxYz', Installer::mask(self::TOKEN));
        self::assertSame('***', Installer::mask('short'));
    }

    private function tokenOther(): string
    {
        return '999999:ZZZZZZZZZZZZZZZZZZZZ';
    }

    public function testPromptsOnlyWithTtyAndWithoutNonInteractive(): void
    {
        [$code, $out] = $this->install($this->required(), [], 1000, 'tester', $this->services(tty: true, input: str_repeat("\n", 10)));
        self::assertSame(0, $code);
        self::assertStringContainsString('ALLOWED_USER_IDS [1001,1002]: ', $out);

        $this->removeEnv();
        [$code, $out] = $this->install([...$this->required(), '--non-interactive'], [], 1000, 'tester', $this->services(tty: true));
        self::assertSame(0, $code);
        self::assertStringNotContainsString('ALLOWED_USER_IDS [', $out);
        self::assertStringNotContainsString('ALLOWED_USER_IDS: ', $out);

        $this->removeEnv();
        [$code, $out] = $this->install($this->required(), [], 1000, 'tester', $this->services(tty: false));
        self::assertSame(0, $code);
        self::assertStringNotContainsString('ALLOWED_USER_IDS: ', $out);
    }

    public function testInteractiveDefaultsComeFromExistingEnvAndAreMasked(): void
    {
        $this->writeEnv('BOT_TOKEN='.self::TOKEN."\nALLOWED_USER_IDS=77\nREPO_PATH={$this->repo}\nBOT_LOCALE=ru\nSTATE_DIR={$this->state}\n");

        [$code, $out] = $this->install([], [], 1000, 'tester', $this->services(tty: true, input: str_repeat("\n", 10)));

        self::assertSame(0, $code);
        self::assertStringContainsString('BOT_TOKEN [***WxYz]: ', $out);
        self::assertStringContainsString('ALLOWED_USER_IDS [77]: ', $out);
        self::assertStringContainsString('BOT_LOCALE [ru]: ', $out);
        self::assertStringNotContainsString('STATE_DIR', $out);
        self::assertSame('77', Env::load($this->root.'/.env', true)->get('ALLOWED_USER_IDS'));
    }

    public function testInteractiveRepeatsQuestionOnInvalidAnswer(): void
    {
        $input = self::TOKEN."\nabc\n1001\n{$this->repo}\n\n\n\nxx\nde\nNot/AZone\nEurope/Berlin\n\n\n";
        [$code, $out, $err] = $this->install([], [], 1000, 'tester', $this->services(tty: true, input: $input));

        self::assertSame(0, $code, $err);
        self::assertSame(2, substr_count($out, 'ALLOWED_USER_IDS: '), $out);
        self::assertStringContainsString('ALLOWED_USER_IDS: must contain only digits', $err);
        self::assertStringContainsString('BOT_LOCALE=xx is not in the locale catalog', $err);
        self::assertStringContainsString('Invalid TIMEZONE=Not/AZone', $err);
        $env = Env::load($this->root.'/.env', true);
        self::assertSame('1001', $env->get('ALLOWED_USER_IDS'));
        self::assertSame('de', $env->get('BOT_LOCALE'));
        self::assertSame('Europe/Berlin', $env->get('TIMEZONE'));
    }

    public function testExtraArgsHasItsOwnQuestionWithWarning(): void
    {
        $input = self::TOKEN."\n1001\n{$this->repo}\n\n\n\n\n\n\n--permission-mode,acceptEdits\n";
        [$code, $out] = $this->install([], [], 1000, 'tester', $this->services(tty: true, input: $input));

        self::assertSame(0, $code);
        self::assertStringContainsString('WARNING: with --dangerously-skip-permissions Claude runs WITHOUT permission prompts', $out);
        self::assertStringContainsString('CLAUDE_EXTRA_ARGS [--dangerously-skip-permissions]: ', $out);
        self::assertSame('--permission-mode,acceptEdits', Env::load($this->root.'/.env', true)->get('CLAUDE_EXTRA_ARGS'));
    }

    public function testTokenIsReadWithoutEchoAndEchoIsRestored(): void
    {
        $calls = [];
        $run = static function (array $command) use (&$calls): array {
            $calls[] = $command;

            return [0, '', ''];
        };
        $input = self::TOKEN."\n1001\n{$this->repo}\n\n\n\n\n\n\n\n";
        [$code, $out] = $this->install([], [], 1000, 'tester', $this->services(run: $run, tty: true, input: $input));

        self::assertSame(0, $code);
        self::assertSame([['stty', '-echo'], ['stty', 'echo']], array_values(array_filter($calls, static fn (array $c): bool => $c[0] === 'stty')));
        self::assertStringNotContainsString(self::TOKEN, $out);
    }

    public function testEchoIsRestoredAfterInterruption(): void
    {
        $calls = [];
        $run = static function (array $command) use (&$calls): array {
            $calls[] = $command;

            return [0, '', ''];
        };
        // The input ends while the token is being read: the same exit path as Ctrl+C.
        [$code] = $this->install([], [], 1000, 'tester', $this->services(run: $run, tty: true, input: ''));

        self::assertSame(1, $code);
        self::assertSame(['stty', 'echo'], end($calls));
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testEchoIsRestoredAfterSigint(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required.');
        }
        $calls = [];
        $run = static function (array $command) use (&$calls): array {
            $calls[] = $command;
            if ($command === ['stty', '-echo']) {
                posix_kill(posix_getpid(), SIGINT);
            }

            return [0, '', ''];
        };
        [$code] = $this->install([], [], 1000, 'tester', $this->services(run: $run, tty: true, input: "secret\n"));

        self::assertSame(1, $code);
        self::assertSame(['stty', 'echo'], end($calls));
        self::assertFileDoesNotExist($this->root.'/.env');
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGINT));
    }

    public function testMissingCurlIsReportedWithoutCallingHttp(): void
    {
        $http = static fn (): never => throw new \Error('Call to undefined function curl_init()');
        $ext = static fn (string $e): bool => $e !== 'curl';
        [$code, , $err] = $this->install([...$this->required(), '--skip-network-checks'], [], 1000, 'tester', $this->services(http: $http, ext: $ext));

        self::assertSame(1, $code);
        self::assertStringContainsString('extension curl is not loaded', $err);
        self::assertStringNotContainsString('curl_init', $err);
    }

    public function testCronLogFollowsStateDir(): void
    {
        $stateDir = $this->root.'/custom-state';
        mkdir($stateDir);
        $this->writeEnv("STATE_DIR={$stateDir}\n");
        [$code, $out] = $this->install($this->required());

        self::assertSame(0, $code);
        self::assertStringContainsString("ExecStart=/usr/bin/php {$this->root}/bin/bot.php\n", $out);
        self::assertStringContainsString(">> {$stateDir}/check_gh_auth.log 2>&1", $out);
        self::assertStringNotContainsString("{$this->state}/check_gh_auth.log", $out);
    }

    public function testCronLogResolvesRelativeStateDirAgainstInstallDir(): void
    {
        $this->writeEnv("STATE_DIR=relative-state\n");
        [$code, $out] = $this->install($this->required());

        self::assertSame(0, $code);
        self::assertStringContainsString(">> {$this->root}/relative-state/check_gh_auth.log 2>&1", $out);
        self::assertDirectoryExists($this->root.'/relative-state/outbox');
        self::assertDirectoryDoesNotExist(dirname(__DIR__).'/relative-state');
    }

    public function testUnitAndCronEscapeSpacesAndPercent(): void
    {
        $root = $this->root.'/my dir%x';
        mkdir($root);
        copy($this->root.'/.env.dist', $root.'/.env.dist');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Installer($root, [], 1000, 'tester', $this->services(phpBinary: '/opt/my php/bin/php')))->run($this->required(), $out, $err);
        rewind($out);
        $text = (string) stream_get_contents($out);

        self::assertSame(0, $code);
        self::assertStringContainsString("WorkingDirectory={$this->root}/my dir%%x\n", $text);
        self::assertStringContainsString("ExecStart=\"/opt/my php/bin/php\" \"{$this->root}/my dir%%x/bin/bot.php\"\n", $text);
        self::assertStringContainsString("0 9 * * 1 '{$this->root}/my dir\\%x/bin/check_gh_auth.sh' >> ", $text);
    }

    public function testInteractiveModeRequiresPcntl(): void
    {
        $calls = [];
        $run = static function (array $command) use (&$calls): array {
            $calls[] = $command;

            return [0, '', ''];
        };
        $ext = static fn (string $e): bool => $e !== 'pcntl';
        [$code, , $err] = $this->install([], [], 1000, 'tester', $this->services(run: $run, ext: $ext, tty: true, input: "secret\n"));

        self::assertSame(1, $code);
        self::assertStringContainsString('pcntl', $err);
        self::assertNotContains('stty', array_column($calls, 0));
        self::assertFileDoesNotExist($this->root.'/.env');
    }

    public function testUnitHasNoUserLineWhenUserIsUnknown(): void
    {
        [$code, $out, $err] = $this->install($this->required(), [], 1000, null);

        self::assertSame(0, $code);
        self::assertStringNotContainsString('User=', $out);
        self::assertStringContainsString('user name is unknown', $err);
    }

    public function testEchoIsNotTouchedWithoutTty(): void
    {
        $calls = [];
        $run = static function (array $command) use (&$calls): array {
            $calls[] = $command[0];

            return [0, '', ''];
        };
        $this->install($this->required(), [], 1000, 'tester', $this->services(run: $run));

        self::assertNotContains('stty', $calls);
    }

    /**
     * @return iterable<string, array{array<string, \Closure>, string, bool|null}>
     */
    public static function failingCheckProvider(): iterable
    {
        $fail = static fn (string $binary): \Closure => static fn (array $c): array => $c[0] === $binary || ($c[0] === 'git' && $binary === 'ls-remote' && in_array('ls-remote', $c, true)) || ($c[0] === 'gh' && $binary === 'gh-auth' && in_array('auth', $c, true))
            ? [1, '', 'boom'] : [0, '', ''];

        yield 'old php' => [['php' => static fn (): string => '8.0.0'], 'PHP 8.4 or newer is required', false];
        yield 'extension curl' => [['ext' => static fn (string $e): bool => $e !== 'curl'], 'extension curl is not loaded', false];
        yield 'extension pdo_sqlite' => [['ext' => static fn (string $e): bool => $e !== 'pdo_sqlite'], 'extension pdo_sqlite is not loaded', false];
        yield 'extension pcntl' => [['ext' => static fn (string $e): bool => $e !== 'pcntl'], 'extension pcntl is not loaded', false];
        yield 'extension posix' => [['ext' => static fn (string $e): bool => $e !== 'posix'], 'extension posix is not loaded', false];
        yield 'git' => [['run' => $fail('git')], 'git was not found', false];
        yield 'gh' => [['run' => $fail('gh')], 'gh was not found', false];
        yield 'claude bin' => [['run' => $fail('claude')], 'CLAUDE_BIN=claude was not found', false];
        yield 'getMe http' => [['http' => static fn (): array => [401, '{"ok":false}']], 'rejected BOT_TOKEN', true];
        yield 'getMe transport' => [['http' => static fn (): ?array => null], 'Bot API is unreachable', true];
        yield 'ls-remote' => [['run' => $fail('ls-remote')], 'git cannot reach the remote', true];
        yield 'ls-remote timeout' => [['run' => static fn (array $c): array => in_array('ls-remote', $c, true) ? [InstallerServices::EXIT_TIMEOUT, '', ''] : [0, '', '']], 'timed out', true];
        yield 'gh auth' => [['run' => $fail('gh-auth')], '`gh auth status` failed', null];
    }

    /**
     * @param array<string, \Closure> $overrides
     * @param bool|null               $network   true: network check (warning with --skip-network-checks); false: always an error; null: always a warning
     */
    #[DataProvider('failingCheckProvider')]
    public function testCheckFailureLevel(array $overrides, string $message, ?bool $network): void
    {
        $services = fn (): InstallerServices => $this->services($overrides['run'] ?? null, $overrides['http'] ?? null, $overrides['ext'] ?? null, php: isset($overrides['php']) ? $overrides['php']() : '8.5.0');

        [$code, , $err] = $this->install($this->required(), [], 1000, 'tester', $services());
        if ($network === null) {
            self::assertSame(0, $code, $err);
            self::assertStringContainsString($message, $err);
        } else {
            self::assertSame(1, $code);
            self::assertStringContainsString($message, $err);
            self::assertFileDoesNotExist($this->root.'/.env');
        }

        if ($network !== false) {
            [$code, $out, $err] = $this->install([...$this->required(), '--skip-network-checks'], [], 1000, 'tester', $services());
            self::assertSame(0, $code, $err);
            self::assertStringContainsString($message, $err);
            self::assertStringContainsString($message, $out);
        } else {
            [$code] = $this->install([...$this->required(), '--skip-network-checks'], [], 1000, 'tester', $services());
            self::assertSame(1, $code);
        }
    }

    public function testLocaleAndTimezoneAndRepoFailuresAreErrors(): void
    {
        foreach ([['--bot-locale=xx', 'not in the locale catalog'], ['--timezone=Nope/Zone', 'Invalid TIMEZONE'], ['--repo-path='.$this->root, 'does not look like a git repository']] as [$option, $message]) {
            $args = [...array_filter($this->required(), static fn (string $a): bool => !str_starts_with($a, '--repo-path') || !str_starts_with($option, '--repo-path')), $option];
            [$code, , $err] = $this->install($args);
            self::assertSame(1, $code);
            self::assertStringContainsString($message, $err);
        }
    }

    public function testCustomClaudeBinIsProbed(): void
    {
        $probed = [];
        $run = static function (array $c) use (&$probed): array {
            $probed[] = $c[0];

            return [0, '', ''];
        };
        $this->install([...$this->required(), '--claude-bin=/opt/tools/claude-x'], [], 1000, 'tester', $this->services(run: $run));

        self::assertContains('/opt/tools/claude-x', $probed);
        self::assertNotContains('claude', $probed);
    }

    public function testExternalCallsHaveTimeoutsAndGitIsNonInteractive(): void
    {
        $calls = [];
        $httpTimeouts = [];
        $run = static function (array $c, array $env, int $timeout) use (&$calls): array {
            $calls[] = [$c, $env, $timeout];

            return [0, '', ''];
        };
        $http = static function (string $url, int $timeout) use (&$httpTimeouts): array {
            $httpTimeouts[] = $timeout;

            return [200, '{"ok":true}'];
        };
        $this->install($this->required(), ['PATH' => '/usr/bin'], 1000, 'tester', $this->services(run: $run, http: $http));

        self::assertNotSame([], $httpTimeouts);
        self::assertGreaterThan(0, $httpTimeouts[0]);
        foreach ($calls as [, , $timeout]) {
            self::assertGreaterThan(0, $timeout);
        }
        $lsCalls = array_values(array_filter($calls, static fn (array $c): bool => in_array('ls-remote', $c[0], true)));
        self::assertCount(1, $lsCalls);
        $ls = $lsCalls[0];
        self::assertSame(['git', '-C', $this->repo, 'ls-remote'], $ls[0]);
        self::assertSame('0', $ls[1]['GIT_TERMINAL_PROMPT']);
        self::assertStringContainsString('BatchMode=yes', $ls[1]['GIT_SSH_COMMAND']);
        self::assertStringContainsString('StrictHostKeyChecking=yes', $ls[1]['GIT_SSH_COMMAND']);
        self::assertStringNotContainsString('accept-new', $ls[1]['GIT_SSH_COMMAND']);
        self::assertSame('/usr/bin', $ls[1]['PATH']);
    }

    public function testUnknownHostKeyGivesSshKeyscanHint(): void
    {
        $run = static fn (array $c): array => in_array('ls-remote', $c, true) ? [128, '', 'Host key verification failed.'] : [0, '', ''];

        [$code, , $err] = $this->install($this->required(), [], 1000, 'tester', $this->services(run: $run));

        self::assertSame(1, $code);
        self::assertStringContainsString('ssh-keyscan', $err);
    }

    public function testPrintsUnitAndCronWithoutWritingFile(): void
    {
        [$code, $out] = $this->install($this->required());

        self::assertSame(0, $code);
        self::assertStringContainsString("WorkingDirectory={$this->root}\n", $out);
        self::assertStringContainsString("ExecStart=/usr/bin/php {$this->root}/bin/bot.php\n", $out);
        self::assertStringContainsString("User=tester\n", $out);
        self::assertStringContainsString("0 9 * * 1 {$this->root}/bin/check_gh_auth.sh", $out);
        self::assertSame([], glob($this->root.'/*.service'));
    }

    public function testUnitFileIsWrittenOnlyToTheGivenPath(): void
    {
        $path = $this->root.'/custom.service';
        [$code] = $this->install([...$this->required(), '--unit-file='.$path]);

        self::assertSame(0, $code);
        self::assertStringContainsString("User=tester\n", (string) file_get_contents($path));
        self::assertStringContainsString("[Install]\nWantedBy=multi-user.target\n", (string) file_get_contents($path));

        [$code, , $err] = $this->install([...$this->required(), '--unit-file='.$this->root.'/missing/x.service']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--unit-file', $err);
    }

    public function testUnitFileIsNotWrittenWhenChecksFail(): void
    {
        $path = $this->root.'/custom.service';
        $this->install([...$this->required(), '--unit-file='.$path], [], 1000, 'tester', $this->services(php: '7.0.0'));

        self::assertFileDoesNotExist($path);
    }

    private function removeEnv(): void
    {
        @unlink($this->root.'/.env');
        @unlink($this->root.'/.env.bak');
    }

    /**
     * Offline services where every check passes; no TTY.
     */
    private function services(?\Closure $run = null, ?\Closure $http = null, ?\Closure $ext = null, bool $tty = false, string $input = '', string $php = '8.5.0', string $phpBinary = '/usr/bin/php'): InstallerServices
    {
        $in = fopen('php://memory', 'w+');
        self::assertIsResource($in);
        fwrite($in, $input);
        rewind($in);

        return new InstallerServices(
            $run ?? static fn (): array => [0, '', ''],
            $http ?? static fn (): array => [200, '{"ok":true}'],
            $ext ?? static fn (): bool => true,
            static fn (): bool => $tty,
            $in,
            $php,
            $phpBinary,
        );
    }

    /**
     * @return list<string>
     */
    private function required(): array
    {
        return ['--bot-token='.self::TOKEN, '--allowed-user-ids=1001,1002', '--repo-path='.$this->repo];
    }

    private function writeEnv(string $content): void
    {
        file_put_contents($this->root.'/.env', $content);
    }

    private function mode(string $path): int
    {
        clearstatcache();

        return (int) (fileperms($path) & 0777);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $environment
     *
     * @return array{int, string, string}
     */
    private function install(array $args, array $environment = [], int $euid = 1000, ?string $user = 'tester', ?InstallerServices $services = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Installer($this->root, $environment, $euid, $user, $services ?? $this->services()))->run($args, $out, $err);

        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::removeTree($path.'/'.$item);
            }
        }
        rmdir($path);
    }
}
