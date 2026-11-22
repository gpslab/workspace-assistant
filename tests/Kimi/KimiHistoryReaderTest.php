<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiHistoryReader;
use GpsLab\WorkspaceAssistant\PmGateHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiHistoryReader::class)]
final class KimiHistoryReaderTest extends TestCase
{
    public function testAForegroundAgentCallIsReturnedWhenItsResultCompleted(): void
    {
        // The `critic` stage is called at 16 and its result reports `status: completed` at 20.
        self::assertSame(
            [['agent' => 'critic', 'callAt' => 16, 'resultAt' => 20, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            KimiHistoryReader::agentCalls(self::lines('13-subagent-foreground')),
        );
    }

    /**
     * A `run_in_background` call is launched with `status: running` and returns only when the
     * completion notification of its task arrives — a later `turn.prompt` at 33, not the launch
     * result at 20.
     */
    public function testABackgroundAgentCallIsReturnedOnlyAfterItsTaskCompletedNotification(): void
    {
        self::assertSame(
            [['agent' => 'critic', 'callAt' => 16, 'resultAt' => 33, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            KimiHistoryReader::agentCalls(self::lines('14-subagent-background')),
        );
    }

    public function testAStatusRunningResultAloneNeverCounts(): void
    {
        $lines = self::lines('14-subagent-background');
        // The run is cut right after the launch result of the background sub-agent (line 20), which
        // reports the sub-agent as running: a window that stops there holds no returned stage.
        self::assertStringContainsString('status: running', $lines[20]);

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 16, 'resultAt' => null, 'completed' => false, 'asyncLaunched' => false, 'isSidechain' => false]],
            KimiHistoryReader::agentCalls(array_slice($lines, 0, 21)),
        );
    }

    /**
     * The call is matched to its task by the `subagent.spawned` record of the call
     * (`parentToolCallId`); the launch result carries the same id as a `task_id:` line, so a wire
     * without the spawn record still matches the notification.
     */
    public function testTheLaunchResultTaskIdMatchesTheNotificationWithoutASpawnRecord(): void
    {
        $lines = array_values(array_filter(
            self::lines('14-subagent-background'),
            static fn (string $line): bool => !str_contains($line, '"subagent.spawned"'),
        ));

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 16, 'resultAt' => 32, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            KimiHistoryReader::agentCalls($lines),
        );
    }

