<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Kimi\KimiHook;
use GpsLab\WorkspaceAssistant\KimiRunner;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Memory\MemoryContext;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A real {@see KimiRunner} against a fake `kimi` that replays the committed spike fixtures in
 * `tests/fixtures/kimi/`. No real Kimi is needed.
 */
#[CoversClass(KimiRunner::class)]
final class KimiRunnerTest extends TestCase
{
    private const string SESSION = 'session_11111111-1111-1111-1111-111111111111';

    /** The session id the 01-plain stream fixture prints in `session.resume_hint`. */
    private const string FIXTURE_SESSION = 'session_69baaee8-5306-4648-b06b-465a8bb88e1f';

    private string $root;
    private string $repo;
    private string $home;
    private string $kimiHome;
    private string $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-runner-'.uniqid('', true);
        $this->repo = $this->root.'/repo';
        $this->home = $this->root.'/home';
        $this->kimiHome = $this->root.'/kimi';
        $this->state = $this->root.'/state';
        foreach ([$this->repo, $this->home, $this->kimiHome, $this->state] as $dir) {
            mkdir($dir, 0o700, true);
        }
        // The shared home entries the runner links, each with a file that must survive a purge.
        foreach (['credentials', 'sessions', 'oauth'] as $name) {
            mkdir($this->kimiHome.'/'.$name, 0o700);
            file_put_contents($this->kimiHome.'/'.$name.'/bait.txt', 'keep me');
        }
        file_put_contents($this->kimiHome.'/region', 'eu');
        file_put_contents($this->kimiHome.'/device_id', 'device-1');
        copy(dirname(__DIR__).'/fixtures/kimi/config.toml', $this->kimiHome.'/config.toml');
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    public function testANewRunGetsTheAgentFileAndAnIsolatedHome(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);

        $runner = $this->runner($bin, ['extraArgs' => ['--extra', 'value']]);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $record = $this->record();
        $home = (string) $record['home'];
        self::assertSame(
            ['-p', 'hello', '--output-format', 'stream-json', '--agent-file', $home.'/agent.md', '-m', 'kimi-code/kimi-for-coding', '--extra', 'value'],
            $record['argv'],
        );
        self::assertNotContains('-S', $record['argv']);
        self::assertSame('1', $record['no_update']);
        self::assertSame(0700, fileperms($home) & 0777);
        self::assertStringStartsWith($this->state.'/kimi-', $home);

        // The run home holds the rendered files, the links and the copies.
        self::assertFileExists($home.'/config.toml');
        self::assertFileExists($home.'/agent.md');
        self::assertFileExists($home.'/agents/critic.md');
        self::assertSame($this->kimiHome.'/credentials', readlink($home.'/credentials'));
        self::assertSame($this->kimiHome.'/sessions', readlink($home.'/sessions'));
        self::assertSame($this->kimiHome.'/oauth', readlink($home.'/oauth'));
        self::assertSame('eu', file_get_contents($home.'/region'));
        self::assertSame('device-1', file_get_contents($home.'/device_id'));
        // The gate shim is not installed yet: the config carries no hooks.
        self::assertStringNotContainsString('[[hooks]]', (string) file_get_contents($home.'/config.toml'));

        $result = $runner->result();
        self::assertFalse($result->isError);
        self::assertSame('OK', $result->text);
        self::assertSame(self::FIXTURE_SESSION, $result->sessionId);
        self::assertSame('kimi-code/kimi-for-coding', $runner->actualModel());
        self::assertNotNull($result->totals);
        self::assertSame(377, $result->totals->inputTokens);
        self::assertSame(0, $result->totals->cacheCreationTokens);
        self::assertSame(12800, $result->totals->cacheReadTokens);
        self::assertSame(30, $result->totals->outputTokens);

