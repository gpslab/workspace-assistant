<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * The engine-specific half of the PM gate: it reads the Claude Code transcript (the `transcript_path`
 * JSONL a run hands to the hook) and turns it into the list of `Agent` calls the neutral core of
 * {@see PmGateHook} decides on. A second engine adds its own reader that produces the same list
 * (see "OpenCode session-history reader"); the core does not change.
 *
 * Each returned call carries its {@see $callAt} position in the transcript, the position of its
 * result and the flags the counting criterion needs. The window is applied here: only calls after
 * the last successful issue creation that was followed by a real message of the author are
 * returned, so the neutral core never sees a transcript line.
 */
final class ClaudeCodeTranscriptReader
{
    /**
     * @param list<string> $transcriptLines JSONL transcript lines, as passed to the hook
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
    public static function agentCalls(array $transcriptLines): array
    {
        /** @var array<string, array{agent: string, callAt: int, resultAt: int|null, completed: bool, asyncLaunched: bool, isSidechain: bool}> $calls */
        $calls = [];
        /** @var array<string, bool> $issueCalls tool_use id => true */
        $issueCalls = [];
        $windowStart = -1;
        $lastCreate = null;

        foreach ($transcriptLines as $at => $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            $sidechain = ($entry['isSidechain'] ?? false) === true;
            if (!$sidechain && ReviewStatusHook::isRealUserMessage($entry)) {
                if ($lastCreate !== null) {
                    $windowStart = $lastCreate;
                    $lastCreate = null;
                }
                continue;
            }
            foreach (self::blocks($entry) as $block) {
                $type = $block['type'] ?? null;
                $id = (string) ($block['id'] ?? $block['tool_use_id'] ?? '');
                if ($type === 'tool_use' && ($entry['type'] ?? null) === 'assistant') {
                    if (($block['name'] ?? null) === 'Agent' && is_string($block['input']['subagent_type'] ?? null)) {
                        $calls[$id] = [
                            'agent' => $block['input']['subagent_type'],
                            'callAt' => $at,
                            'resultAt' => null,
                            'completed' => false,
                            'asyncLaunched' => false,
                            'isSidechain' => $sidechain,
                        ];
                    } elseif (!$sidechain && ($block['name'] ?? null) === 'Bash' && is_string($block['input']['command'] ?? null) && PmGateHook::createsIssue($block['input']['command'])) {
                        $issueCalls[$id] = true;
                    }
                } elseif ($type === 'tool_result' && ($entry['type'] ?? null) === 'user') {
                    $result = $entry['toolUseResult'] ?? null;
                    if (is_array($result) && is_string($result['status'] ?? null) && isset($calls[$id])) {
                        $calls[$id]['resultAt'] = $at;
                        $calls[$id]['completed'] = $result['status'] === 'completed';
                        $calls[$id]['asyncLaunched'] = $result['status'] === 'async_launched';
                    }
                    if (!$sidechain && isset($issueCalls[$id]) && ($block['is_error'] ?? false) !== true) {
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
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $entry): array
    {
        $content = $entry['message']['content'] ?? null;

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }
}
