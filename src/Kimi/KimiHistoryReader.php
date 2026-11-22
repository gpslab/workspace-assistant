<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

use GpsLab\WorkspaceAssistant\PmGateHook;

/**
 * The Kimi half of the PM gate: it reads the main agent's `wire.jsonl` of a session and turns it into
 * the same list of agent calls the neutral core of {@see PmGateHook} decides on, exactly as
 * {@see \GpsLab\WorkspaceAssistant\ClaudeCodeTranscriptReader} does for a Claude Code transcript and
 * {@see \GpsLab\WorkspaceAssistant\Opencode\OpencodeHistoryReader} for an OpenCode session history.
 *
 * Source: `agents/main/wire.jsonl` only. A sub-agent writes its own `agents/<agent>/wire.jsonl`, so
 * the main file holds the chain that decides and no sidechain filtering is needed.
 *
 * A stage is a `tool.call` of the `Agent` tool, the stage agent being `args.subagent_type`. Its result
 * is the call's own `tool.result`:
 * - a foreground call is returned when that result reports `status: completed` — the status is a line
 *   of the header of the result output (the leading `key: value` block, up to the first empty line or
 *   the `[summary]` marker: the model-written text after it is not read), which is a string, or the
 *   list of text parts a background launch reports; a result the engine flags as an error never counts;
 * - a `run_in_background` call reports `status: running` at launch, and a running call never counts:
 *   it is returned only when the completion notification of its task arrives as a later `turn.prompt`
 *   (`origin.kind: "task"`, `origin.status: "completed"`), matched to the call by the task id of its
 *   `subagent.spawned` record (`parentToolCallId`), or, without that record, by the `task_id:` line of
 *   the launch result.
 *
 * The order of the stages is their return position, never their call position: the list carries both
 * (`callAt`, `resultAt`) and the core ({@see PmGateHook::missingStagesFromCalls()}) requires the call
 * of a stage to come after the returned result of the previous one, so a stage called before the
 * previous one returned does not pass.
 *
 * Window: as for the other engines, only the calls after the last successful issue creation that was
 * followed by a message of the author count: a series of issues created in one batch passes on one set
 * of stages, the next design after the author's reply needs fresh stages. In `wire.jsonl` the author's
 * message is a `turn.prompt` with `origin.kind: "user"`; the completion notification of a background
 * task is a `turn.prompt` too, but with `origin.kind: "task"`, so it does not open a window.
 */
final class KimiHistoryReader
{
    /** The main agent's wire, relative to the session directory. */
    public const string MAIN_WIRE = 'agents/main/wire.jsonl';

