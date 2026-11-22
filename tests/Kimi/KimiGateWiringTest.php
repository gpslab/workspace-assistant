<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Epic\Card;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Inbox;
use GpsLab\WorkspaceAssistant\JobRunner;
use GpsLab\WorkspaceAssistant\KimiRunner;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Memory\MemorySync;
use GpsLab\WorkspaceAssistant\PmGateHook;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;
use GpsLab\WorkspaceAssistant\RunKind;
use GpsLab\WorkspaceAssistant\RunnerFactory;
use GpsLab\WorkspaceAssistant\RunOutcome;
use GpsLab\WorkspaceAssistant\RunState;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use GpsLab\WorkspaceAssistant\Tests\StderrCaptureFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Kimi gate wiring of {@see JobRunner}: the self-test before a gated run and the post-run audit
 * of the hook records against the wire window.
 *
 * The engine is not selectable yet ({@see RunnerFactory::create()} still rejects `kimi`), so the
 * factory is a test hook that dispatches to {@see RunnerFactory::createKimiRunner()}. The fake
 * `kimi` creates the session directory and its wire, and the real gate shim writes the hook records:
 * the audit is exercised end to end, not on hand-written record arrays.
 */
#[CoversClass(JobRunner::class)]
#[CoversClass(KimiRunner::class)]
final class KimiGateWiringTest extends TestCase
{
    private const string SESSION = 'session_69baaee8-5306-4648-b06b-465a8bb88e1f';

    /** The tool call in the fixture wire of {@see wireCall()}. */
    private const string WIRE_CALL = 'tool_ZDytl2a7NOY8eQzzhmhfjcjN';

    private string $root;
    private string $repo;
    private string $kimiHome;
    private string $state;
    private FakeTelegram $tg;
    private RunState $runState;
    private Database $db;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-gate-wiring-'.uniqid('', true);
        $this->repo = $this->root.'/repo';
        $this->kimiHome = $this->root.'/kimi';
        $this->state = $this->root.'/state';
        foreach ([$this->repo, $this->kimiHome, $this->state, $this->root.'/outbox', $this->root.'/inbox'] as $dir) {
            mkdir($dir, 0o700, true);
        }
        foreach (['credentials', 'sessions', 'oauth'] as $name) {
            mkdir($this->kimiHome.'/'.$name, 0o700);
        }
        copy(dirname(__DIR__).'/fixtures/kimi/config.toml', $this->kimiHome.'/config.toml');

        $this->tg = new FakeTelegram();
        $this->runState = new RunState();
        $this->db = new Database($this->root);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    /** A gated run whose gate shim fails its self-test must not start, and the reason is returned. */
    public function testAGatedRunIsRefusedWhenTheShimSelfTestFails(): void
    {
        $readonly = $this->root.'/readonly-state';
        mkdir($readonly, 0o700);
        chmod($readonly, 0o500);
        if (is_writable($readonly)) {
            self::markTestSkipped('Running as a user that can write any directory.');
        }

        try {
            $runner = $this->jobRunner($this->config($readonly));
            $error = $runner->launchJob(
                1, 0, RunKind::KIND_CHAT, 'hello', null, 100, null,
                SystemPrompt::MODE_PM, 0,
            );

            self::assertNotNull($error, 'a gated run whose shim self-test fails must be refused');
            self::assertStringContainsString('Kimi gate self-test failed', $error);
            self::assertStringContainsString('not writable', $error);
            self::assertNull($this->runState->activeJob);
        } finally {
            chmod($readonly, 0o700);
        }
    }

    /**
     * A gated run whose gate shim hangs must stop the launch, not freeze the bot: the self-test has a
     * short deadline, the child is terminated and the refusal mentions the timeout.
     */
    public function testAGatedRunIsRefusedWhenTheSelfTestTimesOut(): void
    {
        $runner = $this->jobRunner($this->config(), $this->sleepingShim());
        $started = microtime(true);
        $error = $runner->launchJob(
            1, 0, RunKind::KIND_CHAT, 'hello', null, 100, null,
            SystemPrompt::MODE_PM, 0,
        );
        $elapsed = microtime(true) - $started;

        self::assertNotNull($error, 'a self-test that hangs must be refused');
        self::assertStringContainsString('timed out', $error);
        self::assertNull($this->runState->activeJob, 'the hung shim must not leave a running job');
        self::assertLessThan(30.0, $elapsed, 'the launch must return promptly instead of waiting for the stub');
    }

