<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The record of one Kimi Code run, parsed from its stream-json stdout
 * ({@see KimiStreamParser::parse()}): the CLI version that produced the stream, the tool calls as
 * live-progress lines, the answer, the session to resume and the error event of a refused run.
 *
 * The stream is the only live view of a run: it carries no usage and no model (these are in
 * `wire.jsonl`, a separate reader), and it is not the history of the run — a background sub-agent
 * is printed as completed there while its `wire.jsonl` says `running`, so nothing here is a
 * statement about the state on disk.
 */
final class KimiStream
{
    /**
     * @param string|null                               $version       `version` of the `system.version` event (the first line of the stream)
     * @param list<string>                              $progressLines one line per tool call of an `assistant` message with `tool_calls`, in event order
     * @param string                                    $finalText     the text of the last `assistant` message without `tool_calls` (empty when the run produced none)
     * @param string|null                               $sessionId     `session_id` of the `session.resume_hint` event (`session_<uuid>`); printed only on success
     * @param array{code: string, message: string}|null $error         the `error` event of the run; null when the stream carried none
     * @param int                                       $retrying      how many `turn.step.retrying` events the provider retry printed
     */
    public function __construct(
        public readonly ?string $version,
        public readonly array $progressLines,
        public readonly string $finalText,
        public readonly ?string $sessionId,
        public readonly ?array $error,
        public readonly int $retrying,
    ) {
    }
}
