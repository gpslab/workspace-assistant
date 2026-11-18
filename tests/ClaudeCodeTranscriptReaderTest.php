<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeCodeTranscriptReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaudeCodeTranscriptReader::class)]
final class ClaudeCodeTranscriptReaderTest extends TestCase
{
    private const string CREATE = 'gh issue create --title t --body b';

    private static function user(string $text): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => false, 'message' => ['role' => 'user', 'content' => $text]]);
    }

    private static function agentCall(string $id, string $agent, bool $sidechain = false): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => $sidechain, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Agent', 'input' => ['subagent_type' => $agent]],
        ]]]);
    }

    private static function agentCallWithoutAgent(string $id): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Agent', 'input' => ['prompt' => 'no subagent_type']],
        ]]]);
    }

    private static function nonAgentToolUseWithAgent(string $id): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Greet', 'input' => ['subagent_type' => 'critic']],
        ]]]);
    }

    private static function agentResult(string $id, string $status = 'completed', bool $sidechain = false): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => $sidechain, 'toolUseResult' => ['status' => $status], 'message' => ['content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'done'],
        ]]]);
    }

    private static function bash(string $id, string $command): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => ['command' => $command]],
        ]]]);
    }

    private static function bashResult(string $id, bool $isError = false): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok', 'is_error' => $isError],
        ]]]);
    }

    public function testAgentCallsCarryPositionsAndFlags(): void
    {
        $lines = [self::user('design it'), self::agentCall('a1', 'critic'), self::agentResult('a1')];

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 1, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }

    public function testOnlyAgentCallsAreReturned(): void
    {
        $lines = [self::user('x'), self::bash('b1', 'ls -la'), self::bashResult('b1')];

        self::assertSame([], ClaudeCodeTranscriptReader::agentCalls($lines));
    }

    public function testAgentCallWithoutSubagentTypeIsIgnored(): void
    {
        $lines = [self::user('x'), self::agentCallWithoutAgent('a1'), self::agentResult('a1')];

        self::assertSame([], ClaudeCodeTranscriptReader::agentCalls($lines));
    }

    public function testNonAgentToolNameIsNotAnAgentCall(): void
    {
        $lines = [self::user('x'), self::nonAgentToolUseWithAgent('a1'), self::agentResult('a1')];

        self::assertSame([], ClaudeCodeTranscriptReader::agentCalls($lines));
    }

    public function testCallCarriesAsyncAndSidechainFlags(): void
    {
        $lines = [
            self::user('x'),
            self::agentCall('a1', 'critic'), self::agentResult('a1', 'async_launched'),
            self::agentCall('a2', 'verifier', true), self::agentResult('a2', 'completed', true),
        ];

        self::assertSame(
            [
                ['agent' => 'critic', 'callAt' => 1, 'resultAt' => 2, 'completed' => false, 'asyncLaunched' => true, 'isSidechain' => false],
                ['agent' => 'verifier', 'callAt' => 3, 'resultAt' => 4, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => true],
            ],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }

    public function testSidechainResultMarksMainChainCallAsSidechain(): void
    {
        $lines = [self::user('x'), self::agentCall('a1', 'critic'), self::agentResult('a1', 'completed', true)];

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 1, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => true]],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }

    public function testResultPositionIsNullWithoutAResult(): void
    {
        $lines = [self::user('x'), self::agentCall('a1', 'critic')];

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 1, 'resultAt' => null, 'completed' => false, 'asyncLaunched' => false, 'isSidechain' => false]],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }

    public function testWindowDropsCallsBeforeSuccessfulCreationFollowedByAuthorMessage(): void
    {
        $lines = [
            self::user('design it'),
            self::agentCall('a1', 'critic'), self::agentResult('a1'),
            self::bash('b1', self::CREATE), self::bashResult('b1'),
            self::user('and another one'),
        ];

        self::assertSame([], ClaudeCodeTranscriptReader::agentCalls($lines));
    }

    public function testWindowKeepsCallsAfterCreationAndAuthorMessage(): void
    {
        $lines = [
            self::user('design it'),
            self::agentCall('a1', 'critic'), self::agentResult('a1'),
            self::bash('b1', self::CREATE), self::bashResult('b1'),
            self::user('and another one'),
            self::agentCall('a2', 'verifier'), self::agentResult('a2'),
        ];

        self::assertSame(
            [['agent' => 'verifier', 'callAt' => 6, 'resultAt' => 7, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }

    public function testFailedCreationDoesNotCloseTheWindow(): void
    {
        $lines = [
            self::user('x'),
            self::agentCall('a1', 'critic'), self::agentResult('a1'),
            self::bash('b1', self::CREATE), self::bashResult('b1', true),
            self::user('retry'),
        ];

        self::assertSame(
            [['agent' => 'critic', 'callAt' => 1, 'resultAt' => 2, 'completed' => true, 'asyncLaunched' => false, 'isSidechain' => false]],
            ClaudeCodeTranscriptReader::agentCalls($lines),
        );
    }
}
