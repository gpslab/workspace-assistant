<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaudeResult::class)]
final class ClaudeResultTest extends TestCase
{
    public function testParsesSuccessfulResult(): void
    {
        // stream-json: the final result event (same fields + type/subtype).
        $json = json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => false,
            'result' => 'done',
            'session_id' => 'sess-1',
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertFalse($result->isError);
        self::assertSame('done', $result->text);
        self::assertSame('sess-1', $result->sessionId);
        self::assertNull($result->totals);
    }

    public function testTotalsComeFromModelUsageSummedOverModelsNotFromUsage(): void
    {
        // A run with a subagent: result.usage covers the main agent only (out 299), modelUsage all of it.
        $json = json_encode([
            'type' => 'result',
            'result' => 'ok',
            'session_id' => 's',
            'usage' => ['input_tokens' => 18, 'cache_creation_input_tokens' => 11738, 'cache_read_input_tokens' => 46236, 'output_tokens' => 299],
            'modelUsage' => [
                'claude-haiku' => ['inputTokens' => 20, 'cacheCreationInputTokens' => 20000, 'cacheReadInputTokens' => 40000, 'outputTokens' => 400],
                'claude-sonnet' => ['inputTokens' => 8, 'cacheCreationInputTokens' => 10266, 'cacheReadInputTokens' => 6236, 'outputTokens' => 200],
            ],
            'total_cost_usd' => 0.0543,
        ], JSON_THROW_ON_ERROR);

        $totals = ClaudeResult::fromJson($json, '', 0)->totals;

        self::assertNotNull($totals);
        self::assertSame(28, $totals->inputTokens);
        self::assertSame(30266, $totals->cacheCreationTokens);
        self::assertSame(46236, $totals->cacheReadTokens);
        self::assertSame(600, $totals->outputTokens);
        self::assertSame(0.0543, $totals->costUsd);
    }

    public function testMissingCostIsUnknownNotZero(): void
    {
        $json = json_encode([
            'type' => 'result',
            'result' => 'ok',
            'modelUsage' => ['m' => ['inputTokens' => 1, 'outputTokens' => 2]],
        ], JSON_THROW_ON_ERROR);

        $totals = ClaudeResult::fromJson($json, '', 0)->totals;

        self::assertNotNull($totals);
        self::assertNull($totals->costUsd);
    }

    public function testResultWithoutModelUsageHasNoTotals(): void
    {
        $json = json_encode([
            'type' => 'result',
            'result' => 'ok',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 7],
            'total_cost_usd' => 0.01,
        ], JSON_THROW_ON_ERROR);

        self::assertNull(ClaudeResult::fromJson($json, '', 0)->totals);
    }

    public function testFlagsErrorFromPayload(): void
    {
        $json = json_encode([
            'type' => 'result',
            'subtype' => 'error_during_execution',
            'is_error' => true,
            'result' => 'Failed to authenticate',
            'session_id' => 's',
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertTrue($result->isError);
        self::assertSame('Failed to authenticate', $result->text);
    }

    public function testInvalidJsonFallsBackToStderr(): void
    {
        $result = ClaudeResult::fromJson('', 'boom from stderr', 1);

        self::assertTrue($result->isError);
        self::assertSame('boom from stderr', $result->text);
        self::assertNull($result->sessionId);
        self::assertNull($result->totals);
    }

    public function testInvalidJsonWithoutOutputReportsExitCode(): void
    {
        $result = ClaudeResult::fromJson('', '', 137);

        self::assertTrue($result->isError);
        self::assertStringContainsString('137', $result->text);
    }

    public function testEmptySessionIdBecomesNull(): void
    {
        $json = json_encode([
            'result' => 'ok',
            'session_id' => '',
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertNull($result->sessionId);
    }

    public function testFallsBackToErrorsWhenResultIsEmpty(): void
    {
        // a "dead" session: --resume on a session_id with no transcript on disk.
        $json = json_encode([
            'type' => 'result',
            'is_error' => true,
            'result' => '',
            'errors' => ['No conversation found with session ID: abc-123'],
            'session_id' => 'abc-123',
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertTrue($result->isError);
        self::assertSame('No conversation found with session ID: abc-123', $result->text);
    }

    public function testJoinsMultipleErrorsWithNewline(): void
    {
        $json = json_encode([
            'is_error' => true,
            'result' => '',
            'errors' => ['first error', 'second error'],
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertSame("first error\nsecond error", $result->text);
    }

    public function testHandlesNonScalarErrorEntriesWithoutLosingReason(): void
    {
        $json = json_encode([
            'is_error' => true,
            'result' => '',
            'errors' => [['message' => 'No conversation found with session ID: abc-123']],
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertTrue($result->isError);
        self::assertSame('{"message":"No conversation found with session ID: abc-123"}', $result->text);
    }

    public function testEmptyResultAndEmptyErrorsKeepsPreviousFallback(): void
    {
        $json = json_encode([
            'is_error' => true,
            'result' => '',
            'errors' => [],
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertTrue($result->isError);
        self::assertSame('', $result->text);
    }
}
