<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiHookRecords;
use GpsLab\WorkspaceAssistant\Kimi\KimiHookRecordSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiHookRecords::class)]
#[CoversClass(KimiHookRecordSet::class)]
final class KimiHookRecordsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-hook-records-'.uniqid('', true);
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/hook-records.jsonl');
        @rmdir($this->root);
    }

    public function testItDecodesEveryLineInOrder(): void
    {
        file_put_contents($this->root.'/hook-records.jsonl', implode("\n", [
            '{"event":"SessionStart","tool_call_id":null}',
            '{"event":"PreToolUse","tool_call_id":"tool_a"}',
            '',
        ]));

        $set = KimiHookRecords::read($this->root.'/hook-records.jsonl');

        self::assertTrue($set->found);
        self::assertSame(0, $set->skippedLines);
        self::assertSame([
            ['event' => 'SessionStart', 'tool_call_id' => null],
            ['event' => 'PreToolUse', 'tool_call_id' => 'tool_a'],
        ], $set->records);
    }

    /** A run killed mid-write leaves a truncated line: it is skipped and counted, never fatal. */
    public function testInvalidLinesAreSkippedAndCounted(): void
    {
        file_put_contents($this->root.'/hook-records.jsonl', implode("\n", [
            '{"event":"PreToolUse","tool_call_id":"tool_a"}',
            '{"event":"PreToolUse","tool_call_id"',
            'not json at all',
        ]));

        $set = KimiHookRecords::read($this->root.'/hook-records.jsonl');

        self::assertTrue($set->found);
        self::assertSame(2, $set->skippedLines);
        self::assertSame([['event' => 'PreToolUse', 'tool_call_id' => 'tool_a']], $set->records);
    }

    /** A missing file is not an empty set: the shim that should have written it never did. */
    public function testAMissingFileIsReportedAsNotFound(): void
    {
        $set = KimiHookRecords::read($this->root.'/nowhere.jsonl');

        self::assertFalse($set->found);
        self::assertSame([], $set->records);
        self::assertSame(0, $set->skippedLines);
    }

    public function testAnEmptyFileIsFound(): void
    {
        file_put_contents($this->root.'/hook-records.jsonl', '');

        $set = KimiHookRecords::read($this->root.'/hook-records.jsonl');

        self::assertTrue($set->found);
        self::assertSame([], $set->records);
    }
}
