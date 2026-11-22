<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Reads the hook-records file the gate shim appends to (`KimiHook::HOOK_RECORDS_FILE`) into the
 * `list<array>` {@see KimiWireAudit::compare()} takes.
 *
 * The file is JSONL written by `bin/kimi-hook.php`; a line that is not valid JSON — a run killed
 * mid-write leaves a truncated last line — is skipped and counted, never turned into an exception.
 * A missing file is reported as `found: false`, which the caller must treat as a violation of a
 * gated run rather than as an empty record set.
 */
final class KimiHookRecords
{
    public static function read(string $path): KimiHookRecordSet
    {
        if (!is_file($path)) {
            return new KimiHookRecordSet([], 0, false);
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return new KimiHookRecordSet([], 0, false);
        }

        $records = [];
        $skipped = 0;
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                ++$skipped;
                continue;
            }
            $records[] = $record;
        }

        return new KimiHookRecordSet($records, $skipped, true);
    }
}