    /**
     * A gated run with a hook record missing: the audit sees the wire call, reports a violation
     * loudly in the chat and the run gets no success status.
     */
    public function testAGatedRunWithAMissingHookRecordIsLoudAndFails(): void
    {
        $runner = $this->jobRunner($this->config());
        $this->launchGated($runner);

        $kimi = $this->activeKimiRunner();
        // The shim records another call, not the one the run's wire holds: that is a skipped hook.
        $this->runShim($this->runHome(), ['tool_name' => 'Read', 'tool_call_id' => 'tool_anotherCall0000000000', 'tool_input' => []]);

        $outcome = $this->finish($runner);

        self::assertNotNull($outcome, 'the run must finish');
        self::assertNull($outcome->result, 'a gate violation has no success result');
        self::assertNotNull($outcome->failure);
        self::assertStringContainsString('Kimi gate violation', $outcome->failure);
        $texts = $this->tg->textsTo(1, 0);
        self::assertNotEmpty(array_filter($texts, static fn (string $text): bool => str_contains($text, 'Kimi gate violation')), 'the violation must be delivered to the chat');
        self::assertTrue($this->tg->deleted !== [], 'the thinking message is removed');

        $kimi->purgeRunHome();
    }

    /** A missing hook-records file is a violation on a gated run, never a skipped audit. */
    public function testAMissingHookRecordsFileIsAViolationOnAGatedRun(): void
    {
        $runner = $this->jobRunner($this->config());
        $this->launchGated($runner);
        $kimi = $this->activeKimiRunner();

        $outcome = $this->finish($runner);

        self::assertNotNull($outcome);
        self::assertNull($outcome->result);
        self::assertNotNull($outcome->failure);
        self::assertStringContainsString('Kimi gate violation', $outcome->failure);
        self::assertStringContainsString('the hook records file is missing', $outcome->failure);

        $kimi->purgeRunHome();
    }

    /**
     * The same difference on a chat run is a log line only: the reply is delivered and the run is a
     * success. This pins the gated/chat split of the reporting, and the log line itself.
     */
    public function testAChatRunWithADifferenceOnlyLogs(): void
    {
        $runner = $this->jobRunner($this->config());
        $this->launch($runner, SystemPrompt::MODE_ARCH);
        $kimi = $this->activeKimiRunner();
        // No hook records at all: the wire call has no record, but this is not a gated run.
        $outcome = null;
        $log = $this->capturedLog(function () use ($runner, &$outcome): void {
            $outcome = $this->finish($runner);
        });

        self::assertNotNull($outcome);
        self::assertNotNull($outcome->result, 'a chat run is not failed by a difference');
        self::assertNull($outcome->failure);
        $texts = $this->tg->textsTo(1, 0);
        self::assertSame([], array_values(array_filter($texts, static fn (string $text): bool => str_contains($text, 'Kimi gate violation'))));
        self::assertStringContainsString('kimi gate audit: ', $log);
        self::assertStringContainsString('tool calls with no hook record: '.self::WIRE_CALL, $log);

        $kimi->purgeRunHome();
    }

    /** A gated run whose shim records the wire call is clean and succeeds. */
    public function testAGatedRunWithMatchingRecordsSucceeds(): void
    {
        $runner = $this->jobRunner($this->config());
        $this->launchGated($runner);
        $kimi = $this->activeKimiRunner();
        $this->runShim($this->runHome(), ['tool_name' => 'Read', 'tool_call_id' => self::WIRE_CALL, 'tool_input' => []]);

        $outcome = $this->finish($runner);

        self::assertNotNull($outcome);
        self::assertNotNull($outcome->result);
        self::assertNull($outcome->failure);

        $kimi->purgeRunHome();
    }

    /**
     * A gated run that ended in an error is not audited: it has no gate answer to check, and turning
     * its missing hook records into a gate violation would hide the real error from the chat.
     */
    public function testAGatedRunThatErrsDeliversTheRealErrorNotAViolation(): void
    {
        $runner = $this->jobRunner($this->config(kimiBin: $this->failingKimi()));
        $this->launchGated($runner);

        $outcome = $this->finish($runner);

        self::assertNotNull($outcome);
        self::assertNotNull($outcome->result, 'the real error reply is delivered, not a violation');
        self::assertNotNull($outcome->failure);
        self::assertStringContainsString('Engine error', $outcome->failure);
        self::assertStringNotContainsString('Kimi gate violation', $outcome->failure);
        $texts = $this->tg->textsTo(1, 0);
        self::assertSame([], array_values(array_filter($texts, static fn (string $text): bool => str_contains($text, 'Kimi gate violation'))));
    }

