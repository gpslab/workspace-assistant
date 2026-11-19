<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * The engine-neutral record of one OpenCode run, parsed from its NDJSON stdout
 * ({@see OpencodeNdjsonParser::parse()}). Nothing consumes it yet: the runner task
 * wires it in and acts on the fields.
 */
final class OpencodeTurn
{
    /**
     * @param list<array{tool: string, command: string|null, status: string|null}> $toolCalls
     *                                                                                        tool calls of the run in event order (`part.tool`; for shell calls also
     *                                                                                        `part.state.input.command` and `part.state.status`)
     * @param array<string, mixed>|null                                            $error
     *                                                                                        the `error` object of the run's `error` event; null when the run had none
     */
    public function __construct(
        public readonly ?string $sessionId,
        public readonly string $finalText,
        public readonly array $toolCalls,
        public readonly ?OpencodeTokens $tokens,
        public readonly ?float $costUsd,
        public readonly bool $hasSubagent,
        public readonly ?array $error,
    ) {
    }

    /** Whether the run ended with an `error` event (OpenCode exit code 1). */
    public function isError(): bool
    {
        return $this->error !== null;
    }
}
