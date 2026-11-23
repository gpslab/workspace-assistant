<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

use GpsLab\WorkspaceAssistant\SecretScanner;

/**
 * Parses the stdout of `kimi -p … --output-format stream-json` (Kimi Code CLI 2.7.2) into one
 * {@see KimiStream}.
 *
 * It consumes a list of pre-read lines and reads nothing else: the only outside access is the mask
 * of a tool-call argument ({@see SecretScanner}), which reads the known secret values and logs a
 * finding. The event schema is undocumented upstream, so the parser is verified only against the
 * committed fixtures in `tests/fixtures/kimi/run/` and the facts in `.claude-docs/internals.md`.
 *
 * Two properties of that stdout drive the shape of the parser:
 *
 * - **It is not the history of the run.** A `tool` message of a `run_in_background` sub-agent says
 *   `status: completed` even while the agent is still running, so only `assistant` messages are
 *   read for the answer and the tool messages are skipped entirely.
 * - **A hook failure is an `assistant` message too** ("PreToolUse hook … failed to execute …"),
 *   printed right before the failed tool call is repeated. Such a notice is not the answer of the
 *   run, so any text that a tool call follows is dropped ({@see parse()}).
 *
 * The lines of the answer are the raw text of the engine: the caller masks them before they reach a
 * Telegram message. The argument of a tool call is masked here, before the clip, because a secret
 * cut in the middle matches neither a shape pattern nor a known value any more.
 */
final class KimiStreamParser
{
    /**
     * The argument keys a progress line is summarised from, in the order they are tried. Only keys
     * that stand for the work of the call are here: a call whose arguments are not recognised is
     * printed by its tool name alone, so the line never turns into a dump of the raw arguments.
     */
    private const array SUMMARY_KEYS = [
        'command',
        'description',
        'pattern',
        'path',
        'query',
        'url',
        'prompt',
        'subagent_type',
    ];

    /** Length a progress line is clipped to (the tool name is not counted). */
    private const int CLIP_LENGTH = 80;

    /**
     * @param list<string> $lines the stream-json lines of one run, in order
     */
    public static function parse(array $lines): KimiStream
    {
        $version = null;
        /** @var list<string> $progressLines */
        $progressLines = [];
        /** @var list<array{tool: string, command: string|null, status: string|null, id: string|null}> $toolCalls */
        $toolCalls = [];
        /** @var string|null $pendingText the last text not (yet) known to be a status line */
        $pendingText = null;
        $sessionId = null;
        /** @var array{code: string, message: string}|null $error */
        $error = null;
        $retrying = 0;

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                // stdout is a stream of events with no promise about its lines: anything that is
                // not a JSON object (a broken line, a scalar, a log line) is skipped.
                continue;
            }

            if (($event['role'] ?? null) === 'assistant') {
                $calls = $event['tool_calls'] ?? null;
                if (is_array($calls) && $calls !== []) {
                    foreach ($calls as $call) {
                        if (is_array($call)) {
                            $progressLines[] = self::progressLine($call);
                            $toolCalls[] = self::toolCall($call);
                        }
                    }
                    // The call is repeated after a hook failure notice, and the answer of the run
                    // comes after the call: a text a tool call follows is a status line, not the
                    // answer, so the pending one is dropped here.
                    $pendingText = null;

                    continue;
                }

                $content = $event['content'] ?? null;
                if (is_string($content)) {
                    $pendingText = $content;
                }

                continue;
            }

            $type = $event['type'] ?? null;

            if ($type === 'system.version') {
                $reported = $event['version'] ?? null;
                if ($version === null && is_string($reported) && $reported !== '') {
                    $version = $reported;
                }
            } elseif ($type === 'session.resume_hint') {
                $reported = $event['session_id'] ?? null;
                if ($sessionId === null && is_string($reported) && $reported !== '') {
                    $sessionId = $reported;
                }
            } elseif ($type === 'turn.step.retrying') {
                ++$retrying;
            } elseif ($type === 'error') {
                $code = $event['code'] ?? null;
                if ($error === null && is_string($code) && $code !== '') {
                    $message = $event['message'] ?? null;
                    $error = ['code' => $code, 'message' => is_string($message) ? $message : ''];
                }
            }
        }

        return new KimiStream($version, $progressLines, $pendingText ?? '', $sessionId, $error, $retrying, $toolCalls);
    }

    /**
     * The neutral record of one tool call ({@see \GpsLab\WorkspaceAssistant\TurnRecord}): the tool
     * name, the command of a call, its id and its status. A `Bash` call is the neutral `shell` call,
     * as in the OpenCode stream. The stdout stream carries no per-call status (`tool` messages are
     * not the history of the run, see the class doc), so the status is `null`: the caller joins the
     * call's `id` to the `tool.result` of the session's `wire.jsonl` to decide it.
     *
     * @param array<mixed> $call one item of a `tool_calls` list of an `assistant` message
     *
     * @return array{tool: string, command: string|null, status: string|null, id: string|null}
     */
    private static function toolCall(array $call): array
    {
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];
        $name = $function['name'] ?? null;
        $name = is_string($name) && $name !== '' ? $name : '';

        $arguments = $function['arguments'] ?? null;
        $args = is_string($arguments) ? json_decode($arguments, true) : null;
        $command = is_array($args) ? ($args['command'] ?? null) : null;
        $isShell = $name === 'Bash';
        $id = $call['id'] ?? null;

        return [
            'tool' => $isShell ? 'shell' : $name,
            'command' => $isShell && is_string($command) ? $command : null,
            'status' => null,
            'id' => is_string($id) && $id !== '' ? $id : null,
        ];
    }

    /**
     * The progress line of one tool call: the tool name and a short summary of its arguments
     * (`Read a.txt`, `Bash echo ready`, `Agent Critique claim`).
     *
     * @param array<mixed> $call one item of a `tool_calls` list of an `assistant` message
     */
    private static function progressLine(array $call): string
    {
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];
        $name = $function['name'] ?? null;
        $name = is_string($name) && $name !== '' ? $name : 'Tool';

        $summary = self::summary($function['arguments'] ?? null);

        return $summary === '' ? $name : $name.' '.$summary;
    }

    /**
     * A short summary of the arguments of a call: the value of the first {@see SUMMARY_KEYS} key
     * the arguments carry (`arguments` is a JSON string of the engine), masked and clipped to one
     * line. An unreadable or unrecognised argument object has no summary.
     *
     * Masked before the clip, as {@see \GpsLab\WorkspaceAssistant\ClaudeRunner::formatToolUse} and
     * {@see \GpsLab\WorkspaceAssistant\OpencodeRunner} do it: a secret cut in the middle of a token
     * matches neither a shape pattern nor a known value, so the send-time mask of the caller would
     * let the tail through.
     */
    private static function summary(mixed $arguments): string
    {
        $args = is_string($arguments) ? json_decode($arguments, true) : null;
        if (!is_array($args)) {
            return '';
        }

        foreach (self::SUMMARY_KEYS as $key) {
            $value = $args[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::clip(SecretScanner::shared()->mask($value));
            }
        }

        return '';
    }

    /** One line of at most {@see CLIP_LENGTH} characters: a multi-line argument must not break the message. */
    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::CLIP_LENGTH ? mb_substr($text, 0, self::CLIP_LENGTH).'…' : $text;
    }
}
