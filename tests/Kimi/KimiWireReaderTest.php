<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiTurnError;
use GpsLab\WorkspaceAssistant\Kimi\KimiWire;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireReader;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiWireReader::class)]
#[CoversClass(KimiWire::class)]
#[CoversClass(KimiTurnError::class)]
#[CoversClass(KimiWireRecord::class)]
final class KimiWireReaderTest extends TestCase
{
    public function testToolRunSumsItsTwoUsageRecordsAndReportsTheModelAndTheProtocolVersion(): void
    {
        $wire = KimiWireReader::read(self::lines('02-tool'));

        // Two `usage.record` of `usageScope: "turn"`; the `usage` of the two `step.end` events is the
        // same count and must not be added a second time.
        self::assertSame(388 + 231, $wire->inputOther);
        self::assertSame(70 + 26, $wire->output);
        self::assertSame(12800 + 13056, $wire->inputCacheRead);
        self::assertSame(0, $wire->inputCacheCreation);
        self::assertSame('kimi-code/kimi-for-coding', $wire->model);
        self::assertSame('1.5', $wire->protocolVersion);
        self::assertNull($wire->error);
        self::assertFalse($wire->isError());
    }

    public function testSubagentUsageIsAddedToTheMainTurnUsage(): void
    {
        $wire = KimiWireReader::read(self::lines('13-subagent-foreground'));

        // The main agent's two `usage.record` (392 + 391) do not include the sub-agent's usage, so the
        // `usage` of `subagent.completed` (413) is added on top.
        self::assertSame(392 + 391 + 413, $wire->inputOther);
        self::assertSame(144 + 50 + 119, $wire->output);
        self::assertSame(12800 + 13056 + 2048, $wire->inputCacheRead);
        self::assertSame(0, $wire->inputCacheCreation);
        self::assertNull($wire->error);
    }

    public function testAUsageRecordThatIsNotScopedToTheTurnIsNotSummed(): void
    {
        $lines = self::lines('02-tool');
        // Every `usage.record` of the fixtures is scoped to the turn, so the scope guard is checked on
        // a synthetic record: only `usageScope: "turn"` counts.
        $lines[] = '{"type": "usage.record", "usage": {"inputOther": 9999, "output": 9999,'
            .' "inputCacheRead": 9999, "inputCacheCreation": 9999}}';

        $wire = KimiWireReader::read($lines);

        self::assertSame(388 + 231, $wire->inputOther);
        self::assertSame(70 + 26, $wire->output);
        self::assertSame(12800 + 13056, $wire->inputCacheRead);
        self::assertSame(0, $wire->inputCacheCreation);
    }

    public function testTheWindowSumsOnlyTheLinesItIsGiven(): void
    {
        $lines = self::lines('25-resumed-gated-3');
        // The file is cumulative: three runs of one session, each ending with `prompt.completed`.
        // The window of the third run starts right after the second run's end.
        $window = array_slice($lines, 47);

        $whole = KimiWireReader::read($lines);
        $third = KimiWireReader::read($window);

        self::assertSame(386 + 246 + 427 + 264 + 436 + 275, $whole->inputOther);
        self::assertSame(436 + 275, $third->inputOther);
        self::assertSame(64 + 20, $third->output);
        self::assertSame(13312 + 13568, $third->inputCacheRead);
        self::assertSame(0, $third->inputCacheCreation);
        // The reader that ignored the window and summed the whole file would report the same number twice.
        self::assertNotSame($whole->inputOther, $third->inputOther);
    }

    public function testAnOversizedOrFractionalTokenCountSaturatesOrReadsAsZeroInsteadOfCrashing(): void
    {
        // Two counts that are each valid alone overflow the `int` sum into a float, which would throw
        // under strict_types when KimiWire is constructed; the sum saturates at PHP_INT_MAX instead.
        $wire = KimiWireReader::read([
            '{"type": "usage.record", "usageScope": "turn", "usage": {"inputOther": '.PHP_INT_MAX.'}}',
            '{"type": "subagent.completed", "usage": {"inputOther": 5}}',
        ]);
        self::assertSame(PHP_INT_MAX, $wire->inputOther);

        // `json_decode` yields floats for these: `INF` is not a usable count, `1e19` is out of `int`
        // range and `1.5` is not a whole number, so each reads as 0 rather than a warning plus a
        // silent truncation into a plausible-but-wrong count.
        $wire = KimiWireReader::read([
            '{"type": "usage.record", "usageScope": "turn", "usage": {"inputOther": 1e400}}',
            '{"type": "usage.record", "usageScope": "turn", "usage": {"inputOther": 1e19}}',
            '{"type": "usage.record", "usageScope": "turn", "usage": {"inputOther": 1.5}}',
        ]);
        self::assertSame(0, $wire->inputOther);
    }

    public function testAModelAndAProtocolVersionAreNullWhenTheWindowStartsAfterTheHeadOfTheFile(): void
    {
        $lines = self::lines('25-resumed-gated-3');
        // `metadata` and `profile.bind` are written once at the start of the file, so a window that
        // starts later must report null for both rather than guess them.
        $third = KimiWireReader::read(array_slice($lines, 47));

        self::assertNull($third->model);
        self::assertNull($third->protocolVersion);
        self::assertSame('kimi-code/kimi-for-coding', KimiWireReader::read($lines)->model);
        self::assertSame('1.5', KimiWireReader::read($lines)->protocolVersion);
    }

    public function testAnAuthFailureIsNotRetryable(): void
    {
        $wire = KimiWireReader::read(self::lines('30-mock-auth'));

        self::assertTrue($wire->isError());

        $error = self::error($wire);
        self::assertSame('provider.auth_error', $error->code);
        self::assertSame('APIStatusError', $error->name);
        self::assertSame(403, $error->statusCode);
        self::assertFalse($error->retryable);
        self::assertNotNull($error->message);
    }

