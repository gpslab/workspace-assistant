<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * One record of a `wire.jsonl` window, as {@see KimiWireReader::records()} reports it: the type,
 * the decoded record and the position of its line in the window. A loop event wrapped in a
 * `context.append_loop_event` record is unwrapped — the type is the inner `event.type`
 * (`tool.call`, `tool.result`, `step.end`, …) and the data is the inner `event` object. Every other
 * record (`turn.prompt`, `usage.record`, `subagent.completed`, `turn.ended`, …) is reported as it
 * is written.
 */
final class KimiWireRecord
{
    /**
     * @param ?string              $type       the record type, or the inner `event.type` of a wrapped loop event
     * @param array<string, mixed> $data       the decoded record, or the inner `event` object of a wrapped loop event
     * @param int                  $index      the position of the record's line in the window (0-based)
     * @param ?string              $toolCallId the `toolCallId` of a `tool.call` or `tool.result`; null on every other type
     */
    public function __construct(
        public readonly ?string $type,
        public readonly array $data,
        public readonly int $index,
        public readonly ?string $toolCallId,
    ) {
    }
}