        $runner->purgeRunHome();
        self::assertDirectoryDoesNotExist($home);
    }

    /**
     * This engine reads no user scope, so the memory of the instance reaches it as CONTENT in the run
     * home's agent file, not as the paths the client prints: the run cannot read those files itself.
     */
    public function testTheMemoryOfTheInstanceIsInjectedIntoTheAgentFile(): void
    {
        $session = $this->memoryFile('SESSION.md', 'the session remembers issue #188');
        $memory = $this->memoryClient($session);
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/01-plain/main.jsonl',
            sessionDir: $this->kimiHome.'/sessions/wd_test/'.self::SESSION,
        );

        $runner = $this->runner($bin, ['memory' => new MemoryContext($memory, $this->repo, 4096)]);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        try {
            $agent = (string) file_get_contents($home.'/agent.md');
            self::assertStringContainsString('Memory of the instance (memory-3layer)', $agent);
            self::assertStringContainsString('the session remembers issue #188', $agent);
            self::assertStringNotContainsString($session, $agent, 'the run gets the content, never the paths');
        } finally {
            $runner->purgeRunHome();
        }
    }

    /**
     * A resumed session keeps the agent file it was created with, so the memory is a session snapshot:
     * the client is not asked again and neither the `-p` text nor an agent file carries the memory.
     */
    public function testAResumedRunDoesNotReadTheMemoryAgain(): void
    {
        $session = $this->memoryFile('SESSION.md', 'the session remembers issue #188');
        [$memory, $calls] = $this->recordingMemoryClient($session);
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/01-plain/main.jsonl',
            sessionDir: $this->kimiHome.'/sessions/wd_test/'.self::SESSION,
        );

        $runner = $this->runner($bin, ['memory' => new MemoryContext($memory, $this->repo, 4096)]);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $record = $this->record();
        $argv = $record['argv'];
        self::assertContains('-S', $argv);
        self::assertNotContains('--agent-file', $argv, 'a resume passes no agent file at all');
        $at = array_search('-p', $argv, true);
        self::assertIsInt($at);
        self::assertSame('hello', $argv[$at + 1], 'the -p prompt carries the user text alone, no memory');
        self::assertFileDoesNotExist($calls, 'a resume must not ask the client for the memory again');
        self::assertStringNotContainsString('Memory of the instance', (string) file_get_contents($record['home'].'/agent.md'));

        $runner->purgeRunHome();
    }

    /**
     * The memory of the instance is not part of the prompt hash: a runner with a memory client and one
     * without it hash the same prompt the same, so a memory change never looks like an operator-changed
     * prompt (and never triggers the instructions-update block) on a resume.
     */
    public function testTheMemoryDoesNotChangeThePromptHash(): void
    {
        $session = $this->memoryFile('SESSION.md', 'the session remembers issue #188');
        $memory = $this->memoryClient($session);
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);

        $withMemory = $this->runner($bin, ['memory' => new MemoryContext($memory, $this->repo, 4096)]);
        $withMemory->start('hello', null);

        $withoutMemory = $this->runner($bin);
        $withoutMemory->start('hello', null);

        self::assertSame($withoutMemory->promptHash(), $withMemory->promptHash());

        $withMemory->kill();
        $withoutMemory->kill();
        $withMemory->purgeRunHome();
        $withoutMemory->purgeRunHome();
    }

    /**
     * A resume whose saved hash matches the memory-free hash carries no session_update block: the memory
     * is outside the hash, so the same prompt with the memory set on is still recognised as unchanged.
     */
    public function testAResumeWithTheMemoryFreeHashCarriesNoUpdateBlock(): void
    {
        $session = $this->memoryFile('SESSION.md', 'the session remembers issue #188');
        $memory = $this->memoryClient($session);
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);

        // The hash a memory-free run of the same prompt stores.
        $memoryFree = $this->runner($bin);
        $memoryFree->start('hello', null);
        $savedPromptHash = $memoryFree->promptHash();
        $memoryFree->kill();
        $memoryFree->purgeRunHome();

        $runner = $this->runner($bin, [
            'memory' => new MemoryContext($memory, $this->repo, 4096),
            'updateOnResume' => true,
            'savedPromptHash' => $savedPromptHash,
        ]);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $argv = $this->record()['argv'];
        $at = array_search('-p', $argv, true);
        self::assertIsInt($at);
        self::assertSame('hello', $argv[$at + 1], 'no session_update block and no memory in the resumed -p');

        $runner->purgeRunHome();
    }

    /** The setting off: the same run home carries the prompt alone, with no memory block. */
    public function testWithoutTheMemorySettingTheAgentFileHasNoMemoryBlock(): void
    {
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/01-plain/main.jsonl',
            sessionDir: $this->kimiHome.'/sessions/wd_test/'.self::SESSION,
        );

        $runner = $this->runner($bin);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        try {
            self::assertStringNotContainsString('Memory of the instance', (string) file_get_contents($home.'/agent.md'));
        } finally {
            $runner->purgeRunHome();
        }
    }

    /** A missing client must never fail a run: the run finishes as usual, without a memory block. */
    public function testARunWithABrokenMemoryClientStillFinishes(): void
    {
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/01-plain/main.jsonl',
            sessionDir: $this->kimiHome.'/sessions/wd_test/'.self::SESSION,
        );

        $runner = $this->runner($bin, ['memory' => new MemoryContext($this->root.'/gone', $this->repo, 4096, 1)]);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        try {
            self::assertStringNotContainsString('Memory of the instance', (string) file_get_contents($home.'/agent.md'));
            self::assertFalse($runner->result()->isError);
        } finally {
            $runner->purgeRunHome();
        }
    }

    public function testAHookShimAddsTheHookTables(): void
    {
        $shim = $this->root.'/kimi-hook.php';
        file_put_contents($shim, '<?php');
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);

        $runner = $this->runner($bin, ['shim' => $shim]);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        $config = (string) file_get_contents($home.'/config.toml');
        self::assertSame(2, substr_count($config, '[[hooks]]'));
        self::assertStringContainsString('event = "SessionStart"', $config);
        self::assertStringContainsString('event = "PreToolUse"', $config);

        $runner->purgeRunHome();
    }

    /**
     * Without a configured shim the runner defaults to the bot's own `bin/kimi-hook.php` — the one
     * place it lives when deployed, not some directory derived from the source layout (a `dirname`
     * one level too high would point outside the package).
     */
    public function testTheDefaultHookShimIsTheBotsOwnBin(): void
    {
        $runner = $this->runner('', ['shim' => '']);

        $default = (new \ReflectionMethod(KimiRunner::class, 'defaultShimPath'))->invoke($runner);

        self::assertSame(dirname(__DIR__, 2).'/bin/kimi-hook.php', $default);
    }

    public function testAResumedRunUsesTheSessionAndReadsOnlyTheRunsWireWindow(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        mkdir($sessionDir.'/agents/main', 0o700, true);
        $wire = $sessionDir.'/agents/main/wire.jsonl';
        file_put_contents($wire, implode("\n", [
            '{"type":"metadata","protocol_version":"1.5"}',
            '{"type":"profile.bind","modelAlias":"kimi-code/kimi-for-coding"}',
            '{"type":"usage.record","usageScope":"turn","usage":{"inputOther":1000,"output":100,"inputCacheRead":5000,"inputCacheCreation":0}}',
        ])."\n");

        $bin = $this->fakeKimiAppend(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            $wire,
            '{"type":"usage.record","usageScope":"turn","usage":{"inputOther":7,"output":3,"inputCacheRead":11,"inputCacheCreation":0}}',
        );

        $runner = $this->runner($bin);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $argv = $this->record()['argv'];
        self::assertContains('-S', $argv);
        self::assertContains(self::SESSION, $argv);
        self::assertNotContains('--agent-file', $argv);

        $result = $runner->result();
        self::assertFalse($result->isError);
        self::assertNotNull($result->totals);
        // Only the appended line is this run's: the baseline is before the launch offset.
        self::assertSame(7, $result->totals->inputTokens);
        self::assertSame(11, $result->totals->cacheReadTokens);
        self::assertSame(3, $result->totals->outputTokens);
        // The model is written at the head, outside the window.
        self::assertSame('kimi-code/kimi-for-coding', $runner->actualModel());

        $runner->purgeRunHome();
    }

    /**
     * The audit of a resumed run is clean inside the window, although the file is cumulative and
     * still holds the earlier run's call. The window taken at launch is what keeps that call out.
     */
    public function testTheAuditOfAResumedRunIsCleanInsideTheWindow(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        mkdir($sessionDir.'/agents/main', 0o700, true);
        copy($this->fixture('wire/25-resumed-gated-1/main.jsonl'), $sessionDir.'/agents/main/wire.jsonl');

        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/25-resumed-gated-2/main.jsonl', sessionDir: $sessionDir);
        $runner = $this->runner($bin);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        $this->writeRecords($home, ['tool_rzNtmHjZWt4z4OOahlBS0247']);

        $audit = $runner->wireAudit();
        self::assertTrue($audit->audited);
        self::assertTrue($audit->isClean(), $audit->describe());
        self::assertStringContainsString(
            'tool_eHJXp4GXrripxiOAUj6kOPGM',
            (string) file_get_contents($sessionDir.'/agents/main/wire.jsonl'),
            'the earlier run\'s call is in the file, only outside the window',
        );

        $runner->purgeRunHome();
    }

    /**
     * A new session has no directory at launch: the window is the explicit empty snapshot, so the
     * run's own call is read from 0 and matches its record.
     */
    public function testANewSessionGetsTheEmptyWindow(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::FIXTURE_SESSION;
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/15-subagent-bash/main.jsonl',
            sessionDir: $sessionDir,
        );
        $runner = $this->runner($bin);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        $this->writeRecords($home, ['tool_ZDytl2a7NOY8eQzzhmhfjcjN']);

        $audit = $runner->wireAudit();
        self::assertTrue($audit->audited);
        self::assertTrue($audit->isClean(), $audit->describe());

        $runner->purgeRunHome();
    }

    /** A missing hook-records file is reported, never read as an empty (clean) set. */
    public function testAMissingHookRecordsFileIsNotACleanAudit(): void
    {
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/01-plain.stdout.jsonl'),
            wire: 'wire/15-subagent-bash/main.jsonl',
            sessionDir: $this->kimiHome.'/sessions/wd_test/'.self::FIXTURE_SESSION,
        );
        $runner = $this->runner($bin);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $audit = $runner->wireAudit();
        self::assertTrue($audit->audited);
        self::assertTrue($audit->recordsMissing);
        self::assertFalse($audit->isClean());
        self::assertStringContainsString('missing', $audit->describe());

        $runner->purgeRunHome();
    }

    public function testPromptHashAndWarningsMatchClaudeRunner(): void
    {
        $claudeBin = $this->root.'/fake-claude.php';
        file_put_contents($claudeBin, '#!'.PHP_BINARY."\n<?php\nexit(0);\n");
        chmod($claudeBin, 0o755);
        $claude = new ClaudeRunner(
            $claudeBin,
            $this->repo,
            0,
            [],
            mode: SystemPrompt::MODE_ARCH,
            locale: new Locale(),
            stateDir: $this->state,
            homeDir: $this->home,
        );
        $claude->start('hello', null);

        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $kimi = $this->runner($this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir));
        $kimi->start('hello', null);

        self::assertSame($claude->promptHash(), $kimi->promptHash());
        self::assertSame($claude->promptWarnings(), $kimi->promptWarnings());

        $claude->kill();
        $kimi->kill();
        $kimi->purgeRunHome();
    }

    public function testAChangedPromptOnResumePrependsTheUpdateBlock(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);

        $runner = $this->runner($bin, ['updateOnResume' => true, 'savedPromptHash' => 'stale-hash']);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $argv = $this->record()['argv'];
        $at = array_search('-p', $argv, true);
        self::assertIsInt($at);
        self::assertStringStartsWith('The instructions of this session have been updated', $argv[$at + 1]);
        self::assertStringEndsWith("\n\nhello", $argv[$at + 1]);
        self::assertContains('-S', $argv);
        self::assertNotContains('--agent-file', $argv);

        $runner->purgeRunHome();
    }

    /**
     * stdout stays silent while a background sub-agent works: the growth of the session's
     * wire.jsonl keeps the run alive past the stall timeout.
     */
    public function testTheStallTimerTreatsWireGrowthAsLife(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        mkdir($sessionDir.'/agents/main', 0o700, true);
        $wire = $sessionDir.'/agents/main/wire.jsonl';
        touch($wire);

        $script = '#!'.PHP_BINARY."\n<?php\n"
            .'usleep(200000);'."\n"
            .'for ($i = 0; $i < 6; ++$i) { file_put_contents('.var_export($wire, true).', \'{"type":"usage.record","usageScope":"turn","usage":{"inputOther":1,"output":1,"inputCacheRead":0,"inputCacheCreation":0}}\'."\n", FILE_APPEND); usleep(300000); }'."\n"
            .'fwrite(STDOUT, '.var_export($this->fixtureContent('run/01-plain.stdout.jsonl'), true).');'."\n"
            .'exit(0);'."\n";
        $bin = $this->root.'/fake-kimi-stall.php';
        file_put_contents($bin, $script);
        chmod($bin, 0o755);

        $runner = $this->runner($bin, ['stall' => 1]);
        $runner->start('hello', self::SESSION);
        $started = microtime(true);
        $state = $this->finish($runner);

        self::assertSame(RunnerState::Done, $state, 'wire growth must keep the run alive');
        self::assertSame('', $runner->timeoutReason());
        self::assertGreaterThan(1.0, microtime(true) - $started, 'the run must outlive the stall timeout');

        $runner->purgeRunHome();
    }

    /**
     * Another Kimi CLI on the same home can start a session during our run; its directory is not
     * ours. Only a directory whose wire records a start time at this run's launch is adopted — the
     * foreign one (recorded earlier) is left alone for sessionId() and the wire read alike.
     */
    public function testAForeignSessionDirectoryStartedDuringTheRunIsNotAdopted(): void
    {
        $foreign = $this->kimiHome.'/sessions/wd_test/session_'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $own = $this->kimiHome.'/sessions/wd_test/'.self::FIXTURE_SESSION;

        // The foreign session carries the fixture's recorded (past) start time; the run's own session
        // has its start time moved to now, the way a real run creates it.
        $body = $this->recordPrologue()
            .'@mkdir('.var_export($foreign.'/agents/main', true).', 0700, true);'."\n"
            .'file_put_contents('.var_export($foreign.'/agents/main/wire.jsonl', true).', '.var_export($this->fixtureContent('wire/01-plain/main.jsonl'), true).');'."\n"
            .'usleep(600000);'."\n"
            .'@mkdir('.var_export($own.'/agents/main', true).', 0700, true);'."\n"
            .'file_put_contents('.var_export($own.'/agents/main/wire.jsonl', true).', '.var_export($this->wireBodyForNow('wire/01-plain/main.jsonl'), true).');'."\n"
            .'fwrite(STDOUT, '.var_export($this->fixtureContent('run/01-plain.stdout.jsonl'), true).');'."\n"
            .'exit(0);'."\n";
        $bin = $this->writeFake($body);

        $runner = $this->runner($bin);
        $runner->start('hello', null);

        // Now the foreign directory exists but the run's own session does not yet: it must not be
        // exposed as the run's session (and so never persisted mid-run).
        usleep(300000);
        self::assertSame(RunnerState::Running, $runner->poll());
        self::assertNull($runner->sessionId(), 'a foreign session started by another process must not be exposed');

        self::assertSame(RunnerState::Done, $this->finish($runner));
        $result = $runner->result();
        self::assertFalse($result->isError);
        self::assertSame(self::FIXTURE_SESSION, $result->sessionId);
        self::assertNotNull($result->totals);
        // The tokens come from the run's own wire window, not from the foreign session.
        self::assertSame(377, $result->totals->inputTokens);

        $runner->purgeRunHome();
    }

    public function testSessionNotFoundIsClassifiedAndKeepsTheText(): void
    {
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/05-bad-session.stdout.jsonl'),
            $this->fixtureContent('run/05-bad-session.stderr.txt'),
            1,
        );

        $runner = $this->runner($bin);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $result = $runner->result();
        self::assertTrue($result->isError);
        self::assertSame('session_not_found', $result->errorClass);
        self::assertNull($result->limitClass);
        self::assertStringContainsString('not found.', $result->text);

        $runner->purgeRunHome();
    }

    public function testAnOccupiedSessionIsClassifiedWithoutALimit(): void
    {
        $bin = $this->fakeKimi($this->fixtureContent('run/06-occupied-second.stdout.jsonl'), exit: 4);

        $runner = $this->runner($bin);
        $runner->start('hello', self::SESSION);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $result = $runner->result();
        self::assertTrue($result->isError);
        self::assertSame('occupied', $result->errorClass);
        self::assertNull($result->limitClass);
        self::assertStringContainsString('in use', $result->text);

        $runner->purgeRunHome();
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function wireErrorScenarios(): iterable
    {
        yield 'auth' => ['auth', null, '30-mock-auth'];
        yield 'quota' => ['quota', 'quota', '30-mock-quota'];
        yield 'rate' => ['rate', 'rate', '30-mock-rate'];
    }

    #[DataProvider('wireErrorScenarios')]
    public function testTheWireErrorDecidesTheClass(string $class, ?string $limit, string $scenario): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/session_'.str_repeat('2', 8).'-2222-2222-2222-222222222222';
        $bin = $this->fakeKimi(
            $this->fixtureContent('run/'.$scenario.'.stdout.jsonl'),
            $this->fixtureContent('run/'.$scenario.'.stderr.txt'),
            1,
            'wire/'.$scenario.'/main.jsonl',
            $sessionDir,
        );

        $runner = $this->runner($bin);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $result = $runner->result();
        self::assertTrue($result->isError);
        self::assertSame($class, $result->errorClass);
        self::assertSame($limit, $result->limitClass);

        $runner->purgeRunHome();
    }

    /**
     * The purge must treat credentials/, sessions/ and oauth/ as symlinks: a purge that follows
     * them would delete the login and every topic's sessions. Both the post-run purge and the
     * startup sweep keep the linked files.
     */
    public function testPurgeAndSweepNeverFollowTheLinkedHomeEntries(): void
    {
        $sessionDir = $this->kimiHome.'/sessions/wd_test/'.self::SESSION;
        $bin = $this->fakeKimi($this->fixtureContent('run/01-plain.stdout.jsonl'), wire: 'wire/01-plain/main.jsonl', sessionDir: $sessionDir);
        $runner = $this->runner($bin);
        $runner->start('hello', null);
        self::assertSame(RunnerState::Done, $this->finish($runner));

        $home = (string) $this->record()['home'];
        self::assertTrue(is_link($home.'/credentials'));
        $runner->purgeRunHome();

        self::assertDirectoryDoesNotExist($home);
        foreach (['credentials', 'sessions', 'oauth'] as $name) {
            self::assertFileExists($this->kimiHome.'/'.$name.'/bait.txt');
        }

        // The startup sweep of a dead run home of the same shape.
        $dead = $this->state.'/kimi-20260101-000000-2147483646-abcd';
        mkdir($dead, 0o700, true);
        foreach (['credentials', 'sessions', 'oauth'] as $name) {
            symlink($this->kimiHome.'/'.$name, $dead.'/'.$name);
        }
        file_put_contents($dead.'/config.toml', '');
        $live = $this->state.'/kimi-20260101-000000-'.getmypid().'-abcd';
        mkdir($live, 0o700);
        file_put_contents($live.'/config.toml', '');

        KimiRunner::purgeRunHomes($this->state);

        self::assertDirectoryDoesNotExist($dead);
        self::assertDirectoryExists($live);
        foreach (['credentials', 'sessions', 'oauth'] as $name) {
            self::assertFileExists($this->kimiHome.'/'.$name.'/bait.txt');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runner(string $bin, array $options = []): KimiRunner
    {
        $extraArgs = $options['extraArgs'] ?? [];

        return new KimiRunner(
            kimiBin: $bin,
            repoPath: $this->repo,
            timeout: (int) ($options['timeout'] ?? 0),
            extraArgs: is_array($extraArgs) ? array_values(array_filter($extraArgs, 'is_string')) : [],
            stallTimeout: (int) ($options['stall'] ?? 0),
            appendSystemPrompt: (string) ($options['append'] ?? ''),
            mode: (string) ($options['mode'] ?? SystemPrompt::MODE_ARCH),
            locale: new Locale(),
            stateDir: $this->state,
            homeDir: $this->home,
            updateOnResume: (bool) ($options['updateOnResume'] ?? false),
            savedPromptHash: is_string($options['savedPromptHash'] ?? null) ? $options['savedPromptHash'] : null,
            kimiModel: (string) ($options['model'] ?? 'kimi-code/kimi-for-coding'),
            kimiHome: $this->kimiHome,
            hookShimPath: (string) ($options['shim'] ?? $this->root.'/no-such-shim.php'),
            memory: ($options['memory'] ?? null) instanceof MemoryContext ? $options['memory'] : null,
        );
    }

    /**
     * Writes a hook-records file into a run home, as the shim does.
     *
     * @param list<string> $toolCallIds
     */
    private function writeRecords(string $home, array $toolCallIds): void
    {
        $lines = '';
        foreach ($toolCallIds as $id) {
            $lines .= json_encode(['event' => 'PreToolUse', 'tool_call_id' => $id, 'tool_name' => 'Read', 'agent_name' => null], JSON_THROW_ON_ERROR)."\n";
        }
        file_put_contents($home.'/'.KimiHook::HOOK_RECORDS_FILE, $lines);
    }

    /** A memory file of the instance, in the data directory a `memory-3layer` client would name. */
    private function memoryFile(string $name, string $content): string
    {
        $dir = $this->root.'/memory';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $path = $dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /** A fake `memory-3layer` that names $session, the way `memory-3layer context` prints a path. */
    private function memoryClient(string $session): string
    {
        $path = $this->root.'/memory-3layer';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".'echo "session: '.$session.'\n";');
        chmod($path, 0o755);

        return $path;
    }

    /**
     * A fake `memory-3layer` like {@see memoryClient()} that also records each invocation in a file, so
     * a test can assert the client was not asked for the context.
     *
     * @return array{string, string} the client path and the calls-recording path
     */
    private function recordingMemoryClient(string $session): array
    {
        $calls = $this->root.'/memory.calls';
        $path = $this->root.'/memory-3layer-rec';
        file_put_contents(
            $path,
            '#!'.PHP_BINARY."\n<?php\n"
            .'file_put_contents('.var_export($calls, true).", \"context\\n\", FILE_APPEND);\n"
            .'echo "session: '.$session.'\n";',
        );
        chmod($path, 0o755);

        return [$path, $calls];
    }

    private function finish(KimiRunner $runner): RunnerState
    {
        $state = $runner->poll();
        for ($i = 0; $i < 400 && $state === RunnerState::Running; ++$i) {
            usleep(50000);
            $state = $runner->poll();
        }

        return $state;
    }

    /**
     * A fake `kimi` that records its argv and environment, optionally creates the run's session
     * directory with a wire fixture, prints the prepared stdout/stderr and exits with $exit.
     */
    private function fakeKimi(string $stdout, string $stderr = '', int $exit = 0, ?string $wire = null, ?string $sessionDir = null): string
    {
        $body = $this->recordPrologue();
        if ($wire !== null && $sessionDir !== null) {
            $body .= '@mkdir('.var_export($sessionDir.'/agents/main', true).', 0700, true);'."\n"
                .'file_put_contents('.var_export($sessionDir.'/agents/main/wire.jsonl', true).', '.var_export($this->wireBodyForNow($wire), true).');'."\n"
                .'file_put_contents('.var_export($sessionDir.'/state.json', true).', "{}");'."\n";
        }
        $body .= 'fwrite(STDOUT, '.var_export($stdout, true).');'."\n"
            .'fwrite(STDERR, '.var_export($stderr, true).');'."\n"
            .'exit('.$exit.');'."\n";

        return $this->writeFake($body);
    }

    /**
     * The wire fixture with its `metadata.created_at` moved to "now": the fixtures replay a session
     * recorded in the past, while the runner confirms a NEW session by that time lying at the run's
     * launch (see {@see KimiRunner::$startedAt}).
     */
    private function wireBodyForNow(string $wire): string
    {
        $content = $this->fixtureContent($wire);

        return (string) preg_replace('/"created_at":\d+/', '"created_at":'.(time() * 1000), $content, 1);
    }

    /** A fake `kimi` that appends one more line to the session's wire file before printing stdout. */
    private function fakeKimiAppend(string $stdout, string $wirePath, string $line): string
    {
        $body = $this->recordPrologue()
            .'file_put_contents('.var_export($wirePath, true).', '.var_export($line."\n", true).', FILE_APPEND);'."\n"
            .'fwrite(STDOUT, '.var_export($stdout, true).');'."\n"
            .'exit(0);'."\n";

        return $this->writeFake($body);
    }

    private function recordPrologue(): string
    {
        return 'file_put_contents('.var_export($this->recordPath(), true).', json_encode(['
            ."'argv' => array_slice(\$argv, 1),"
            ."'home' => getenv('KIMI_CODE_HOME'),"
            ."'no_update' => getenv('KIMI_CODE_NO_AUTO_UPDATE'),"
            .'], JSON_UNESCAPED_SLASHES));'."\n";
    }

    private function writeFake(string $body): string
    {
        $path = $this->root.'/fake-kimi-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".$body);
        chmod($path, 0o755);

        return $path;
    }

    /**
     * @return array{argv: list<string>, home: string|null, no_update: string|null}
     */
    private function record(): array
    {
        /** @var array{argv: list<string>, home: string|null, no_update: string|null} $record */
        $record = json_decode((string) file_get_contents($this->recordPath()), true, 512, JSON_THROW_ON_ERROR);

        return $record;
    }

    private function recordPath(): string
    {
        return $this->root.'/record-'.getmypid().'.json';
    }

    private function fixture(string $relative): string
    {
        return dirname(__DIR__).'/fixtures/kimi/'.$relative;
    }

    private function fixtureContent(string $relative): string
    {
        return (string) file_get_contents($this->fixture($relative));
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
