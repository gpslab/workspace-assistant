<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiWireAudit;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireAuditResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiWireAudit::class)]
#[CoversClass(KimiWireAuditResult::class)]
final class KimiWireAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-wire-audit-'.uniqid('', true);
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    public function testTheSnapshotHoldsTheByteSizeOfEveryWireFileOfTheSession(): void
    {
        $session = $this->session('session');
        $this->wire($session, '15-subagent-bash', 'main');
        $this->wire($session, '15-subagent-bash', 'agent-0');

        self::assertSame(
            [
                'agents/agent-0/wire.jsonl' => filesize($session.'/agents/agent-0/wire.jsonl'),
                'agents/main/wire.jsonl' => filesize($session.'/agents/main/wire.jsonl'),
            ],
            KimiWireAudit::snapshot($session),
        );
    }

    /**
     * A sub-agent's tool call is in its own wire file and its hook record carries no agent id: the
     * sets still match, because the audit reads every `agents/<agent>/wire.jsonl` of the session.
     */
    public function testASubAgentWireFileMatchesTheHookRecordsOfItsCall(): void
    {
        $session = $this->session('session');
        $this->wire($session, '15-subagent-bash', 'main');
        $this->wire($session, '15-subagent-bash', 'agent-0');

        $result = KimiWireAudit::compare($session, [], self::records('15-subagent-bash'));

        self::assertSame([], $result->callsWithoutRecords);
        self::assertSame([], $result->recordsWithoutCalls);
    }

    /**
     * The snapshot of a run holds the files that existed at launch; the wire file of a sub-agent
     * appears during the run and is read from 0, so its call is inside the window while the calls the
     * main file already held are not.
     */
    public function testAWireFileCreatedDuringTheRunIsReadFromItsStart(): void
    {
        $session = $this->session('session');
        $this->wire($session, '15-subagent-bash', 'main');
        $window = KimiWireAudit::snapshot($session);
        $this->wire($session, '15-subagent-bash', 'agent-0');

        $result = KimiWireAudit::compare($session, $window, self::records('15-subagent-bash'));

        self::assertSame([], $result->callsWithoutRecords);
        self::assertSame(['tool_ZDytl2a7NOY8eQzzhmhfjcjN'], $result->recordsWithoutCalls);
    }

    /**
     * The wire file of a session is cumulative: the second run sees the call of the first one. The
     * window taken before the launch leaves the audit clean; without it every resumed run would report
     * the first run's call as a call no hook recorded.
     */
    public function testAResumedRunIsCleanInsideTheWindow(): void
    {
        $session = $this->session('session');
        $this->wire($session, '25-resumed-gated-1', 'main');
        $window = KimiWireAudit::snapshot($session);
        $this->wire($session, '25-resumed-gated-2', 'main');

        self::assertStringContainsString(
            'tool_eHJXp4GXrripxiOAUj6kOPGM',
            (string) file_get_contents($session.'/agents/main/wire.jsonl'),
        );

        $inside = KimiWireAudit::compare($session, $window, self::records('25-resumed-gated-2'));
        self::assertSame([], $inside->callsWithoutRecords);
        self::assertSame([], $inside->recordsWithoutCalls);

        $whole = KimiWireAudit::compare($session, [], self::records('25-resumed-gated-2'));
        self::assertSame(['tool_eHJXp4GXrripxiOAUj6kOPGM'], $whole->callsWithoutRecords);
        self::assertSame([], $whole->recordsWithoutCalls);
    }

    /** A hook record whose call the window does not hold is reported: the record is the trace of a call the wire does not show. */
    public function testAHookRecordWithoutAToolCallIsReported(): void
    {
        $session = $this->session('session');
        $this->wire($session, '25-resumed-gated-1', 'main');
        $window = KimiWireAudit::snapshot($session);
        $this->wire($session, '25-resumed-gated-2', 'main');

        $records = [...self::records('25-resumed-gated-2'), ['event' => 'PreToolUse', 'tool_call_id' => 'tool_NotInTheWire0000000000', 'tool_name' => 'Bash', 'agent_name' => null]];
        $result = KimiWireAudit::compare($session, $window, $records);

        self::assertSame(['tool_NotInTheWire0000000000'], $result->recordsWithoutCalls);
        self::assertSame([], $result->callsWithoutRecords);
    }

    /** A tool call no hook recorded is reported: on Kimi that is the trace of a hook which did not run (or died) before the call. */
    public function testAToolCallWithoutAHookRecordIsReported(): void
    {
        $session = $this->session('session');
        $this->wire($session, '14-subagent-background', 'main');

        $result = KimiWireAudit::compare($session, [], self::withoutRecords('14-subagent-background', 'tool_TAr1YdPonzf6RJIzVqm4PPHQ'));

        self::assertSame(['tool_TAr1YdPonzf6RJIzVqm4PPHQ'], $result->callsWithoutRecords);
        self::assertSame([], $result->recordsWithoutCalls);
    }

    /**
     * The comparison is by set: the order of the records and the repeats of an id do not matter —
     * every recorded call has a `PreToolUse` and a `PostToolUse` record.
     */
    public function testTheComparisonIgnoresTheOrderAndTheRepeatsOfTheRecords(): void
    {
        $session = $this->session('session');
        $this->wire($session, '14-subagent-background', 'main');

        $records = self::records('14-subagent-background');
        self::assertSame(2, count(array_filter($records, static fn (array $record): bool => ($record['tool_call_id'] ?? null) === 'tool_eIjfLIHUYjC4M5gnREXHOJsH')));

        $result = KimiWireAudit::compare($session, [], array_reverse($records));

        self::assertSame([], $result->callsWithoutRecords);
        self::assertSame([], $result->recordsWithoutCalls);
    }

    public function testAMissingSessionDirectoryIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the session directory is not available');

        KimiWireAudit::snapshot($this->root.'/nowhere');
    }

    public function testAComparisonOnAMissingSessionDirectoryIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the session directory is not available');

        KimiWireAudit::compare($this->root.'/nowhere', [], []);
    }

    /** A session directory of this test's root. */
    private function session(string $name): string
    {
        $session = $this->root.'/'.$name;
        mkdir($session, 0o700, true);

        return $session;
    }

    /** Copies a fixture wire file into a session directory in the layout of a real session. */
    private function wire(string $session, string $scenario, string $agent): void
    {
        $dir = $session.'/agents/'.$agent;
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        copy(self::path('wire/'.$scenario.'/'.$agent.'.jsonl'), $dir.'/wire.jsonl');
    }

    /**
     * The decoded hook records of a fixture scenario.
     *
     * @return list<array<string, mixed>>
     */
    private static function records(string $scenario): array
    {
        $records = [];
        foreach (explode("\n", self::fixture('hooks/'.$scenario.'.hook-records.txt')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true);
            self::assertIsArray($record);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * The decoded hook records of a scenario without the records of one tool call.
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutRecords(string $scenario, string $toolCallId): array
    {
        return array_values(array_filter(
            self::records($scenario),
            static fn (array $record): bool => ($record['tool_call_id'] ?? null) !== $toolCallId,
        ));
    }

    /** The path of a fixture file of `tests/fixtures/kimi/`. */
    private static function path(string $file): string
    {
        return __DIR__.'/../fixtures/kimi/'.$file;
    }

    /** The content of a fixture file of `tests/fixtures/kimi/`. */
    private static function fixture(string $file): string
    {
        return (string) file_get_contents(self::path($file));
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
