<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\OpencodeRunner;
use GpsLab\WorkspaceAssistant\RunnerFactory;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunnerFactory::class)]
final class RunnerFactoryTest extends TestCase
{
    /** A fake claude that finishes with a valid result: the run ends quickly, the argv is what we assert on. */
    private const string RESULT_BODY = 'echo \'{"type":"result","is_error":false,"result":"ok","session_id":"s"}\'."\n";';

    /** A fake claude that stays silent for 10s: only the config timeouts can end the run. */
    private const string SILENT_BODY = 'usleep(10_000_000);';

    /** A fake opencode that finishes with one valid NDJSON event: the run ends quickly, the argv is what we assert on. */
    private const string OPENCODE_RESULT_BODY = 'echo \'{"type":"text","sessionID":"s-oc","part":{"text":"ok"}}\'."\n";';

    public function testResolvesTheClaudeEngineToARunner(): void
    {
        $runner = $this->factory($this->config())->create(
            SystemPrompt::MODE_ARCH,
            '',
            [],
            Locale::DEFAULT,
            true,
            false,
            null,
            null,
        );

        self::assertInstanceOf(ClaudeRunner::class, $runner);
    }

    public function testWiresTheModelAndTheRequestedLocaleIntoTheRunner(): void
    {
        ['argv' => $argv] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], 'ru', false, false, null, 'sonnet'],
            self::RESULT_BODY,
        );

        // The resolved model leaves the factory as --model <m>.
        $modelAt = array_search('--model', $argv, true);
        self::assertIsInt($modelAt);
        self::assertSame('sonnet', $argv[$modelAt + 1]);

        // The requested locale ends up in the system prompt passed by --append-system-prompt,
        // not the factory's own default locale.
        $appendAt = array_search('--append-system-prompt', $argv, true);
        self::assertIsInt($appendAt);
        self::assertStringContainsString('in Russian', $argv[$appendAt + 1]);
    }

    public function testWiresTheModeIntoTheSystemPrompt(): void
    {
        ['runner' => $arch] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, false, null, null],
            self::RESULT_BODY,
        );
        ['runner' => $review] = $this->recordedRun(
            [SystemPrompt::MODE_REVIEW, '', [], Locale::DEFAULT, false, false, null, null],
            self::RESULT_BODY,
        );

        self::assertNotSame($arch->promptHash(), $review->promptHash());
    }

    public function testWiresTheWithAgentsFlagIntoTheArgv(): void
    {
        ['argv' => $with] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, null],
            self::RESULT_BODY,
        );
        ['argv' => $without] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, false, null, null],
            self::RESULT_BODY,
        );

        self::assertContains('--agents', $with);
        self::assertNotContains('--agents', $without);
    }

    public function testWiresUpdateOnResumeIntoThePrompt(): void
    {
        ['argv' => $updated] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, true, 'stale-hash', null],
            self::RESULT_BODY,
            'sess-1',
        );
        ['argv' => $untouched] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, false, 'stale-hash', null],
            self::RESULT_BODY,
            'sess-1',
        );

        // The `-p` argument is the prompt: with updateOnResume and a stale saved hash it carries
        // the rendered instructions-update block instead of the bare prompt.
        $promptAt = array_search('-p', $updated, true);
        self::assertIsInt($promptAt);
        self::assertStringStartsWith('The instructions of this session have been updated', $updated[$promptAt + 1]);
        self::assertStringEndsWith("\n\nhello", $updated[$promptAt + 1]);

        // Without updateOnResume the prompt is passed on unchanged.
        $promptAt = array_search('-p', $untouched, true);
        self::assertIsInt($promptAt);
        self::assertSame('hello', $untouched[$promptAt + 1]);
    }

    public function testWiresTheDistinctTimeoutsIntoTheRunner(): void
    {
        // claudeTimeout=1 vs claudeStallTimeout=2: the overall timeout (1) fires before the stall one.
        ['runner' => $total] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, false, null, null],
            self::SILENT_BODY,
            timeout: 1,
            stall: 2,
        );
        self::assertSame('overall timeout 1s', $total->timeoutReason());

        // Swapped: the stall timeout (1) fires before the overall one (2).
        ['runner' => $stall] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, false, false, null, null],
            self::SILENT_BODY,
            timeout: 2,
            stall: 1,
        );
        self::assertSame('no output for 1s (hung)', $stall->timeoutReason());
    }

    public function testResolvesTheOpencodeEngineToARunner(): void
    {
        $runner = $this->factory($this->config(engine: Config::ENGINE_OPENCODE))->create(
            SystemPrompt::MODE_ARCH,
            '',
            [],
            Locale::DEFAULT,
            true,
            false,
            null,
            null,
        );

        self::assertInstanceOf(OpencodeRunner::class, $runner);
    }

    public function testOpencodePinsTheInstanceModelAndIgnoresTheTopicModel(): void
    {
        // The queue item carries a Claude Code alias ('sonnet'): on OpenCode it is dropped, the
        // run goes to OPENCODE_MODEL.
        ['argv' => $argv] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, 'sonnet'],
            self::OPENCODE_RESULT_BODY,
            engine: Config::ENGINE_OPENCODE,
            opencodeModel: 'anthropic/sonnet',
        );

        self::assertNotContains('sonnet', $argv);
        $modelAt = array_search('--model', $argv, true);
        self::assertIsInt($modelAt);
        self::assertSame('anthropic/sonnet', $argv[$modelAt + 1]);
    }

    public function testOpencodeWithoutAnInstanceModelPassesNoModelFlag(): void
    {
        ['argv' => $argv] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, 'sonnet'],
            self::OPENCODE_RESULT_BODY,
            engine: Config::ENGINE_OPENCODE,
        );

        self::assertNotContains('--model', $argv);
        self::assertNotContains('sonnet', $argv);
    }

    public function testOpencodePassesTheSessionOnlyWhenItIsGivenOne(): void
    {
        ['argv' => $resumed] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, null],
            self::OPENCODE_RESULT_BODY,
            'sess-oc',
            engine: Config::ENGINE_OPENCODE,
        );
        ['argv' => $fresh] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, null],
            self::OPENCODE_RESULT_BODY,
            engine: Config::ENGINE_OPENCODE,
        );

        $sessionAt = array_search('--session', $resumed, true);
        self::assertIsInt($sessionAt);
        self::assertSame('sess-oc', $resumed[$sessionAt + 1]);
        self::assertNotContains('--session', $fresh);
    }

    public function testWiresTheOpencodeBinaryAndTheInstanceArgsIntoTheArgv(): void
    {
        ['argv' => $argv] = $this->recordedRun(
            [SystemPrompt::MODE_ARCH, '', [], Locale::DEFAULT, true, false, null, null],
            self::OPENCODE_RESULT_BODY,
            engine: Config::ENGINE_OPENCODE,
            opencodeExtraArgs: ['--title', 'from .env'],
        );

        // The recorded argv drops the script path, so the extra args are the whole proof here;
        // the binary itself is the script that wrote the file.
        self::assertContains('--title', $argv);
        self::assertContains('from .env', $argv);
    }

    public function testUnsupportedEngineFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported ENGINE=gemini.');

        $this->factory($this->config(engine: 'gemini'))->create(
            SystemPrompt::MODE_ARCH,
            '',
            [],
            Locale::DEFAULT,
            true,
            false,
            null,
            null,
        );
    }

    private function factory(Config $config): RunnerFactory
    {
        return new RunnerFactory($config, new Locale());
    }

    /**
     * A minimal config: only the fields the factory reads, so a signature change breaks the test, not the factory.
     *
     * @param list<string> $opencodeExtraArgs
     */
    private function config(
        string $engine = 'claude',
        string $claudeBin = 'claude',
        string $stateDir = '',
        int $claudeTimeout = 0,
        int $claudeStallTimeout = 0,
        string $opencodeBin = 'opencode',
        string $opencodeModel = '',
        array $opencodeExtraArgs = [],
    ): Config {
        return new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: $claudeBin,
            claudeTimeout: $claudeTimeout,
            claudeStallTimeout: $claudeStallTimeout,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $stateDir,
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 30,
            engine: $engine,
            opencodeBin: $opencodeBin,
            opencodeModel: $opencodeModel,
            opencodeExtraArgs: $opencodeExtraArgs,
        );
    }

    /**
     * Creates a runner through the factory against a fake claude that records its argv
     * (script path excluded) and then runs $body; runs it on 'hello', resumed as $sessionId
     * when given, until the run leaves RunnerState::Running; returns the runner plus the argv.
     *
     * @param array{0: string, 1: string, 2: array<string, string>, 3: string, 4: bool, 5: bool, 6: ?string, 7: ?string} $createArgs
     * @param list<string>                                                                                               $opencodeExtraArgs
     *
     * @return array{runner: AgentRunner, argv: list<string>}
     */
    private function recordedRun(
        array $createArgs,
        string $body,
        ?string $sessionId = null,
        int $timeout = 0,
        int $stall = 0,
        string $engine = 'claude',
        string $opencodeModel = '',
        array $opencodeExtraArgs = [],
    ): array {
        $argvFile = tempnam(sys_get_temp_dir(), 'factory-argv-');
        $bin = tempnam(sys_get_temp_dir(), 'factory-claude-');
        $stateDir = sys_get_temp_dir().'/factory-run-'.uniqid('', true);
        self::assertIsString($argvFile);
        self::assertIsString($bin);
        file_put_contents(
            $bin,
            '#!'.PHP_BINARY."\n<?php\n"
            .'file_put_contents('.(string) var_export($argvFile, true).", json_encode(array_slice(\$argv, 1)));\n"
            .$body."\n",
        );
        chmod($bin, 0o755);

        try {
            $runner = $this->factory($this->config(
                engine: $engine,
                claudeBin: $engine === 'claude' ? $bin : 'claude',
                opencodeBin: $engine === Config::ENGINE_OPENCODE ? $bin : 'opencode',
                opencodeModel: $opencodeModel,
                opencodeExtraArgs: $opencodeExtraArgs,
                stateDir: $stateDir,
                claudeTimeout: $timeout,
                claudeStallTimeout: $stall,
            ))->create(...$createArgs);
            $runner->start('hello', $sessionId);
            while ($runner->poll() === RunnerState::Running) {
                usleep(50000);
            }

            /** @var list<string> $argv */
            $argv = json_decode((string) file_get_contents($argvFile), true);

            return ['runner' => $runner, 'argv' => $argv];
        } finally {
            unlink($bin);
            unlink($argvFile);
            @rmdir($stateDir.'/runs');
            @rmdir($stateDir.'/agents');
            @rmdir($stateDir);
        }
    }
}
