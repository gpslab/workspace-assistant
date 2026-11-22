<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The differences {@see KimiWireAudit::compare()} found between the run window of a session's wire
 * files and the hook records of the run, both ways and each as a sorted set of `tool_call_id`s.
 *
 * The two lists are empty when the run is clean. Which of them is a violation is not decided here:
 * `callsWithoutRecords` is the trace of a hook that did not run (the wire holds a tool call no hook
 * record saw), `recordsWithoutCalls` of a record whose call the wire does not hold in the window.
 */
final class KimiWireAuditResult
{
    /**
     * @param list<string> $callsWithoutRecords `tool_call_id`s of the window's `tool.call`s with no hook record
     * @param list<string> $recordsWithoutCalls `tool_call_id`s of the hook records with no `tool.call` in the window
     */
    public function __construct(
        public readonly array $callsWithoutRecords,
        public readonly array $recordsWithoutCalls,
    ) {
    }
}
