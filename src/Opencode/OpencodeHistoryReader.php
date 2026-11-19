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
 * message does.
 */
final class OpencodeHistoryReader
{
    /**
     * @param array<string, mixed> $history a decoded OpenCode history (its `messages` list is read)
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
    public static function agentCalls(array $history): array
    {
        $messages = $history['messages'] ?? null;
        if (!is_array($messages)) {
            return [];
        }

        /** @var array<string, array{agent: string, callAt: int, resultAt: int|null, completed: bool, asyncLaunched: bool, isSidechain: bool}> $calls */
        $calls = [];
        $windowStart = -1;
        $lastCreate = null;

        foreach (array_values($messages) as $at => $entry) {
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
                    if (is_string($command) && ($state['status'] ?? null) === 'completed' && PmGateHook::createsIssue($command)) {
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
     * Reads a history file and returns its agent-call list ({@see agentCalls()}).
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
     * @throws \RuntimeException when the file is missing or does not hold a valid JSON object
     */
    public static function fromFile(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('history is not available');
        }
        $history = json_decode((string) file_get_contents($path), true);
        if (!is_array($history)) {
            throw new \RuntimeException('history is not valid JSON');
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
}
