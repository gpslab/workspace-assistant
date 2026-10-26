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
            'usage' => ['input_tokens' => 10, 'output_tokens' => 7],
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertFalse($result->isError);
        self::assertSame('done', $result->text);
        self::assertSame('sess-1', $result->sessionId);
        self::assertSame(10, $result->inputTokens);
        self::assertSame(7, $result->outputTokens);
    }

    public function testSumsCacheTokensIntoInput(): void
    {
        $json = json_encode([
            'type' => 'result',
            'result' => 'ok',
            'session_id' => 's',
            'usage' => [
                'input_tokens' => 5,
                'cache_read_input_tokens' => 100,
                'cache_creation_input_tokens' => 20,
                'output_tokens' => 3,
            ],
        ], JSON_THROW_ON_ERROR);

        $result = ClaudeResult::fromJson($json, '', 0);

        self::assertSame(125, $result->inputTokens);
        self::assertSame(3, $result->outputTokens);
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
        self::assertSame(0, $result->inputTokens);
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
