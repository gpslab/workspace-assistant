<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\GitHooks;
use GpsLab\WorkspaceAssistant\Kimi\KimiHook;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireAudit;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireReader;
use GpsLab\WorkspaceAssistant\PmGateHook;
use GpsLab\WorkspaceAssistant\ReviewCommitHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Kimi gate shim (`bin/kimi-hook.php`) as a real process: stdin carries the hook JSON, the gate
 * environment comes from the process environment, and the run home (`KIMI_CODE_HOME`) receives the
 * session-id file and the hook records.
 *
 * The shim holds no gate rules of its own: it runs the same PHP classes the other engines use. These
 * tests pin the wiring — the event detection, the fail-closed paths, the secret guard, the PM gate and
 * the review commit hook — not the rules, which {@see \GpsLab\WorkspaceAssistant\Tests\PmGateHookTest},
 * {@see \GpsLab\WorkspaceAssistant\Tests\ReviewCommitHookTest} and
 * {@see \GpsLab\WorkspaceAssistant\Tests\SecretGuardTest} cover directly.
 */
#[CoversClass(KimiHook::class)]
final class KimiHookTest extends TestCase
{
    private const string SESSION = 'session_11111111-1111-1111-1111-111111111111';

    private const array STAGES = [
        ['id' => 'stage_a', 'agent' => 'stage_a', 'required' => true],
        ['id' => 'stage_b', 'agent' => 'stage_b', 'required' => true],
    ];

    private const string CREATE = 'gh issue create --title t --body b';