    /**
     * A gate violation on a gated run that also had a PM-gate skip delivers both messages, the
     * violation first and the skip notice after it, like the timeout ending orders its two messages.
     */
    public function testAGatedViolationWithAPmGateSkipDeliversBothInOrder(): void
    {
        $runner = $this->jobRunner($this->config());
        $this->launchGated($runner);
        $kimi = $this->activeKimiRunner();
        // The shim records another call, not the wire's: a skipped hook, hence a violation.
        $this->runShim($this->runHome(), ['tool_name' => 'Read', 'tool_call_id' => 'tool_anotherCall0000000000', 'tool_input' => []]);
        // A PM-gate skip was applied during the run; the violation must still tell the author about it.
        $context = $this->runState->jobContext;
        self::assertNotNull($context, 'the gated run has a job context');
        $runId = (string) $context['pm_gate_run_id'];
        file_put_contents(PmGateHook::appliedPath($this->state, $runId), 'the stages were skipped');

        $outcome = $this->finish($runner);

        self::assertNotNull($outcome);
        self::assertNull($outcome->result, 'a gate violation has no success result');
        self::assertNotNull($outcome->failure);
        self::assertStringContainsString('Kimi gate violation', $outcome->failure);

        $texts = $this->tg->textsTo(1, 0);
        $violationIndex = null;
        $skipIndex = null;
        foreach ($texts as $i => $text) {
            if (str_contains($text, 'Kimi gate violation')) {
                $violationIndex ??= $i;
            }
            if (str_contains($text, 'Issue creation went ahead without all pipeline stages')) {
                $skipIndex ??= $i;
            }
        }
        self::assertNotNull($violationIndex, 'the violation must be delivered');
        self::assertNotNull($skipIndex, 'the PM-gate skip must be delivered');
        self::assertLessThan($skipIndex, $violationIndex, 'the violation message comes before the PM-gate skip');

        $kimi->purgeRunHome();
    }

    /** A run killed by a timeout never reaches its audit: bot.log says the audit was skipped. */
    public function testATimedOutRunLogsTheSkippedAudit(): void
    {
        $runner = $this->jobRunner($this->config(kimiBin: $this->hangingKimi(), claudeTimeout: 1));
        $this->launch($runner, SystemPrompt::MODE_ARCH);

        $log = $this->capturedLog(function () use ($runner): void {
            $this->finish($runner);
        });

        self::assertStringContainsString('kimi gate audit skipped: the run was killed by a timeout, its wire and hook records are incomplete', $log);
    }

    /** A cancelled run never reaches its audit: bot.log says the audit was skipped. */
    public function testACancelledRunLogsTheSkippedAudit(): void
    {
        $runner = $this->jobRunner($this->config(kimiBin: $this->hangingKimi()));
        $this->launch($runner, SystemPrompt::MODE_ARCH);

        $log = $this->capturedLog(function () use ($runner): void {
            $runner->cancelTopic(1, 0);
        });

        self::assertStringContainsString('kimi gate audit skipped: the run was cancelled, its wire and hook records are incomplete', $log);
    }

    private function launchGated(JobRunner $runner): void
    {
        $error = $runner->launchJob(
            1, 0, RunKind::KIND_CHAT, 'hello', null, 100, null,
            SystemPrompt::MODE_PM, 0,
        );
        self::assertNull($error, 'the gated run must start');
    }

    private function launch(JobRunner $runner, string $mode): void
    {
        $error = $runner->launchJob(
            1, 0, RunKind::KIND_CHAT, 'hello', null, 100, null,
            $mode, 0,
        );
        self::assertNull($error, 'the run must start');
    }

    private function activeKimiRunner(): KimiRunner
    {
        $job = $this->runState->activeJob;
        self::assertInstanceOf(KimiRunner::class, $job);

        return $job;
    }

    private function runHome(): string
    {
        $homes = glob($this->state.'/kimi-*') ?: [];
        self::assertCount(1, $homes);

        return $homes[0];
    }

    /** Polls to the end through {@see JobRunner::poll()} and returns the outcome. */
    private function finish(JobRunner $runner): ?RunOutcome
    {
        $outcome = $runner->poll();
        for ($i = 0; $i < 400 && $outcome === null; ++$i) {
            usleep(50000);
            $outcome = $runner->poll();
        }

        return $outcome;
    }

