<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

/**
 * Parses the NDJSON of `opencode run --format json` (OpenCode 2.3.0) into one {@see OpencodeTurn}.
 *
 * Pure: it consumes a list of pre-read NDJSON lines and performs no I/O. The event schema is
 * undocumented upstream, so the parser is verified only against the committed fixtures in
 * `tests/fixtures/opencode/run/`.
 */
final class OpencodeNdjsonParser
{
    /**
     * @param list<string> $lines the NDJSON lines of one run, in order
     */
    public static function parse(array $lines): OpencodeTurn
    {
        $sessionId = null;
        $finalText = '';
        /** @var list<array{tool: string, command: string|null, status: string|null}> $toolCalls */
        $toolCalls = [];
        /** @var array<string, mixed>|null $error */
        $error = null;
        $hasSubagent = false;
        $input = 0;
        $output = 0;
        $reasoning = 0;
        $cacheRead = 0;
        $cacheWrite = 0;
        $sawTokens = false;
        $costUsd = null;

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }

            $sid = $event['sessionID'] ?? null;
            if (is_string($sid) && $sid !== '' && $sessionId === null) {
                $sessionId = $sid;
            }

            $type = $event['type'] ?? null;
            $part = is_array($event['part'] ?? null) ? $event['part'] : [];

            if ($type === 'text') {
                $text = $part['text'] ?? null;
                if (is_string($text)) {
                    $finalText = $text;
                }
            } elseif ($type === 'tool_use') {
                $toolCalls[] = self::toolCall($part);
                if (($part['tool'] ?? null) === 'subagent') {
                    $hasSubagent = true;
                }
            } elseif ($type === 'step_finish') {
                $tokens = is_array($part['tokens'] ?? null) ? $part['tokens'] : [];
                if ($tokens !== []) {
                    $sawTokens = true;
                    $cache = is_array($tokens['cache'] ?? null) ? $tokens['cache'] : [];
                    $input += (int) ($tokens['input'] ?? 0);
                    $output += (int) ($tokens['output'] ?? 0);
                    $reasoning += (int) ($tokens['reasoning'] ?? 0);
                    $cacheRead += (int) ($cache['read'] ?? 0);
                    $cacheWrite += (int) ($cache['write'] ?? 0);
                }
                $cost = $part['cost'] ?? null;
                if (is_int($cost) || is_float($cost)) {
                    $costUsd = ($costUsd ?? 0.0) + (float) $cost;
                }
            } elseif ($type === 'error') {
                $err = $event['error'] ?? null;
                if (is_array($err)) {
                    $error = $err;
                }
            }
        }

        return new OpencodeTurn(
            $sessionId,
            $finalText,
            $toolCalls,
            $sawTokens ? new OpencodeTokens($input, $output, $reasoning, $cacheRead, $cacheWrite) : null,
            $costUsd,
            $hasSubagent,
            $error,
        );
    }

    /**
     * The neutral record of one `tool_use` part: the tool name, the command of a shell call (null for
     * any other tool) and the call status (`completed`/`error`).
     *
     * @param array<string, mixed> $part the `part` object of a `tool_use` event
     *
     * @return array{tool: string, command: string|null, status: string|null}
     */
    private static function toolCall(array $part): array
    {
        $state = is_array($part['state'] ?? null) ? $part['state'] : [];
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $command = $input['command'] ?? null;

        return [
            'tool' => is_string($part['tool'] ?? null) ? $part['tool'] : '',
            'command' => is_string($command) ? $command : null,
            'status' => is_string($state['status'] ?? null) ? $state['status'] : null,
        ];
    }
}
