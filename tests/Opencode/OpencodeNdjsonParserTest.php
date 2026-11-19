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
    }

    private function parse(string $file): OpencodeTurn
    {
        $content = (string) file_get_contents(__DIR__.'/../fixtures/opencode/run/'.$file);

        return OpencodeNdjsonParser::parse(array_values(array_filter(explode("\n", $content), static fn (string $l): bool => $l !== '')));
    }
}