    /**
     * Runs the real gate shim into the run home: precisely the records the audit must read before
     * the run home is purged.
     *
     * @param array<string, mixed> $input
     */
    private function runShim(string $runHome, array $input): void
    {
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/kimi-hook.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['KIMI_CODE_HOME' => $runHome]),
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);
    }

    /** A gate-shim stub in place of `bin/kimi-hook.php` that hangs, to exercise the self-test deadline. */
    private function sleepingShim(): string
    {
        $path = $this->root.'/sleeping-shim.php';
        file_put_contents($path, "<?php\n// Simulate a stuck bootstrap: never finish.\nsleep(60);\n");

        return $path;
    }

    /**
     * A fake `kimi` that creates the session directory with a wire holding one tool call and prints a
     * valid successful stream.
     */
    private function fakeKimi(): string
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $wire = (string) preg_replace(
            '/"created_at":\d+/',
            '"created_at":'.(time() * 1000),
            (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/wire/15-subagent-bash/main.jsonl'),
            1,
        );
        $stdout = (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/run/01-plain.stdout.jsonl');

        $body = '@mkdir('.var_export($sessionDir.'/agents/main', true).', 0700, true);'."\n"
            .'file_put_contents('.var_export($sessionDir.'/agents/main/wire.jsonl', true).', '.var_export($wire, true).');'."\n"
            .'file_put_contents('.var_export($sessionDir.'/state.json', true).', "{}");'."\n"
            .'fwrite(STDOUT, '.var_export($stdout, true).');'."\n"
            .'exit(0);'."\n";
        $path = $this->root.'/fake-kimi-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".$body);
        chmod($path, 0o755);

        return $path;
    }

    /**
     * A fake `kimi` that creates the session directory and its wire (so the audit has something to
     * read) and then fails with an error, the way a run that dies right after the session starts does.
     */
    private function failingKimi(): string
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $wire = (string) preg_replace(
            '/"created_at":\d+/',
            '"created_at":'.(time() * 1000),
            (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/wire/15-subagent-bash/main.jsonl'),
            1,
        );

        $body = '@mkdir('.var_export($sessionDir.'/agents/main', true).', 0700, true);'."\n"
            .'file_put_contents('.var_export($sessionDir.'/agents/main/wire.jsonl', true).', '.var_export($wire, true).');'."\n"
            .'file_put_contents('.var_export($sessionDir.'/state.json', true).', "{}");'."\n"
            .'fwrite(STDERR, "kimi engine error: the session died");'."\n"
            .'exit(255);'."\n";
        $path = $this->root.'/fake-kimi-fail-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".$body);
        chmod($path, 0o755);

        return $path;
    }

    /** A fake `kimi` that never finishes, for a run that is cancelled or timed out. */
    private function hangingKimi(): string
    {
        $path = $this->root.'/fake-kimi-hang-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\nsleep(60);\n");
        chmod($path, 0o755);

        return $path;
    }

    /**
     * Collects what {@see GpsLab\WorkspaceAssistant\Log} writes to STDERR while {@see $run} runs.
     *
     * @param \Closure(): void $run
     */
    private function capturedLog(\Closure $run): string
    {
        if (!in_array('stderr_capture', stream_get_filters(), true)) {
            stream_filter_register('stderr_capture', StderrCaptureFilter::class);
        }
        StderrCaptureFilter::$buffer = '';
        $filter = stream_filter_append(STDERR, 'stderr_capture', STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);
        try {
            $run();
        } finally {
            stream_filter_remove($filter);
        }

        return StderrCaptureFilter::$buffer;
    }

    private function jobRunner(Config $config, ?string $kimiHookShim = null): JobRunner
    {
        $locale = new Locale();
        $delivery = new Delivery($this->tg, $config, $locale);
        $subRepoSync = new SubRepoSync($config, $locale);

        return new JobRunner(
            $config,
            $this->db,
            $this->tg,
            new GitManager($this->repo),
            $delivery,
            $locale,
            new Inbox($this->tg, $config, $locale),
            $subRepoSync,
            new QueueTexts($locale),
            new Card($this->db, $this->tg, $this->repo, $config->epicsDir, $config->epicTickMinutes * 60, $locale),
            $this->runState,
            $this->factory($config, $locale),
            new MemorySync('', $config->stateDir, $this->repo),
            $kimiHookShim,
        );
    }

    /**
     * The test hook of the not-yet-selectable engine: `create()` still rejects `kimi`, so this
     * subclass dispatches to {@see RunnerFactory::createKimiRunner()}.
     */
    private function factory(Config $config, Locale $locale): RunnerFactory
    {
        return new class($config, $locale) extends RunnerFactory {
            /**
             * @param array<string, string> $extraEnv
             */
            public function create(
                string $mode,
                string $settingsJson,
                array $extraEnv,
                string $localeCode,
                bool $withAgents,
                bool $updateOnResume,
                ?string $savedPromptHash,
                ?string $model,
            ): AgentRunner {
                return $this->createKimiRunner($mode, $extraEnv, $localeCode, $updateOnResume, $savedPromptHash);
            }
        };
    }

    private function config(?string $stateDir = null, ?string $kimiBin = null, int $claudeTimeout = 0): Config
    {
        $state = $stateDir ?? $this->state;

        return new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: $this->repo,
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: $claudeTimeout,
            claudeStallTimeout: 0,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $state,
            outboxDir: $this->root.'/outbox',
            inboxDir: $this->root.'/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            engine: Config::ENGINE_KIMI,
            kimiBin: $kimiBin ?? $this->fakeKimi(),
            kimiHome: $this->kimiHome,
        );
    }

    private static function rrmdir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rrmdir($dir.'/'.$entry);
        }
        @rmdir($dir);
    }
}
