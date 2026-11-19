<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\OpencodeRunner;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The OpenCode runner against a fake `opencode` executable that replays the committed NDJSON
 * fixtures. No real OpenCode is needed.
 */
#[CoversClass(OpencodeRunner::class)]
final class OpencodeRunnerTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/opencode/run';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/opencode-runner-'.uniqid('', true);
        mkdir($this->root.'/state', 0775, true);
        mkdir($this->root.'/repo', 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testArgvHasFixedFlagsSessionModelPromptAndExtraArgs(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson');
        $runner = $this->runner($bin, opencodeModel: 'openrouter/model-x', extraArgs: ['--foo', 'bar']);

        $runner->start('hello', 'sess-1');
        $this->waitDone($runner);

        self::assertSame(
            ['run', '--format', 'json', '--standalone', '--auto', '--session', 'sess-1', '--model', 'openrouter/model-x', 'hello', '--foo', 'bar'],
            $this->recordedArgv(),
        );
    }

    public function testArgvOmitsSessionAndModelWhenTheyAreNotSet(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson');
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        $argv = $this->recordedArgv();
        self::assertSame(['run', '--format', 'json', '--standalone', '--auto', 'hello'], $argv);
        self::assertNotContains('--session', $argv);
        self::assertNotContains('--model', $argv);
    }

    public function testRunEnvironmentPointsAtThePerRunConfigAndNeverMovesXdgConfigHome(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson');
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        $env = $this->recordedEnv();
        self::assertSame('1', $env['OPENCODE_DISABLE_PROJECT_CONFIG']);
        $dir = $env['OPENCODE_CONFIG_DIR'];
        self::assertIsString($dir);
        self::assertStringStartsWith($this->root.'/state/opencode-', $dir);
        self::assertSame($dir.'/explicit.json', $env['OPENCODE_CONFIG']);
        self::assertSame(getenv('XDG_CONFIG_HOME') === false ? null : getenv('XDG_CONFIG_HOME'), $env['XDG_CONFIG_HOME']);
    }

    public function testThePerRunDirectoryIsPrivateAndCarriesTheRenderedArtefacts(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin, withAgents: true);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());
        $runner->poll();

        $dirs = glob($this->root.'/state/opencode-*') ?: [];
        self::assertCount(1, $dirs);
        $dir = $dirs[0];
        self::assertSame(0700, fileperms($dir) & 0777);
        self::assertFileExists($dir.'/opencode.json');
        self::assertFileExists($dir.'/explicit.json');
        self::assertFileExists($dir.'/system-prompt.md');
        self::assertFileExists($dir.'/agents/critic.md');

        $opencodeJson = json_decode((string) file_get_contents($dir.'/opencode.json'), true);
        self::assertIsArray($opencodeJson);
        self::assertSame([$dir.'/system-prompt.md'], $opencodeJson['instructions']);

        $explicitJson = json_decode((string) file_get_contents($dir.'/explicit.json'), true);
        self::assertIsArray($explicitJson);
        self::assertArrayHasKey('critic', $explicitJson['agents']);

        $runner->kill();
        self::assertSame([], glob($this->root.'/state/opencode-*') ?: []);
    }

    public function testThePerRunDirectoryIsRemovedAfterANormalFinish(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson');
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        self::assertSame([], glob($this->root.'/state/opencode-*') ?: []);
        self::assertSame([], glob($this->root.'/state/runs/*') ?: []);
    }

    public function testThePerRunDirectoryIsRemovedWhenTheRunIsKilled(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());
        self::assertNotSame([], glob($this->root.'/state/opencode-*') ?: []);

        $runner->kill();

        self::assertSame([], glob($this->root.'/state/opencode-*') ?: []);
    }

    public function testPromptHashAndWarningsMatchTheClaudeRunner(): void
    {
        $claude = new ClaudeRunner('/bin/true', $this->root.'/repo', 0, []);
        $opencode = $this->runner('/bin/true', withAgents: true);

        try {
            $claude->start('hello', null);
            $opencode->start('hello', null);

            self::assertSame($claude->promptHash(), $opencode->promptHash());
            self::assertSame($claude->promptWarnings(), $opencode->promptWarnings());
        } finally {
            $claude->kill();
            $opencode->kill();
        }
    }

    public function testSilenceBeforeAnySubagentEndsTheRun(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin, stall: 1);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());
        self::assertSame(RunnerState::Running, $runner->poll());

        $this->backdate($runner, 'lastProgressAt');
        self::assertSame(RunnerState::Timeout, $runner->poll());
        self::assertSame('no output for 1s (hung)', $runner->timeoutReason());
    }

    public function testAStallIsSuspendedForTheRestOfTheRunAfterASubagentIsLaunched(): void
    {
        $bin = $this->fake(self::FIXTURES.'/background-subagent.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin, stall: 1);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());
        self::assertSame(RunnerState::Running, $runner->poll());

        $this->backdate($runner, 'lastProgressAt');
        self::assertSame(RunnerState::Running, $runner->poll());
    }

    public function testAnyEventByteResetsTheStallTimer(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin, stall: 1);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());

        // The bytes are written but not read yet: the poll that reads them is a heartbeat.
        $this->backdate($runner, 'lastProgressAt');
        self::assertSame(RunnerState::Running, $runner->poll());
    }

    public function testTheOverallTimeoutStillEndsARunWhoseStallTimerIsSuspended(): void
    {
        $bin = $this->fake(self::FIXTURES.'/background-subagent.ndjson', sleepAfterUs: 3_000_000);
        $runner = $this->runner($bin, timeout: 1, stall: 1);

        $runner->start('hello', null);
        $this->waitForFile($this->doneFile());
        self::assertSame(RunnerState::Running, $runner->poll());

        $this->backdate($runner, 'startedAt');
        self::assertSame(RunnerState::Timeout, $runner->poll());
        self::assertSame('overall timeout 1s', $runner->timeoutReason());
    }

    public function testAResumedRunPassesTheSessionAndGetsNoInstructionsUpdateBlock(): void
    {
        $bin = $this->fake(self::FIXTURES.'/resume-2-instructions-beta.ndjson');
        $runner = $this->runner($bin);

        $runner->start('hello again', 'ses-1');
        $this->waitDone($runner);

        $argv = $this->recordedArgv();
        $sessionAt = array_search('--session', $argv, true);
        self::assertIsInt($sessionAt);
        self::assertSame('ses-1', $argv[$sessionAt + 1]);
        self::assertContains('hello again', $argv);
        foreach ($argv as $arg) {
            self::assertStringNotContainsString('The instructions of this session have been updated', $arg);
        }
        self::assertSame('ses_e264bc708ffeDWPyOUmHuhr020', $runner->sessionId());
        self::assertStringStartsWith('BETA', $runner->result()->text);
    }

    public function testAPlainRunYieldsTheFinalText(): void
    {
        $bin = $this->fake(self::FIXTURES.'/plain.ndjson');
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        $result = $runner->result();
        self::assertFalse($result->isError);
        self::assertSame('There is **1** file in the current directory: `README.md`.', $result->text);
        self::assertSame('ses_e264d4bf7ffe7WjNcc5PBGKfPF', $result->sessionId);
        self::assertNull($result->limitClass);
    }

    public function testAProviderQuotaErrorIsCarriedAsAQuotaLimit(): void
    {
        $bin = $this->fake(self::FIXTURES.'/error-provider-quota.ndjson', exitCode: 1);
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        $result = $runner->result();
        self::assertTrue($result->isError);
        self::assertStringContainsString('requires more credits', $result->text);
        self::assertSame('quota', $result->limitClass);
    }

    public function testAProviderRateLimitIsCarriedAsARateLimit(): void
    {
        $fixture = $this->root.'/rate.ndjson';
        file_put_contents($fixture, '{"type":"error","sessionID":"s","error":{"type":"provider.unknown","message":"Too Many Requests","status":429}}'."\n");
        $bin = $this->fake($fixture, exitCode: 1);
        $runner = $this->runner($bin);

        $runner->start('hello', null);
        $this->waitDone($runner);

        self::assertSame('rate', $runner->result()->limitClass);
    }

    public function testPurgeRemovesDeadRunDirectoriesAndKeepsLiveOnes(): void
    {
        $dead = $this->root.'/state/opencode-20260101-120000-999999-abcd';
        $live = $this->root.'/state/opencode-20260101-120000-'.getmypid().'-abcd';
        mkdir($dead, 0700, true);
        file_put_contents($dead.'/explicit.json', '{}');
        mkdir($live, 0700, true);
        file_put_contents($live.'/explicit.json', '{}');

        OpencodeRunner::purgeRunDirs($this->root.'/state');

        self::assertDirectoryDoesNotExist($dead);
        self::assertDirectoryExists($live);
    }

    /** @param list<string> $extraArgs */
    private function runner(
        string $bin,
        string $opencodeModel = '',
        int $timeout = 0,
        int $stall = 0,
        bool $withAgents = false,
        array $extraArgs = [],
    ): OpencodeRunner {
        return new OpencodeRunner(
            opencodeBin: $bin,
            repoPath: $this->root.'/repo',
            timeout: $timeout,
            extraArgs: $extraArgs,
            stallTimeout: $stall,
            mode: SystemPrompt::MODE_ARCH,
            locale: new Locale(),
            stateDir: $this->root.'/state',
            withAgents: $withAgents,
            homeDir: '',
            opencodeModel: $opencodeModel,
        );
    }

    /**
     * A fake `opencode` that records its argv and the run environment, replays $fixture and then
     * optionally stays alive. The done-file is touched after the last line has been written.
     */
    private function fake(
        string $fixture,
        int $exitCode = 0,
        int $sleepAfterUs = 0,
    ): string {
        $bin = $this->root.'/opencode';
        $script = '#!'.PHP_BINARY."\n<?php\n"
            .'file_put_contents('.var_export($this->argvFile(), true).', json_encode(array_slice($argv, 1)));'."\n"
            .'$keys = ["OPENCODE_DISABLE_PROJECT_CONFIG", "OPENCODE_CONFIG_DIR", "OPENCODE_CONFIG", "XDG_CONFIG_HOME"];'."\n"
            .'$env = [];'."\n"
            .'foreach ($keys as $key) { $value = getenv($key); $env[$key] = $value === false ? null : $value; }'."\n"
            .'file_put_contents('.var_export($this->envFile(), true).', json_encode($env));'."\n"
            .'$lines = file('.var_export($fixture, true).', FILE_IGNORE_NEW_LINES);'."\n"
            .'foreach ($lines as $line) { fwrite(STDOUT, $line."\n"); fflush(STDOUT); }'."\n"
            .'touch('.var_export($this->doneFile(), true).');'."\n"
            .($sleepAfterUs > 0 ? 'usleep('.$sleepAfterUs.');'."\n" : '')
            .'exit('.$exitCode.');'."\n";
        file_put_contents($bin, $script);
        chmod($bin, 0o755);

        return $bin;
    }

    private function waitDone(OpencodeRunner $runner): void
    {
        for ($i = 0; $i < 500; ++$i) {
            if ($runner->poll() !== RunnerState::Running) {
                return;
            }
            usleep(20000);
        }
        self::fail('the run did not finish');
    }

    private function waitForFile(string $path): void
    {
        for ($i = 0; $i < 500 && !is_file($path); ++$i) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private function backdate(OpencodeRunner $runner, string $property): void
    {
        (new \ReflectionProperty(OpencodeRunner::class, $property))->setValue($runner, time() - 100);
    }

    /** @return list<string> */
    private function recordedArgv(): array
    {
        /** @var list<string> $argv */
        $argv = json_decode((string) file_get_contents($this->argvFile()), true);

        return $argv;
    }

    /** @return array<string, string|null> */
    private function recordedEnv(): array
    {
        /** @var array<string, string|null> $env */
        $env = json_decode((string) file_get_contents($this->envFile()), true);

        return $env;
    }

    private function argvFile(): string
    {
        return $this->root.'/argv.json';
    }

    private function envFile(): string
    {
        return $this->root.'/env.json';
    }

    private function doneFile(): string
    {
        return $this->root.'/done';
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
