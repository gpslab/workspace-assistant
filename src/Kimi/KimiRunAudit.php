<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The post-run audit of one Kimi run: the window of the session's wire files taken at launch against
 * the hook records the shim wrote into the run home, plus the cases in which the comparison could not
 * be made.
 *
 * The result distinguishes three outcomes the caller must report differently:
 * - clean: the sets of `tool_call_id`s match both ways;
 * - a difference: `callsWithoutRecords` and/or `recordsWithoutCalls` is non-empty, or the records
 *   file is missing (on a gated run that is a gate violation, never a skip);
 * - unavailable: there is nothing to compare against (no session directory), so the audit was
 *   skipped and only a log line is due.
 */
final class KimiRunAudit
{
    private function __construct(
        public readonly bool $audited,
        public readonly ?KimiWireAuditResult $result,
        public readonly bool $recordsMissing,
        public readonly string $unavailableReason,
    ) {
    }

    /** An audit that ran: a comparison plus whether the hook-records file was there at all. */
    public static function of(KimiWireAuditResult $result, bool $recordsMissing): self
    {
        return new self(true, $result, $recordsMissing, '');
    }

    /** The audit could not run; `$reason` is the English text for `bot.log`. */
    public static function unavailable(string $reason): self
    {
        return new self(false, null, false, $reason);
    }

    /** Whether the sets match both ways and the records file was found. */
    public function isClean(): bool
    {
        if (!$this->audited || $this->result === null || $this->recordsMissing) {
            return false;
        }

        return $this->result->callsWithoutRecords === [] && $this->result->recordsWithoutCalls === [];
    }

    /**
     * An English summary of the audit, for `bot.log` and as the detail of the violation message.
     *
     * For an unavailable audit it is the reason the audit was skipped; for a clean audit it is empty.
     * Tool call ids are opaque engine identifiers, safe to show in a chat message.
     */
    public function describe(): string
    {
        if (!$this->audited || $this->result === null) {
            return $this->unavailableReason;
        }
        $parts = [];
        if ($this->recordsMissing) {
            $parts[] = 'the hook records file is missing';
        }
        if ($this->result->callsWithoutRecords !== []) {
            $parts[] = 'tool calls with no hook record: '.implode(', ', $this->result->callsWithoutRecords);
        }
        if ($this->result->recordsWithoutCalls !== []) {
            $parts[] = 'hook records with no tool call: '.implode(', ', $this->result->recordsWithoutCalls);
        }

        return implode('; ', $parts);
    }
}
