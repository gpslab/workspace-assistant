<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `bin/ctl.sh status` against a copy of the script in a temporary installation directory: where the
 * PID file is looked for (STATE_DIR from `.env`, relative paths, the legacy `./state`) and whether
 * a live PID is recognised as the bot.
 */
final class CtlScriptTest extends TestCase
{
    private string $root;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ctl_'.uniqid('', true);
        mkdir($this->root.'/bin', 0777, true);
        copy(dirname(__DIR__).'/bin/ctl.sh', $this->root.'/bin/ctl.sh');
        copy(dirname(__DIR__).'/bootstrap.php', $this->root.'/bootstrap.php');
        mkdir($this->root.'/src');
        copy(dirname(__DIR__).'/src/Env.php', $this->root.'/src/Env.php');
        copy(dirname(__DIR__).'/src/Config.php', $this->root.'/src/Config.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testStateDirFromEnvFileHoldsThePidFile(): void
    {
        $stateDir = $this->root.'/var/state';
        file_put_contents($this->root.'/.env', "STATE_DIR={$stateDir}\n");
        mkdir($stateDir, 0777, true);
        file_put_contents($stateDir.'/bot.pid', (string) $this->spawn(true));

        [$code, $out] = $this->ctl('status');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Running', $out);
        self::assertDirectoryDoesNotExist($this->root.'/state');
    }

    public function testQuotedStateDirAndLastDuplicateFollowTheBotParser(): void
    {
        $stateDir = $this->root.'/quoted state';
        file_put_contents($this->root.'/.env', "STATE_DIR=/nowhere\nSTATE_DIR=\"{$stateDir}\"  \n");
        mkdir($stateDir);
        file_put_contents($stateDir.'/bot.pid', (string) $this->spawn(true));

        [$code, $out] = $this->ctl('status');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Running', $out);
    }

    public function testRealEnvironmentOverridesTheEnvFile(): void
    {
        file_put_contents($this->root.'/.env', "STATE_DIR=from-file\n");
        mkdir($this->root.'/from-env');
        file_put_contents($this->root.'/from-env/bot.pid', (string) $this->spawn(true));

        [$code, $out] = $this->ctl('status', null, ['STATE_DIR' => 'from-env']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Running', $out);
    }

    public function testUnsetStateDirKeepsStateNextToTheCode(): void
    {
        mkdir($this->root.'/state');
        file_put_contents($this->root.'/state/bot.pid', (string) $this->spawn(true));

        [$code, $out] = $this->ctl('status');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Running', $out);
    }

    public function testRelativeStateDirIsTakenFromTheInstallationDirectory(): void
    {
        file_put_contents($this->root.'/.env', "STATE_DIR=data\n");
        mkdir($this->root.'/data');
        file_put_contents($this->root.'/data/bot.pid', (string) $this->spawn(true));

        [$code, $out] = $this->ctl('status', sys_get_temp_dir());

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Running', $out);
    }

    public function testLivePidOfAnotherProcessIsNotTheBot(): void
    {
        mkdir($this->root.'/state');
        file_put_contents($this->root.'/state/bot.pid', (string) $this->spawn(false));

        [$code, $out] = $this->ctl('status');

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('Not running', $out);
        self::assertFileDoesNotExist($this->root.'/state/bot.pid');
    }

    public function testLegacyPidFileIsUsedWhileTheOldBotRuns(): void
    {
        $stateDir = $this->root.'/var/state';
        file_put_contents($this->root.'/.env', "STATE_DIR={$stateDir}\n");
        mkdir($this->root.'/state');
        $pid = $this->spawn(true);
        file_put_contents($this->root.'/state/bot.pid', (string) $pid);

        [$code, $out, $err] = $this->ctl('status');

        self::assertSame(0, $code, $out.$err);
        self::assertStringContainsString("Running (PID {$pid})", $out);
        self::assertStringContainsString($this->root.'/state/bot.pid', $err);
    }

    public function testOtherEnvKeysAreNotExportedToTheBot(): void
    {
        file_put_contents($this->root.'/.env', "STATE_DIR=data\nBOT_TOKEN=from-file\n");
        file_put_contents($this->root.'/bin/bot.php', '<?php echo getenv("BOT_TOKEN") === false ? "unset" : "set";');

        [$code, $out] = $this->ctl('fg');

        self::assertSame(0, $code, $out);
        self::assertSame('unset', trim($out));
    }

    /**
     * A sleeping child process; with `$asBot` its command line ends in `bin/bot.php`.
     */
    private function spawn(bool $asBot): int
    {
        $cmd = [\PHP_BINARY, '-r', 'sleep(30);', '--', $asBot ? $this->root.'/bin/bot.php' : 'other.php'];
        $process = proc_open($cmd, [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        // Wait until the child has exec'd php, so /proc/<pid>/cmdline is final.
        for ($i = 0; $i < 50 && !str_contains((string) @file_get_contents("/proc/{$pid}/cmdline"), 'sleep(30)'); ++$i) {
            usleep(20_000);
        }

        return $pid;
    }

    /**
     * @param array<string, string> $extraEnv
     *
     * @return array{int, string, string}
     */
    private function ctl(string $command, ?string $cwd = null, array $extraEnv = []): array
    {
        $env = ['PATH' => (string) getenv('PATH'), 'PHP_BIN' => \PHP_BINARY, ...$extraEnv];
        $process = proc_open(['bash', $this->root.'/bin/ctl.sh', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $this->root, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
