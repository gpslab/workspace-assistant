<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The audit of one run of a Kimi session: the `tool.call`s the run left in the session's wire files
 * against the hook records of that run, so that a hook which did not run is noticed after the run.
 * Kimi's hooks fail open — only exit 2 blocks a call; exit 0, 1, 255, death by signal, a timeout and a
 * missing hook binary all let the call pass — so a `tool.call` without a hook record is the trace of a
 * gate that was never asked.
 *
 * The wire files of a session are append-only and cumulative across its runs, so the comparison is
 * limited to a **run window**: {@see snapshot()} records the byte size of every `agents/<agent>/wire.jsonl`
 * of the session before the run is launched and {@see compare()} reads each file from its recorded
 * offset only. A file the snapshot does not hold — a sub-agent that appeared during the run — is read
 * from 0.
 *
 * The invariant is symmetric: the set of `tool_call_id` of the window's `tool.call`s over all
 * `agents/<agent>/wire.jsonl` equals the set of `tool_call_id` of the run's hook records. The comparison is
 * by set, never by order or counts: `PreToolUse` and `PostToolUse` both record the same id, and a
 * sub-agent's tool call is in its own wire file while its hook record carries no agent id.
 *
 * Both methods read the wire files and write nothing. What a difference means is not decided here: a
 * loud violation on a gated run and a log line on a chat run are the caller's business.
 */
final class KimiWireAudit
{
    /** Every wire file of a session, relative to the session directory. */
    private const string WIRE_GLOB = 'agents/*/wire.jsonl';

    /**
     * The byte offsets of the wire files of a session, taken before a run is launched and passed to
     * {@see compare()} after it.
     *
     * A size that cannot be read counts as 0, which widens the window of that file to the whole file:
     * the audit may then report a difference that is not one (a loud false violation), never miss one.
     *
     * @return array<string, int> path relative to the session directory => byte size
     *
     * @throws \RuntimeException when the session directory is not available
     */
    public static function snapshot(string $sessionDir): array
    {
        $window = [];
        foreach (self::wireFiles($sessionDir) as $relative => $path) {
            $size = filesize($path);
            $window[$relative] = $size === false ? 0 : $size;
        }

        return $window;
    }

    /**
     * The differences between the window's `tool.call`s and the run's hook records, both ways.
     *
     * @param array<string, int>         $window      the offsets of {@see snapshot()}: path relative to the session directory => byte offset
     * @param list<array<string, mixed>> $hookRecords the decoded records of the run's hook records file; a record without a `tool_call_id` (a session
     *                                                or sub-agent lifecycle event) is not a tool call and is ignored
     *
     * @throws \RuntimeException when the session directory is not available or a wire file cannot be read
     */
    public static function compare(string $sessionDir, array $window, array $hookRecords): KimiWireAuditResult
    {
        /** @var array<string, true> $calls the tool call ids of the window, as a set */
        $calls = [];
        foreach (self::wireFiles($sessionDir) as $relative => $path) {
            $offset = max(0, $window[$relative] ?? 0);
            foreach (KimiWireReader::records(self::windowLines($path, $offset)) as $record) {
                if ($record->type === 'tool.call' && $record->toolCallId !== null) {
                    $calls[$record->toolCallId] = true;
                }
            }
        }

        /** @var array<string, true> $records the tool call ids of the hook records, as a set */
        $records = [];
        foreach ($hookRecords as $record) {
            $id = $record['tool_call_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $records[$id] = true;
            }
        }

        $callsWithoutRecords = array_keys(array_diff_key($calls, $records));
        $recordsWithoutCalls = array_keys(array_diff_key($records, $calls));
        sort($callsWithoutRecords);
        sort($recordsWithoutCalls);

        return new KimiWireAuditResult($callsWithoutRecords, $recordsWithoutCalls);
    }

    /**
     * The wire files of a session, keyed by their path relative to the session directory.
     *
     * @return array<string, string> relative path => path
     *
     * @throws \RuntimeException when the session directory is not available
     */
    private static function wireFiles(string $sessionDir): array
    {
        $dir = rtrim($sessionDir, '/');
        if ($dir === '' || !is_dir($dir)) {
            throw new \RuntimeException('the session directory is not available');
        }
        $files = [];
        foreach (glob($dir.'/'.self::WIRE_GLOB) ?: [] as $path) {
            $files['agents/'.basename(dirname($path)).'/wire.jsonl'] = $path;
        }
        ksort($files);

        return $files;
    }

    /**
     * The lines of a wire file from a byte offset: the window of a run, whose first line starts right
     * after the last complete line of the previous run. An offset past the end of the file leaves an
     * empty window; a truncated line is skipped by {@see KimiWireReader::records()}.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when the file cannot be read
     */
    private static function windowLines(string $path, int $offset): array
    {
        $content = file_get_contents($path, false, null, $offset);
        if ($content === false) {
            throw new \RuntimeException('cannot read '.$path);
        }

        return array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }
}
