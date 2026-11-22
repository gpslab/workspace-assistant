<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The decoded contents of a run's hook-records file (`KimiHook::HOOK_RECORDS_FILE` in the run home),
 * as {@see KimiHookRecords::read()} reports them.
 *
 * `found` is false when the file does not exist (or cannot be read). The caller must not read that as
 * "there was nothing to audit": on a gated run a missing file is a gate violation, because the shim
 * that should have written it never did.
 */
final class KimiHookRecordSet
{
    /**
     * @param list<array<string, mixed>> $records      the decoded records, in file order
     * @param int                        $skippedLines lines that are not valid JSON (a kill mid-write leaves a truncated line)
     * @param bool                       $found        whether the file existed and could be read
     */
    public function __construct(
        public readonly array $records,
        public readonly int $skippedLines,
        public readonly bool $found,
    ) {
    }
}