    /**
     * @param list<string> $lines the lines of the main agent's `wire.jsonl`, in file order
     *
     * @return list<array{
     *     agent: string,
     *     callAt: int,
     *     resultAt: int|null,
     *     completed: bool,
     *     asyncLaunched: bool,
     *     isSidechain: bool,
     * }>
     */
    public static function agentCalls(array $lines): array
    {
        /** @var array<string, array{agent: string, callAt: int, resultAt: int|null, completed: bool, asyncLaunched: bool, isSidechain: bool}> $calls */
        $calls = [];
        /** @var array<string, string> $tasks tool call id => task id of the sub-agent the call spawned */
        $tasks = [];
        /** @var array<string, true> $issueCalls tool call ids of the Bash calls that create an issue */
        $issueCalls = [];
        $windowStart = -1;
        $lastCreate = null;

        foreach (KimiWireReader::records($lines) as $record) {
            $type = $record->type;

            if ($type === 'tool.call') {
                $id = $record->toolCallId;
                if ($id === null) {
                    continue;
                }
                $args = $record->data['args'] ?? null;
                $args = is_array($args) ? $args : [];
                $name = $record->data['name'] ?? null;
                $agent = $args['subagent_type'] ?? null;
                $command = $args['command'] ?? null;
                if ($name === 'Agent' && is_string($agent) && $agent !== '') {
                    $calls[$id] = [
                        'agent' => $agent,
                        'callAt' => $record->index,
                        'resultAt' => null,
                        'completed' => false,
                        'asyncLaunched' => false,
                        'isSidechain' => false,
                    ];
                } elseif ($name === 'Bash' && is_string($command) && PmGateHook::createsIssue($command)) {
                    $issueCalls[$id] = true;
                }
                continue;
            }

            if ($type === 'subagent.spawned') {
                $parent = $record->data['parentToolCallId'] ?? null;
                $task = $record->data['taskId'] ?? null;
                if (is_string($parent) && is_string($task) && $task !== '' && !isset($tasks[$parent])) {
                    $tasks[$parent] = $task;
                }
                continue;
            }

            if ($type === 'tool.result') {
                $id = $record->toolCallId;
                if ($id === null) {
                    continue;
                }
                $result = $record->data['result'] ?? null;
                $result = is_array($result) ? $result : [];
                $output = self::output($result['output'] ?? null);
                if (isset($calls[$id]) && !$calls[$id]['completed']) {
                    // Only the header block of the output is read (the model-written `[summary]` and the
                    // rest are untrusted), and a result the engine flags as an error never completes.
                    if (($result['isError'] ?? false) !== true && self::status($output) === 'completed') {
                        $calls[$id]['resultAt'] = $record->index;
                        $calls[$id]['completed'] = true;
                    }
                    if (!isset($tasks[$id])) {
                        $task = self::taskId($output);
                        if ($task !== null) {
                            $tasks[$id] = $task;
                        }
                    }
                }
                // An issue-creating call that did not fail opens the window of the next author message.
                if (isset($issueCalls[$id]) && ($result['isError'] ?? false) !== true) {
                    $lastCreate = $record->index;
                }
                continue;
            }

            if ($type === 'turn.prompt') {
                $origin = $record->data['origin'] ?? null;
                $origin = is_array($origin) ? $origin : [];
                if (($origin['kind'] ?? null) === 'user') {
                    if ($lastCreate !== null) {
                        $windowStart = $lastCreate;
                        $lastCreate = null;
                    }
                    continue;
                }
                if (($origin['kind'] ?? null) !== 'task' || ($origin['status'] ?? null) !== 'completed') {
                    continue;
                }
                $task = $origin['taskId'] ?? null;
                if (!is_string($task) || $task === '') {
                    continue;
                }
                foreach ($calls as $id => $call) {
                    if (!$call['completed'] && $call['callAt'] < $record->index && ($tasks[$id] ?? null) === $task) {
                        $calls[$id]['resultAt'] = $record->index;
                        $calls[$id]['completed'] = true;
                    }
                }
                continue;
            }
        }

        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => $call['callAt'] > $windowStart,
        ));
    }

    /**
     * The agent calls of a session, from the path of its main `wire.jsonl` or of its session directory
     * ({@see MAIN_WIRE} is appended to the latter). The whole file is read: this reader performs no
     * cutting of its own, and a caller that wants a run window cuts the lines itself — the byte offsets
     * of {@see KimiWireAudit::snapshot()} name where the window of a run starts.
     *
     * @return list<array{
     *     agent: string,
     *     callAt: int,
     *     resultAt: int|null,
     *     completed: bool,
     *     asyncLaunched: bool,
     *     isSidechain: bool,
     * }>
     *
     * @throws \RuntimeException when the main wire of the session is not available or cannot be read
     */
    public static function fromFile(string $path): array
    {
        $file = is_dir($path) ? rtrim($path, '/').'/'.self::MAIN_WIRE : $path;
        if ($file === '' || !is_file($file)) {
            throw new \RuntimeException('the main wire of the session is not available');
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('the main wire of the session cannot be read');
        }

        return self::agentCalls($lines);
    }

    /**
     * The text of a `tool.result` output: a sub-agent result is a string, the launch result of a
     * background sub-agent is the list of its text parts. Any other shape reads as no text at all,
     * which leaves the call without a status — and a call without a status never counts.
     */
    private static function output(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }
        if (!is_array($output)) {
            return '';
        }
        $text = '';
        foreach ($output as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * The header block of a sub-agent result output: the leading `key: value` lines, up to the first
     * empty line or the `[summary]` marker. Only this block carries the fields the reader trusts
     * (`status:`, `task_id:`); the text after it is the sub-agent's own (or the launch instructions),
     * which the model writes and which must not steer the reader.
     */
    private static function header(string $output): string
    {
        $header = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '[summary]')) {
                break;
            }
            $header[] = $line;
        }

        return implode("\n", $header);
    }

    /** The `status:` line of a sub-agent result header (`completed`, `running`, …), or null when it has none. */
    private static function status(string $output): ?string
    {
        return preg_match('/^\s*status:\s*(?<status>\S+)/m', self::header($output), $status) === 1 ? $status['status'] : null;
    }

    /** The task id a background launch reports in its header (`task_id: agent-…`), or null when it reports none. */
    private static function taskId(string $output): ?string
    {
        return preg_match('/^\s*task_id:\s*(?<task>\S+)/m', self::header($output), $task) === 1 ? $task['task'] : null;
    }
}
