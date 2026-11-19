<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

use GpsLab\WorkspaceAssistant\PmGateHook;

/**
 * The OpenCode half of the PM gate: it reads an OpenCode session-history file and turns it into the
 * same list of agent calls the neutral core of {@see PmGateHook} decides on, exactly as
 * {@see \GpsLab\WorkspaceAssistant\ClaudeCodeTranscriptReader} does for a Claude Code transcript.
 *
 * OpenCode runs every sub-agent in the background and its `subagent` tool call is `completed` at
 * launch, so a stage's result is a later `synthetic` message, matched to the call by the child
 * session id — the tool's own `completed` state never counts as a returned result, only the report
 * supplies the result position. The order of events is the array order of `messages`, never the
 * message `id` order.
 *
 * A `synthetic` report is not a real message of the author and does not open a new window; a `user`
 * message does. A `user` message opens the window only after an issue-creating `shell` call that
 * actually succeeded: `status` is `completed` for any shell that ran (also one that exited non-zero)
 * and success is read from an exit code of 0.
 */
final class OpencodeHistoryReader
{
    /**
     * @param array<mixed> $history a decoded OpenCode history ({@see messages()}: the bare `messages`
     *                              list of `ctx.session.context()`, or an object holding it under `messages`)
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
     * @throws \RuntimeException when the history is neither shape ({@see messages()})
     */
    public static function agentCalls(array $history): array
    {
        $messages = self::messages($history);

        /** @var array<string, array{agent: string, callAt: int, resultAt: int|null, completed: bool, asyncLaunched: bool, isSidechain: bool}> $calls */
        $calls = [];
        $windowStart = -1;
        $lastCreate = null;

        foreach ($messages as $at => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = $entry['type'] ?? null;

            // A real message of the author closes the window opened by a successful issue creation.
            if ($type === 'user') {
                if ($lastCreate !== null) {
                    $windowStart = $lastCreate;
                    $lastCreate = null;
                }
                continue;
            }

            // The result of a sub-agent is a synthetic report, matched to the call by the child id.
            if ($type === 'synthetic') {
                $metadata = $entry['metadata'] ?? null;
                if (!is_array($metadata) || ($metadata['source'] ?? null) !== 'subagent') {
                    continue;
                }
                $child = $metadata['childID'] ?? null;
                if (is_string($child) && isset($calls[$child])) {
                    $calls[$child]['resultAt'] = $at;
                    $calls[$child]['completed'] = ($metadata['state'] ?? null) === 'completed';
                }
                continue;
            }

            if ($type !== 'assistant') {
                continue;
            }

            foreach (self::blocks($entry) as $block) {
                if (($block['type'] ?? null) !== 'tool') {
                    continue;
                }
                $name = $block['name'] ?? null;
                $state = $block['state'] ?? null;
                if (!is_array($state)) {
                    continue;
                }

                if ($name === 'subagent') {
                    $agent = $state['input']['agent'] ?? null;
                    $sessionId = $state['metadata']['sessionID'] ?? null;
                    if (is_string($agent) && is_string($sessionId)) {
                        $calls[$sessionId] = [
                            'agent' => $agent,
                            'callAt' => $at,
                            'resultAt' => null,
                            'completed' => false,
                            'asyncLaunched' => false,
                            'isSidechain' => false,
                        ];
                    }
                } elseif ($name === 'shell') {
                    $command = $state['input']['command'] ?? null;
                    if (is_string($command) && ($state['status'] ?? null) === 'completed' && self::shellExitCode($state) === 0 && PmGateHook::createsIssue($command)) {
                        $lastCreate = $at;
                    }
                }
            }
        }

        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => $call['callAt'] > $windowStart,
        ));
    }

    /**
     * The `messages` list of a decoded history, of either shape it reaches this reader in:
     * `ctx.session.context()` returns the bare list (`opencode-plugin/index.js` writes it out
     * unchanged) and the fixtures of `tests/fixtures/opencode/history/` carry it under the probe's
     * `{sessionID, command, messages}` wrapper. Any other shape is an error, never an empty list:
     * `[]` means "no stage ran", which denies on `pm`/`tick`/`final` blaming the model and forces a
     * skip with a false reason on `review`/`epic_review`.
     *
     * @param array<mixed> $history
     *
     * @return list<mixed>
     *
     * @throws \RuntimeException when the history is neither a list nor an object with a `messages` list
     */
    private static function messages(array $history): array
    {
        if (array_is_list($history)) {
            return $history;
        }
        $messages = $history['messages'] ?? null;
        if (!is_array($messages)) {
            throw new \RuntimeException('history holds no messages list');
        }

        return array_values($messages);
    }

    /**
     * Reads a history file and returns its agent-call list ({@see agentCalls()}). The file holds either
     * shape of {@see messages()}: the bare list OpenCode returns, or the wrapper of the fixtures.
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
     * @throws \RuntimeException when the file is missing, does not hold valid JSON, or holds a history
     *                           of an unrecognised shape
     */
    public static function fromFile(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('history is not available');
        }
        $raw = (string) file_get_contents($path);
        $history = json_decode($raw, true);
        if (!is_array($history)) {
            throw new \RuntimeException('history is not valid JSON');
        }
        // `{}` and `[]` both decode to `[]` with `assoc`; only the non-associative decode keeps them
        // apart (an empty object vs an empty list). An empty object is a history of neither shape, so
        // it throws here instead of silently reading "no messages yet".
        if ($history === [] && json_decode($raw) instanceof \stdClass) {
            throw new \RuntimeException('history holds no messages list');
        }

        return self::agentCalls($history);
    }

    /**
     * The content blocks of an assistant message, filtering out non-array entries.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $entry): array
    {
        $content = $entry['content'] ?? null;

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    /**
     * The exit code of a completed shell tool call, or null when the history does not report one.
     *
     * A shell that ran always reports `status: "completed"`, also when it exited non-zero, so success
     * is read from the exit code. The session history flattens the tool state: where the `run
     * --format json` NDJSON holds `state.metadata.metadata.exit` (`run/plain.ndjson`), the history
     * holds it as `state.metadata.exit` — the sub-agent tool's `state.metadata` is likewise the flat
     * inner object in `history/stage-parallel.json`. Both shapes are read.
     *
     * @param array<string, mixed> $state
     */
    private static function shellExitCode(array $state): ?int
    {
        $metadata = $state['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }
        $exit = $metadata['exit'] ?? null;
        if ($exit === null) {
            $inner = $metadata['metadata'] ?? null;
            $exit = is_array($inner) ? ($inner['exit'] ?? null) : null;
        }
        if (is_int($exit)) {
            return $exit;
        }

        return is_string($exit) && preg_match('/^\d+$/', $exit) === 1 ? (int) $exit : null;
    }
}
