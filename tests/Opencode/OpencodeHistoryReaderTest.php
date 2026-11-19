<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\Opencode\OpencodeHistoryReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpencodeHistoryReader::class)]
final class OpencodeHistoryReaderTest extends TestCase
{
    /**
     * @return array<string, mixed> the decoded history of a committed fixture
     */
    private static function fixture(string $name): array
    {
        $path = dirname(__DIR__).'/fixtures/opencode/history/'.$name.'.json';
        $history = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($history);

        return $history;
    }

    public function testStageSingleReturnsCallAndResultPositions(): void
    {
        self::assertSame(
            [['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            OpencodeHistoryReader::agentCalls(self::fixture('stage-single')),
        );
    }

    /**
     * The `subagent` tool is `completed` at launch, so its own state must not supply the result
     * position: only the later `synthetic` report does. The fixture's tool block is `completed` at
     * index 1, yet the result is at index 4.
     */
    public function testSubagentToolCompletedAtLaunchIsNotTheResult(): void
    {
        $history = self::fixture('stage-single');
        $tool = $history['messages'][1]['content'][1] ?? null;
        self::assertIsArray($tool);
        self::assertSame('subagent', $tool['name'] ?? null);
        self::assertSame('completed', $tool['state']['status'] ?? null);

        self::assertSame(4, OpencodeHistoryReader::agentCalls($history)[0]['resultAt']);
    }

    public function testStageSequentialReturnsBothCallsInOrder(): void
    {
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_b', 'callAt' => 5, 'resultAt' => 8, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('stage-sequential')),
        );
    }

    /**
     * In stage-parallel.json the two reports appear in array order as stage_b (index 4) then
     * stage_a (index 7), while their message `id`s sort the other way round
     * (`…msg_1da3acd340…` for stage_a < `…msg_1da3acd3b0…` for stage_b). The reader must keep the
     * array order: stage_b reports first.
     */
    public function testStageParallelOrdersReportsByArrayPositionNotMessageId(): void
    {
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 7, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_b', 'callAt' => 1, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('stage-parallel')),
        );
    }

    public function testCallWithoutReportHasNoResultPosition(): void
    {
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_c', 'callAt' => 6, 'resultAt' => null, 'completed' => false, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-unreported')),
        );
    }

    public function testSyntheticReportDoesNotOpenANewWindow(): void
    {
        // A synthetic report between calls is not a real user message: the stage_a call before the
        // issue creation still counts alongside the stage_b call after it.
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_b', 'callAt' => 8, 'resultAt' => 9, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-window-synthetic')),
        );
    }

    public function testUserMessageAfterIssueCreationOpensANewWindow(): void
    {
        // A later user message after a successful issue-creating shell call opens a new window, so
        // the stage_a call before it no longer counts.
        self::assertSame(
            [['agent' => 'stage_b', 'callAt' => 8, 'resultAt' => 9, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-window-user')),
        );
    }

    /**
     * A shell reports `status: "completed"` also when it exited non-zero, so success is read from
     * the exit code: a failed `gh issue create` (exit 1) must not open a new window and the stage_a
     * call before it still counts.
     */
    public function testFailedIssueCreationDoesNotOpenANewWindow(): void
    {
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_b', 'callAt' => 5, 'resultAt' => 6, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-window-create-failed')),
        );
    }

    /**
     * A `gh issue create` whose call errored (`status: "error"`) must not open a new window even
     * though the history holds an exit code of 0: the `status === 'completed'` check decides it.
     */
    public function testErroredIssueCreationDoesNotOpenANewWindow(): void
    {
        self::assertSame(
            [
                ['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
                ['agent' => 'stage_b', 'callAt' => 5, 'resultAt' => 6, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false],
            ],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-window-create-error')),
        );
    }

    /**
     * A sub-agent whose report carries a non-`completed` state did not finish the stage: the call
     * comes back `completed: false`, so the neutral core must not count it.
     */
    public function testErrorReportMarksTheCallIncomplete(): void
    {
        self::assertSame(
            [['agent' => 'stage_a', 'callAt' => 1, 'resultAt' => 2, 'completed' => false, 'asyncLaunched' => false, 'isSidechain' => false]],
            OpencodeHistoryReader::agentCalls(self::fixture('derived-error-report')),
        );
    }
}
