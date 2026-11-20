<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Keeps the evidence of a run that ended with an engine error: the raw event stream and the
 * stderr tail, both masked, plus the reason (exit code and last error) in the log.
 *
 * The files live in `STATE_DIR/failed-runs/` — outside `STATE_DIR/runs/` and
 * `STATE_DIR/agents/`, which {@see ClaudeRunner::purgeRunFiles()} wipes at bot start — so the
 * evidence outlives a restart. Directory 0700, files 0600.
 */
final class FailedRunEvidence
{
    /** Directory under STATE_DIR; deliberately outside the paths purged at bot start. */
    public const string DIR = 'failed-runs';

    /** Hard cap of retained failed runs, on top of the age limit. */
    public const int KEEP_FILES = 20;

    /** Bytes of the stderr tail stored in the evidence file. */
    public const int STDERR_TAIL_BYTES = 16384;

    /** Bytes of the event-stream tail stored in the evidence file (256 KiB). */
    public const int STREAM_TAIL_BYTES = 262144;

    /**
     * Logs the failure reason (masked) and stores the evidence of a failed run when there is a
     * state directory. Returns the evidence file path, or null when nothing was written.
     *
     * @param int $retentionDays age limit in days; 0 disables the age limit (the cap still applies)
     */
    public static function record(
        string $stateDir,
        string $engine,
        string $runId,
        int $exitCode,
        string $stream,
        string $stderr,
        string $lastError,
        int $retentionDays,
        ?SecretScanner $scanner = null,
    ): ?string {
        $scanner ??= SecretScanner::shared();
        // The log carries the reason only; tool output may hold secrets, so it is masked too.
        $reason = trim($scanner->mask($lastError));
        Log::line(sprintf(
            '%s run %s failed (exit %d)%s',
            $engine,
            $runId !== '' ? $runId : '(unknown)',
            $exitCode,
            $reason !== '' ? ': '.$reason : '',
        ));

        if ($stateDir === '') {
            return null;
        }

        $dir = rtrim($stateDir, '/').'/'.self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            Log::line('failed-run evidence: cannot create '.$dir);

            return null;
        }
        @chmod($dir, 0700);

        $id = $runId !== '' ? basename($runId) : date('Ymd-His').'-'.getmypid().'-'.bin2hex(random_bytes(4));
        $path = $dir.'/'.$id.'.log';
        // Cap the stream to its tail before masking: a long failed run (huge tool outputs, base64)
        // must not be masked whole, which blocks poll() and fills the disk.
        $streamTail = $stream !== '' && strlen($stream) > self::STREAM_TAIL_BYTES
            ? substr($stream, -self::STREAM_TAIL_BYTES)
            : $stream;
        $body = '== failed run =='."\n"
            .'engine: '.$engine."\n"
            .'run id: '.$id."\n"
            .'time: '.date('Y-m-d H:i:sP')."\n"
            .'exit code: '.$exitCode."\n"
            .'last error: '.$reason."\n"
            .'== event stream (last '.self::STREAM_TAIL_BYTES.' bytes, masked) =='."\n".$scanner->mask($streamTail)."\n"
            .'== stderr tail (last '.self::STDERR_TAIL_BYTES.' bytes, masked) =='."\n"
            .$scanner->mask($stderr !== '' ? substr($stderr, -self::STDERR_TAIL_BYTES) : '')."\n";

        $old = umask(0177);
        $written = @file_put_contents($path, $body);
        umask($old);
        if ($written === false) {
            Log::line('failed-run evidence: cannot write '.$path);

            return null;
        }
        @chmod($path, 0600);

        self::prune($dir, $retentionDays);

        return $path;
    }

    /** Drops evidence older than $retentionDays and everything beyond the newest KEEP_FILES. */
    public static function prune(string $dir, int $retentionDays, ?int $now = null): void
    {
        $files = glob($dir.'/*.log') ?: [];
        // Newest first, by mtime; the file name (a `Ymd-His` timestamp) is only the tie-breaker.
        usort($files, static function (string $a, string $b): int {
            $byMtime = ((int) @filemtime($b)) <=> ((int) @filemtime($a));

            return $byMtime !== 0 ? $byMtime : strcmp(basename($b), basename($a));
        });

        $cutoff = $retentionDays > 0 ? ($now ?? time()) - $retentionDays * 86400 : null;
        foreach ($files as $index => $file) {
            $tooOld = $cutoff !== null && (int) @filemtime($file) < $cutoff;
            if ($index >= self::KEEP_FILES || $tooOld) {
                @unlink($file);
            }
        }
    }
}
