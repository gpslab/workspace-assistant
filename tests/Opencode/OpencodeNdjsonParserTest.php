<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\Opencode\OpencodeNdjsonParser;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeTokens;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeTurn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpencodeNdjsonParser::class)]
#[CoversClass(OpencodeTurn::class)]
#[CoversClass(OpencodeTokens::class)]
final class OpencodeNdjsonParserTest extends TestCase
{
    public function testPlainRunYieldsOneCompletedShellCallAndTheFinalText(): void
    {
        $turn = $this->parse('plain.ndjson');

        self::assertSame('ses_e264d4bf7ffe7WjNcc5PBGKfPF', $turn->sessionId);
        self::assertSame('There is **1** file in the current directory: `README.md`.', $turn->finalText);
        self::assertSame(
            [['tool' => 'shell', 'command' => 'ls', 'status' => 'completed']],
            $turn->toolCalls,
        );
        self::assertFalse($turn->hasSubagent);
        self::assertFalse($turn->isError());
        self::assertNull($turn->error);
    }

    public function testPlainRunTokensAndCostAreSummedOverEveryStep(): void
    {
        $turn = $this->parse('plain.ndjson');

        self::assertEquals(
            new OpencodeTokens(input: 6585, output: 55, reasoning: 112, cacheRead: 10240, cacheWrite: 0),
            $turn->tokens,
        );
        self::assertEqualsWithDelta(0.0022527, $turn->costUsd ?? 0.0, 0.0000001);
    }

    public function testSingleSubagentRunYieldsASubagentCallFollowedByTheFinalText(): void
    {
        $turn = $this->parse('single-subagent.ndjson');

        self::assertSame(
            [
                ['tool' => 'subagent', 'command' => null, 'status' => 'completed'],
                ['tool' => 'shell', 'command' => 'echo GATE', 'status' => 'completed'],
            ],
            $turn->toolCalls,
        );
        self::assertTrue($turn->hasSubagent);
        // The final text is the LAST text event, not the earlier "I've launched ..." one.
        self::assertStringContainsString('stage_a-done', $turn->finalText);
        self::assertStringNotContainsString("I've launched", $turn->finalText);
    }

    public function testBackgroundSubagentRunHasLaunchedASubagent(): void
    {
        $turn = $this->parse('background-subagent.ndjson');

        self::assertTrue($turn->hasSubagent);
    }

    public function testPermissionDeniedRunYieldsOneShellCallWithStatusError(): void
    {
        $turn = $this->parse('permission-denied-explicit-config.ndjson');

        self::assertSame(
            [['tool' => 'shell', 'command' => 'echo precedence-test', 'status' => 'error']],
            $turn->toolCalls,
        );
        // A denied tool call is not a run error: the run has no `error` event.
        self::assertFalse($turn->isError());
    }

    public function testErrorEventSetsTheErrorFlag(): void
    {
        $turn = $this->parse('error-provider-quota.ndjson');

        self::assertTrue($turn->isError());
        self::assertSame('provider.quota', $turn->error['type'] ?? null);
        // No step_finish event: the run reported no usage.
        self::assertNull($turn->tokens);
        self::assertNull($turn->costUsd);
    }

    public function testErrorEventWithAStringPayloadStillSetsTheErrorFlag(): void
    {
        $turn = OpencodeNdjsonParser::parse([
            '{"type":"error","sessionID":"s","error":"provider exploded"}',
        ]);

        self::assertTrue($turn->isError());
        self::assertSame(['type' => null, 'message' => 'provider exploded'], $turn->error);
    }

    public function testErrorEventWithoutAnErrorPayloadStillSetsTheErrorFlag(): void
    {
        $turn = OpencodeNdjsonParser::parse([
            '{"type":"error","sessionID":"s"}',
        ]);

        self::assertTrue($turn->isError());
        self::assertSame(['type' => null, 'message' => null], $turn->error);
    }

    public function testSessionIdTakesTheFirstEvent(): void
    {
        $turn = OpencodeNdjsonParser::parse([
            '{"type":"step_start","sessionID":"first"}',
            '{"type":"text","sessionID":"second","part":{"text":"hello"}}',
        ]);

        self::assertSame('first', $turn->sessionId);
    }

    public function testMalformedLinesAreSkipped(): void
    {
        $turn = OpencodeNdjsonParser::parse([
            'not json',
            '{"type":"text","sessionID":"s","part":{"text":"a"}}',
            '42',
            '"a scalar"',
            '{"type":"text","sessionID":"s","part":{"text":"b"}}',
        ]);

        self::assertSame('s', $turn->sessionId);
        self::assertSame('b', $turn->finalText);
    }

    public function testNonNumericOrNegativeTokensAreIgnored(): void
    {
        $turn = OpencodeNdjsonParser::parse([
            '{"type":"step_finish","sessionID":"s","part":{"tokens":{"input":"5","output":3,"reasoning":-2,"cache":{"read":1.5,"write":0}}}}',
        ]);

        self::assertEquals(
            new OpencodeTokens(input: 0, output: 3, reasoning: 0, cacheRead: 1, cacheWrite: 0),
            $turn->tokens,
        );
    }

    private function parse(string $file): OpencodeTurn
    {
        $content = (string) file_get_contents(__DIR__.'/../fixtures/opencode/run/'.$file);

        return OpencodeNdjsonParser::parse(array_values(array_filter(explode("\n", $content), static fn (string $l): bool => $l !== '')));
    }
}