    /**
     * The order of the stages is the return position of the list, not the call position: the neutral
     * core requires the call of a stage to come after the returned result of the previous one.
     */
    public function testTheStageOrderFollowsTheReturnPosition(): void
    {
        $stages = [
            ['id' => 'design', 'agent' => 'arch', 'required' => true],
            ['id' => 'plan', 'agent' => 'critic', 'required' => true],
        ];

        // Both stages called before either returned: the `critic` call at 1 precedes the returned
        // result of the `arch` stage at 2, so the pair does not pass.
        $parallel = KimiHistoryReader::agentCalls([
            self::agentCall('tool_one', 'arch'),
            self::agentCall('tool_two', 'critic'),
            self::agentResult('tool_one'),
            self::agentResult('tool_two'),
        ]);
        self::assertSame(
            [
                ['agent' => 'arch', 'callAt' => 0, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'critic', 'callAt' => 1, 'resultAt' => 3, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            $parallel,
        );
        self::assertSame(
            ['plan (agent critic) — ran before design, run it again after it'],
            PmGateHook::missingStagesFromCalls($parallel, $stages),
        );

        // The same calls in the order of the runbook pass.
        $sequential = KimiHistoryReader::agentCalls([
            self::agentCall('tool_one', 'arch'),
            self::agentResult('tool_one'),
            self::agentCall('tool_two', 'critic'),
            self::agentResult('tool_two'),
        ]);
        self::assertSame([], PmGateHook::missingStagesFromCalls($sequential, $stages));
    }

    /**
     * The derived fixture holds three stages: `arch` and the background `security` ran before the
     * issue was created, the `critic` stage after the author's next message. Only the `critic` call
     * counts, because the window starts at the returned result of the issue creation (line 11).
     */
    public function testAnAuthorMessageAfterAnIssueCreationOpensANewWindow(): void
    {
        self::assertSame(
            [['agent' => 'critic', 'callAt' => 13, 'resultAt' => 15, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            KimiHistoryReader::agentCalls(self::lines('derived-window')),
        );
    }

    /**
     * The completion notification of a background task is a `turn.prompt` too, but not one of the
     * author: without the author's message the same file counts all three stages, including the two
     * that ran before the issue was created.
     */
    public function testATaskCompletedNotificationDoesNotOpenANewWindow(): void
    {
        $lines = self::lines('derived-window');
        self::assertStringContainsString('"kind": "user"', $lines[12]);
        unset($lines[12]);

        self::assertSame(
            [
                ['agent' => 'arch', 'callAt' => 3, 'resultAt' => 5, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'security', 'callAt' => 6, 'resultAt' => 9, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'critic', 'callAt' => 12, 'resultAt' => 14, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            KimiHistoryReader::agentCalls(array_values($lines)),
        );
    }

    /**
     * Only `agents/main/wire.jsonl` is read: the sub-agent files of the session are their own chains
     * and never reach the gate.
     */
    public function testFromFileReadsTheMainWireOfASessionDirectory(): void
    {
        $session = sys_get_temp_dir().'/kimi-history-'.uniqid('', true);
        mkdir($session.'/agents/main', 0o700, true);
        mkdir($session.'/agents/agent-0', 0o700, true);
        copy(self::path('13-subagent-foreground/main.jsonl'), $session.'/agents/main/wire.jsonl');
        copy(self::path('13-subagent-foreground/agent-0.jsonl'), $session.'/agents/agent-0/wire.jsonl');

        try {
            $expected = KimiHistoryReader::agentCalls(self::lines('13-subagent-foreground'));

            self::assertSame($expected, KimiHistoryReader::fromFile($session));
            self::assertSame($expected, KimiHistoryReader::fromFile($session.'/agents/main/wire.jsonl'));
            self::assertNotSame([], $expected);
        } finally {
            self::rrmdir($session);
        }
    }

    public function testFromFileOnAMissingSessionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the main wire of the session is not available');

        KimiHistoryReader::fromFile(sys_get_temp_dir().'/kimi-history-missing-'.uniqid('', true));
    }

    /** One line of a `tool.call` of the `Agent` tool, as `wire.jsonl` wraps it. */
    private static function agentCall(string $id, string $agent): string
    {
        return json_encode([
            'type' => 'context.append_loop_event',
            'event' => ['type' => 'tool.call', 'toolCallId' => $id, 'name' => 'Agent', 'args' => ['subagent_type' => $agent]],
        ], JSON_THROW_ON_ERROR);
    }

    /** One line of the `tool.result` of a sub-agent that completed, as `wire.jsonl` wraps it. */
    private static function agentResult(string $id): string
    {
        return json_encode([
            'type' => 'context.append_loop_event',
            'event' => ['type' => 'tool.result', 'toolCallId' => $id, 'result' => ['output' => 'status: completed']],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The lines of a fixture `wire.jsonl`, taken by reference: the fixtures are the contract of this
     * private format and are not copied into the test.
     *
     * @return list<string>
     */
    private static function lines(string $scenario, string $agent = 'main'): array
    {
        return array_values(array_filter(
            explode("\n", self::fixture($scenario.'/'.$agent.'.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /** The path of a fixture file of `tests/fixtures/kimi/wire/`. */
    private static function path(string $file): string
    {
        return __DIR__.'/../fixtures/kimi/wire/'.$file;
    }

    /** The content of a fixture file of `tests/fixtures/kimi/wire/`. */
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