    private string $root;
    private string $home;
    private string $state;
    private string $envFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-hook-'.bin2hex(random_bytes(4));
        $this->home = $this->root.'/kimi-run';
        $this->state = $this->root.'/state';
        mkdir($this->home, 0o700, true);
        mkdir($this->state, 0o775, true);
        // An empty .env keeps the secret scanner from logging a "not readable" line to stderr.
        $this->envFile = $this->root.'/.env';
        file_put_contents($this->envFile, '');
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    public function testSelfTestPassesWhenTheRunHomeIsWritable(): void
    {
        $result = $this->runShim([], ['KIMI_CODE_HOME' => $this->home], ['--self-test']);

        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testSelfTestFailsWhenTheRunHomeIsNotWritable(): void
    {
        $result = $this->runShim([], ['KIMI_CODE_HOME' => $this->root.'/missing'], ['--self-test']);

        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('not writable', $result['stderr']);
    }

    public function testSessionStartWritesTheSessionIdAndARecord(): void
    {
        $result = $this->runShim(['session_id' => self::SESSION, 'model' => 'kimi', 'profile' => 'x'], ['KIMI_CODE_HOME' => $this->home]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(self::SESSION, (string) file_get_contents($this->home.'/'.KimiHook::SESSION_ID_FILE));
        self::assertSame([
            ['event' => 'SessionStart', 'tool_call_id' => null, 'tool_name' => null, 'agent_name' => null, 'outcome' => null],
        ], $this->records());
    }

    public function testANonBashToolPassesAndRecords(): void
    {
        $result = $this->runShim(['tool_name' => 'Read', 'tool_call_id' => 'tool_read', 'tool_input' => ['path' => 'x']], ['KIMI_CODE_HOME' => $this->home]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame([
            ['event' => 'PreToolUse', 'tool_call_id' => 'tool_read', 'tool_name' => 'Read', 'agent_name' => null, 'outcome' => 'pass'],
        ], $this->records());
    }

    public function testASecretInAShellCommandIsDenied(): void
    {
        $token = 'ghp_'.substr(str_repeat('aB3dE5gH7jK9mN1pQ4rS', 2), 0, 36);
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => 'curl -H "Authorization: token '.$token.'" https://api.github.com/user']],
            ['KIMI_CODE_HOME' => $this->home],
        );

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('github_token', $result['stderr']);
        self::assertStringNotContainsString($token, $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testAnOrdinaryCommandPassesAndRecords(): void
    {
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => 'git status --short']],
            ['KIMI_CODE_HOME' => $this->home],
        );

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('pass', $this->records()[0]['outcome']);
    }

    public function testAGatedCommandWithAnUnlocatableHistoryIsDenied(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE]],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testAMissingSnapshotIsDenied(): void
    {
        // No snapshot for this run id: the gate fails closed and the call is denied with a record.
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testBadGateArgumentsAreDenied(): void
    {
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => '', PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testAGatedCommandWithMissingStagesIsDenied(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        $this->wire([]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('Issue creation blocked:', $result['stderr']);
        self::assertStringContainsString('stage_a', $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testAGatedCommandWithCompleteStagesPasses(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        $this->wire([
            self::agentCall('tool_a', 'stage_a'),
            self::agentResult('tool_a'),
            self::agentCall('tool_b', 'stage_b'),
            self::agentResult('tool_b'),
        ]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('pass', $this->records()[0]['outcome']);
    }

    public function testTheHistoryIsLocatedByTheSessionIdFile(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        $this->wire([
            self::agentCall('tool_a', 'stage_a'),
            self::agentResult('tool_a'),
            self::agentCall('tool_b', 'stage_b'),
            self::agentResult('tool_b'),
        ]);
        file_put_contents($this->home.'/'.KimiHook::SESSION_ID_FILE, self::SESSION);

        // The payload carries no session_id: the shim falls back to the file SessionStart wrote.
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE]],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testASessionIdThatDoesNotMatchThePatternIsAbsent(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        $this->wire([
            self::agentCall('tool_a', 'stage_a'),
            self::agentResult('tool_a'),
            self::agentCall('tool_b', 'stage_b'),
            self::agentResult('tool_b'),
        ]);

        foreach (['session_../etc', 'session_*', 'session_x/y', 'not-a-session'] as $bad) {
            $result = $this->runShim(
                ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => $bad],
                ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
            );

            self::assertSame(2, $result['code'], $bad);
            self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr'], $bad);
        }
    }

    public function testMalformedInputExitsTwo(): void
    {
        $result = $this->runRaw('not json', ['KIMI_CODE_HOME' => $this->home]);

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testAnUnknownEventExitsTwo(): void
    {
        $result = $this->runShim(['foo' => 'bar'], ['KIMI_CODE_HOME' => $this->home]);

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testTheRecordIsReadableByTheAudit(): void
    {
        // The records are built from a fixture run (13-subagent-foreground) and compared with that
        // run's wire: the set of tool_call_id must match, so the audit reports no difference.
        $fixture = __DIR__.'/../fixtures/kimi/wire/13-subagent-foreground';
        $sessionDir = $this->home.'/sessions/wd_test/'.self::SESSION;
        mkdir($sessionDir.'/agents/main', 0o700, true);
        mkdir($sessionDir.'/agents/agent-0', 0o700, true);
        copy($fixture.'/main.jsonl', $sessionDir.'/agents/main/wire.jsonl');
        copy($fixture.'/agent-0.jsonl', $sessionDir.'/agents/agent-0/wire.jsonl');

        $records = [];
        foreach (['main.jsonl', 'agent-0.jsonl'] as $file) {
            $lines = file($fixture.'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            foreach (KimiWireReader::records($lines) as $record) {
                if ($record->type === 'tool.call' && $record->toolCallId !== null) {
                    $records[] = [
                        'event' => 'PreToolUse',
                        'tool_call_id' => $record->toolCallId,
                        'tool_name' => $record->data['name'] ?? null,
                        'agent_name' => null,
                        'outcome' => 'pass',
                    ];
                }
            }
        }
        self::assertNotSame([], $records);

        $audit = KimiWireAudit::compare($sessionDir, [], $records);

        self::assertSame([], $audit->callsWithoutRecords);
        self::assertSame([], $audit->recordsWithoutCalls);
    }

    public function testTheReviewCommitHookDeniesAReviewCommand(): void
    {
        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => 'gh pr review 1 --approve']],
            ['KIMI_CODE_HOME' => $this->home, ReviewCommitHook::ENABLED_ENV => '1'],
        );

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('attaches the review to the PR head', $result['stderr']);
        self::assertSame('deny', $this->records()[0]['outcome']);
    }

    public function testAFailedBootstrapExitsTwo(): void
    {
        // A copy of the shim without its bootstrap.php: the include fails and the shim exits 2, not
        // the 255 a fatal would produce (which Kimi does not treat as a block).
        $dir = $this->root.'/shim';
        mkdir($dir, 0o700, true);
        copy(dirname(__DIR__, 2).'/bin/kimi-hook.php', $dir.'/kimi-hook.php');

        $result = $this->runScript(
            $dir.'/kimi-hook.php',
            '{"tool_name":"Bash","tool_call_id":"t","tool_input":{"command":"git status"}}',
            ['KIMI_CODE_HOME' => $this->home],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testAnExceptionExitsTwo(): void
    {
        // A non-RuntimeException from the gate (a TypeError from a malformed stage) still exits 2: the
        // outer catch handles every Throwable, not only the RuntimeException the gate's own catch does.
        $snapshot = PmGateHook::snapshotPath($this->state, 'run-1');
        mkdir(dirname($snapshot), 0775, true);
        file_put_contents($snapshot, '{"stages":["not-an-array"],"skip_allowed":{"pm":false}}');
        $this->wire([self::agentCall('tool_a', 'stage_a'), self::agentResult('tool_a')]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testAnUnknownGateKindIsDenied(): void
    {
        // An unknown gate kind is rejected by the gate-argument check before the gate runs. Without the
        // check, the kind would reach decideOnCalls, which treats an unknown kind as not-skip-allowed
        // and would allow a complete run.
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        $this->wire([
            self::agentCall('tool_a', 'stage_a'),
            self::agentResult('tool_a'),
            self::agentCall('tool_b', 'stage_b'),
            self::agentResult('tool_b'),
        ]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'bogus', PmGateHook::RUN_ENV => 'run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testAnInvalidRunIdIsDenied(): void
    {
        // A run id with a path separator is rejected by the gate-argument check before it can reach the
        // snapshot path. Without the check, '../run-1' would resolve to the snapshot of run-1 and allow.
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);
        copy($this->state.'/pm-gate/run-1.json', $this->state.'/run-1.json');
        $this->wire([
            self::agentCall('tool_a', 'stage_a'),
            self::agentResult('tool_a'),
            self::agentCall('tool_b', 'stage_b'),
            self::agentResult('tool_b'),
        ]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => '../run-1'],
        );

        self::assertSame(2, $result['code']);
        self::assertSame(KimiHook::FAILURE_REASON."\n", $result['stderr']);
    }

    public function testAnAppliedSkipWritesTheAppliedFile(): void
    {
        // A skip reason that lets an issue through is recorded in the applied-skip file, so the wiring
        // task can see that a skip was actually used.
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => true]]);
        $skipFile = $this->root.'/skip-reason';
        file_put_contents($skipFile, 'skipped for a reason');
        $this->wire([]);

        $result = $this->runShim(
            ['tool_name' => 'Bash', 'tool_call_id' => 'tool_bash', 'tool_input' => ['command' => self::CREATE], 'session_id' => self::SESSION],
            ['KIMI_CODE_HOME' => $this->home, PmGateHook::STATE_ENV => $this->state, PmGateHook::KIND_ENV => 'pm', PmGateHook::RUN_ENV => 'run-1', PmGateHook::SKIP_ENV => $skipFile],
        );

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists(PmGateHook::appliedPath($this->state, 'run-1'));
        self::assertSame('skipped for a reason', (string) file_get_contents(PmGateHook::appliedPath($this->state, 'run-1')));
    }

    /**
     * A foreground `Agent` `tool.call` of a `wire.jsonl` main file, wrapped as the spike writes it.
     */
    private static function agentCall(string $id, string $agent): string
    {
        return json_encode([
            'type' => 'context.append_loop_event',
            'event' => ['type' => 'tool.call', 'toolCallId' => $id, 'name' => 'Agent', 'args' => ['subagent_type' => $agent]],
        ], JSON_THROW_ON_ERROR);
    }

    /** The `tool.result` of a foreground sub-agent, whose header reports `status: completed`. */
    private static function agentResult(string $id): string
    {
        return json_encode([
            'type' => 'context.append_loop_event',
            'event' => [
                'type' => 'tool.result',
                'toolCallId' => $id,
                'result' => ['output' => "agent_id: agent-0\nstatus: completed\nstop_reason: completed"],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Writes the given wire lines to the session's main `wire.jsonl` under the run home.
     *
     * @param list<string> $lines
     */
    private function wire(array $lines): void
    {
        $dir = $this->home.'/sessions/wd_test/'.self::SESSION.'/agents/main';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($dir.'/wire.jsonl', implode("\n", $lines)."\n");
    }

    /**
     * The decoded hook records the shim appended to the run home.
     *
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        $path = $this->home.'/'.KimiHook::HOOK_RECORDS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $records = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $records[] = json_decode($line, true);
        }

        return $records;
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runShim(array $input, array $env = [], array $args = []): array
    {
        return $this->runRaw(json_encode($input, JSON_THROW_ON_ERROR), $env, $args);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runRaw(string $stdin, array $env = [], array $args = []): array
    {
        return $this->runScript(dirname(__DIR__, 2).'/bin/kimi-hook.php', $stdin, $env, $args);
    }

    /**
     * Runs the shim at $script (a copy without its bootstrap, for the failed-bootstrap test), with the
     * same environment scrubbing as {@see runRaw()}.
     *
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runScript(string $script, string $stdin, array $env = [], array $args = []): array
    {
        $base = getenv();
        foreach ([
            'KIMI_CODE_HOME',
            PmGateHook::STATE_ENV, PmGateHook::KIND_ENV, PmGateHook::RUN_ENV, PmGateHook::PHP_ENV, PmGateHook::SKIP_ENV,
            ReviewCommitHook::ENABLED_ENV, ReviewCommitHook::SHAS_ENV,
            GitHooks::ENV_FILE_ENV,
        ] as $name) {
            unset($base[$name]);
        }
        $env = array_merge($base, [GitHooks::ENV_FILE_ENV => $this->envFile], $env);

        $proc = proc_open(
            [PHP_BINARY, $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $out, 'stderr' => $err];
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (in_array(basename($path), ['.', '..'], true)) {
                continue;
            }
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        @rmdir($dir);
    }
}
