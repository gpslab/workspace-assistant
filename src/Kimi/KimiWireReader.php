<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Reads a window of `wire.jsonl`, the private per-session log of Kimi Code
 * (`$KIMI_CODE_HOME/sessions/<session>/agents/<agent>/wire.jsonl`).
 *
 * Pure: it consumes pre-read lines and performs no I/O. It works over a **window**, never over a
 * file: the file is append-only and cumulative across the runs of a session, so the reader sums
 * exactly the lines it is handed and nothing else. Cutting the window (a byte offset taken at
 * launch) is the caller's business. The format is undocumented upstream, so the reader is verified
 * only against the committed fixtures in `tests/fixtures/kimi/wire/`.
 */
final class KimiWireReader
{
    /**
     * The facts of the turns covered by the window: the token counts, the model and the protocol
     * version of the session, and the error of its last failed turn.
     *
     * The token counts are the sum of the `usage` of every `usage.record` with `usageScope: "turn"`
     * plus the `usage` of every `subagent.completed` record in the window: the main agent's
     * `usage.record` does not include a sub-agent's usage, so the two are added up separately.
     *
     * `model` and `protocolVersion` come from the `profile.bind` and `metadata` records, which are
     * written once at the start of the file: a window that starts later reports both as null rather
     * than guessing them, and the caller reads them from the head of the file.
     *
     * @param list<string> $lines the window's lines in file order
     */
    public static function read(array $lines): KimiWire
    {
        $inputOther = 0;
        $output = 0;
        $inputCacheRead = 0;
        $inputCacheCreation = 0;
        $model = null;
        $protocolVersion = null;
        $error = null;

        foreach (self::records($lines) as $record) {
            if (($record->type === 'usage.record' && ($record->data['usageScope'] ?? null) === 'turn')
                || $record->type === 'subagent.completed'
            ) {
                $usage = is_array($record->data['usage'] ?? null) ? $record->data['usage'] : [];
                $inputOther += self::tokens($usage['inputOther'] ?? null);
                $output += self::tokens($usage['output'] ?? null);
                $inputCacheRead += self::tokens($usage['inputCacheRead'] ?? null);
                $inputCacheCreation += self::tokens($usage['inputCacheCreation'] ?? null);
            } elseif ($record->type === 'profile.bind') {
                $alias = $record->data['modelAlias'] ?? null;
                if (is_string($alias) && $alias !== '') {
                    $model = $alias;
                }
            } elseif ($record->type === 'metadata') {
                $version = $record->data['protocol_version'] ?? null;
                if (is_string($version) && $version !== '') {
                    $protocolVersion = $version;
                }
            } elseif ($record->type === 'turn.ended') {
                $failure = $record->data['error'] ?? null;
                if (is_array($failure)) {
                    $error = self::turnError($failure);
                }
            }
        }

        return new KimiWire(
            $inputOther,
            $output,
            $inputCacheRead,
            $inputCacheCreation,
            $model,
            $protocolVersion,
            $error,
        );
    }

    /**
     * Every decodable record of the window, in window order. A loop event wrapped in a
     * `context.append_loop_event` record is unwrapped to the inner `event` (its type and its
     * `toolCallId`); every other record is reported as it is written. A tool result is not
     * interpreted here: the caller reads its `result.output`.
     *
     * A line that is not valid JSON is skipped without an exception — a run killed mid-write leaves
     * a truncated last line behind.
     *
     * @param list<string> $lines the window's lines in file order
     *
     * @return list<KimiWireRecord>
     */
    public static function records(array $lines): array
    {
        $records = [];

        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }

            $type = is_string($record['type'] ?? null) ? $record['type'] : null;
            $data = $record;

            if ($type === 'context.append_loop_event' && is_array($record['event'] ?? null)) {
                $data = $record['event'];
                $inner = $data['type'] ?? null;
                $type = is_string($inner) ? $inner : null;
            }

            $toolCallId = null;
            if ($type === 'tool.call' || $type === 'tool.result') {
                $callId = $data['toolCallId'] ?? null;
                $toolCallId = is_string($callId) && $callId !== '' ? $callId : null;
            }

            $records[] = new KimiWireRecord($type, $data, $index, $toolCallId);
        }

        return $records;
    }

    /**
     * The error of one `turn.ended` record. `name` is carried along with the code because the code
     * alone does not separate a quota 429 from an HTTP 500: both are `provider.api_error`.
     *
     * @param array<string, mixed> $failure the `error` object of the record
     */
    private static function turnError(array $failure): KimiTurnError
    {
        $details = is_array($failure['details'] ?? null) ? $failure['details'] : [];
        $statusCode = $details['statusCode'] ?? null;

        return new KimiTurnError(
            self::text($failure['code'] ?? null),
            self::text($failure['message'] ?? null),
            self::text($failure['name'] ?? null),
            ($failure['retryable'] ?? null) === true,
            is_int($statusCode) ? $statusCode : null,
        );
    }

    /** A field of an error object as a string, or null when it is missing or not a non-empty string. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A single token count as `wire.jsonl` reports it, or 0 when the value is missing or not a
     * non-negative number. A bare `(int)` cast would turn a string or negative value into a
     * plausible-but-wrong count.
     */
    private static function tokens(mixed $value): int
    {
        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (int) $value;
        }

        return 0;
    }
}