    public function testARateLimitIsRetryable(): void
    {
        $wire = KimiWireReader::read(self::lines('30-mock-rate'));

        $error = self::error($wire);
        self::assertSame('provider.rate_limit', $error->code);
        self::assertSame('APIProviderRateLimitError', $error->name);
        self::assertSame(429, $error->statusCode);
        self::assertTrue($error->retryable);
    }

    public function testAQuotaIsToldFromAServerErrorByNameNotByCode(): void
    {
        $wire = KimiWireReader::read(self::lines('30-mock-quota'));

        // A quota 429 and an HTTP 500 share the code `provider.api_error`: only `name` separates them.
        $error = self::error($wire);
        self::assertSame('provider.api_error', $error->code);
        self::assertSame('APIProviderQuotaExhaustedError', $error->name);
        self::assertSame(429, $error->statusCode);
        self::assertFalse($error->retryable);
    }

    public function testAServerErrorIsRetryable(): void
    {
        $wire = KimiWireReader::read(self::lines('30-mock-server'));

        $error = self::error($wire);
        self::assertSame('provider.api_error', $error->code);
        self::assertSame('APIStatusError', $error->name);
        self::assertSame(500, $error->statusCode);
        self::assertTrue($error->retryable);
    }

    public function testRecordsUnwrapTheLoopEventsOfAToolRun(): void
    {
        $records = KimiWireReader::records(self::lines('02-tool'));

        $toolCall = self::find($records, 'tool.call');
        self::assertSame('tool_UfxYxalG3CtqFfJUh3dM0u5a', $toolCall->toolCallId);
        self::assertSame('Read', $toolCall->data['name']);
        self::assertSame(['path' => 'a.txt'], $toolCall->data['args']);

        $toolResult = self::find($records, 'tool.result');
        self::assertSame($toolCall->toolCallId, $toolResult->toolCallId);
        self::assertSame(['output' => "1\thello", 'durationMs' => 3], $toolResult->data['result']);

        $stepEnd = self::find($records, 'step.end');
        // The first `step.end` of the run is the tool-using one; the second finishes the turn.
        self::assertSame('tool_use', $stepEnd->data['finishReason']);
        self::assertNull($stepEnd->toolCallId);

        // A wrapped event is never reported under the wrapper's own type.
        foreach ($records as $record) {
            self::assertNotSame('context.append_loop_event', $record->type);
        }
    }

    public function testRecordsReportThePositionOfEveryLineInTheWindow(): void
    {
        $lines = self::lines('02-tool');
        $records = KimiWireReader::records($lines);

        self::assertCount(count($lines), $records);
        foreach ($records as $position => $record) {
            self::assertSame($position, $record->index);
        }
    }

    public function testRecordsCarryThePromptAndTheBackgroundSubagentCompletion(): void
    {
        $records = KimiWireReader::records(self::lines('14-subagent-background'));

        $prompt = self::find($records, 'turn.prompt');
        self::assertSame(['kind' => 'user'], $prompt->data['origin']);
        self::assertNull($prompt->toolCallId);

        $completed = self::find($records, 'subagent.completed');
        self::assertSame('agent-0', $completed->data['subagentId']);
        self::assertSame(
            ['inputOther' => 399, 'output' => 96, 'inputCacheRead' => 2048, 'inputCacheCreation' => 0],
            $completed->data['usage'],
        );
    }

    public function testATruncatedLineIsSkippedAndItsNeighboursAreRead(): void
    {
        $lines = self::lines('02-tool');
        // A run killed mid-write leaves a truncated line behind; the fixtures have none.
        array_splice($lines, 16, 0, ['{"type": "usage.record", "usage": {"inputOther": 9999']);
        $lines[] = 'not json at all';

        $wire = KimiWireReader::read($lines);

        // Neither the truncated record nor the garbage line counts: the window reads as the clean file.
        self::assertSame(388 + 231, $wire->inputOther);
        self::assertSame(70 + 26, $wire->output);
        self::assertSame(12800 + 13056, $wire->inputCacheRead);
        self::assertNull($wire->error);

        $records = KimiWireReader::records($lines);

        self::assertCount(28, $records);
        // The record after the skipped line is read, and its index is its line's position in the
        // window — the skipped line still occupies one.
        self::assertSame('tool.call', $records[16]->type);
        self::assertSame(17, $records[16]->index);
        self::assertSame('tool_UfxYxalG3CtqFfJUh3dM0u5a', $records[16]->toolCallId);

        $last = $records[count($records) - 1];
        self::assertSame('prompt.completed', $last->type);
        self::assertSame(28, $last->index);
    }

    /**
     * The error of a window that must have one.
     */
    private static function error(KimiWire $wire): KimiTurnError
    {
        $error = $wire->error;
        self::assertInstanceOf(KimiTurnError::class, $error);

        return $error;
    }

    /**
     * The first record of a type in window order.
     *
     * @param list<KimiWireRecord> $records
     */
    private static function find(array $records, string $type): KimiWireRecord
    {
        foreach ($records as $record) {
            if ($record->type === $type) {
                return $record;
            }
        }

        self::fail(sprintf('No %s record in the window.', $type));
    }

    /**
     * The lines of a fixture `wire.jsonl`, taken by reference: the fixtures are the contract of this
     * private format and are not copied into the test.
     *
     * @return list<string>
     */
    private static function lines(string $scenario, string $agent = 'main'): array
    {
        $content = (string) file_get_contents(__DIR__.'/../fixtures/kimi/wire/'.$scenario.'/'.$agent.'.jsonl');

        return array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }
}
