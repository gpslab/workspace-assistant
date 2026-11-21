<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Memory;

use GpsLab\WorkspaceAssistant\Log;

/**
 * The out-of-band synchronization of the instance's memory (`memory-3layer pull` / `push`).
 *
 * Pull refreshes the memory data for the NEXT run (the current one already has its prompt) and push
 * uploads whatever the run wrote. Both run in the background while the bot keeps serving Telegram: they
 * are started and then reaped by {@see poll()} in the bot loop, so neither can delay a run, and a client
 * that fails or hangs only produces a line in the log — the run itself is never affected. A client still
 * running after {@see self::DEFAULT_TIMEOUT_SEC} is killed.
 *
 * One sync at a time for the whole instance: the lock file in the state directory is taken with a
 * non-blocking flock before the client starts and released once it is gone, so a second sync (another bot
 * process, a leftover client) is skipped instead of running next to the first. A sync this instance wanted
 * to start while its own client still runs is deferred, not dropped: the verb is kept in a pending slot and
 * {@see poll()} starts it as soon as the client is reaped, because a run that ends sooner than its pull
 * must still get its push out.
 *
 * The client is off unless `MEMORY_3LAYER_BIN` is set ({@see \GpsLab\WorkspaceAssistant\Config::memoryBin()}):
 * with it unset every method does nothing at all.
 */
final class MemorySync
{
    public const string PULL = 'pull';
    public const string PUSH = 'push';

    /** Lock of the instance: at most one sync process at a time, whichever verb it runs. */
    public const string LOCK_FILE = 'memory-3layer.lock';

    /** Output of the last sync (diagnostics of a failure); only one sync runs at a time, so it does not interleave. */
    public const string LOG_FILE = 'memory-3layer.log';

    /** A client running longer than this is killed: neither a run nor the bot waits for memory. */
    public const int DEFAULT_TIMEOUT_SEC = 120;

    /** @var resource|null the client of the sync in progress */
    private $proc;

    /** @var resource|null the held lock file handle, released with the client */
    private $lock;

    private string $verb = '';

    /** @var string|null the verb deferred while a client of this instance still runs; started by {@see poll()} */
    private ?string $pendingVerb = null;

    private float $startedAt = 0.0;

    /**
     * @param string $bin        path of the `memory-3layer` client; '' turns the feature off
     * @param string $stateDir   directory of the lock and of the log of the last sync
     * @param string $repoPath   working directory of the client: it syncs the memory of that project
     * @param int    $timeoutSec ceiling of one sync before the client is killed
     */
    public function __construct(
        private readonly string $bin,
        private readonly string $stateDir,
        private readonly string $repoPath,
        private readonly int $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
    ) {
    }

    /** Whether the feature is on for this instance. */
    public function enabled(): bool
    {
        return $this->bin !== '';
    }

    /** Whether a sync of this instance is still running. */
    public function isRunning(): bool
    {
        return is_resource($this->proc);
    }

    /**
     * Starts one sync in the background: {@see self::PULL} or {@see self::PUSH}. Returns false when the
     * feature is off, a sync is already running (this one or another process) or the client could not be
     * started. Never waits for the client and never throws.
     */
    public function start(string $verb): bool
    {
        // A finished client is reaped first: it must not keep the lock, and a crash of the bot that
        // left the log behind must not make the next sync look busy.
        $this->reap();
        if (!$this->enabled()) {
            return false;
        }
        if ($this->isRunning()) {
            // The run just ended while the pull it started is still in flight: the verb is deferred,
            // not dropped, and poll() starts it once the client is reaped. A pending push always wins
            // over a pending pull — a dropped pull is a refresh the next run repeats, a dropped push
            // loses the memory the run wrote.
            if ($this->pendingVerb !== self::PUSH) {
                $this->pendingVerb = $verb;
            }
            Log::line('memory-3layer '.$verb.': another sync is running, deferred until it finishes');

            return false;
        }

        $lock = $this->acquireLock($verb);
        if ($lock === null) {
            return false;
        }

        $log = $this->stateDir.'/'.self::LOG_FILE;
        // No pipes: the client writes its output to the log file itself, so nothing can fill up and
        // block it, and the bot has no stream to serve while the run goes on.
        $stdout = ['file', '/dev/null', 'w'];
        $fh = @fopen($log, 'w');
        if ($fh !== false) {
            // One handle for stdout and stderr alike: two separate ['file', $log, 'w'] opens would
            // truncate the log at two offsets so the streams overwrite each other.
            $stdout = $fh;
        }
        $proc = @proc_open(
            [$this->bin, $verb],
            [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stdout],
            $pipes,
            $this->repoPath,
        );
        if ($fh !== false) {
            fclose($fh);
        }
        if (!is_resource($proc)) {
            $this->release($lock);
            Log::line('memory-3layer '.$verb.': the client '.$this->bin.' could not be started; the run continues without it');

            return false;
        }

        $this->proc = $proc;
        $this->lock = $lock;
        $this->verb = $verb;
        $this->startedAt = microtime(true);

        return true;
    }

    /**
     * One step of the sync in progress: reaps the client when it is gone, kills it at the timeout,
     * releases the lock and then starts a deferred verb, if one is waiting. A no-op when no client of
     * this instance runs and nothing is deferred — the bot calls it on every tick.
     */
    public function poll(): void
    {
        // A deferred verb only starts once a client has actually been reaped here; while the client
        // still runs there is nothing to reclaim the lock for, so the verb keeps waiting without
        // re-logging on every tick.
        if (!$this->reap() || $this->pendingVerb === null) {
            return;
        }
        $verb = $this->pendingVerb;
        $this->pendingVerb = null;
        $this->start($verb);
    }

    /**
     * Reaps a finished or timed-out client and releases its lock. Returns true only when a client was
     * reaped (the lock is free now); false when none of this instance runs or it is still running.
     * Kept separate from {@see poll()} so that {@see start()} can reap a leftover client without also
     * kicking off a deferred verb out of order.
     */
    private function reap(): bool
    {
        $proc = $this->proc;
        if (!is_resource($proc)) {
            $this->release($this->lock);
            $this->lock = null;

            return false;
        }

        $status = proc_get_status($proc);
        $timeout = max(1, $this->timeoutSec);
        if ($status['running'] === true && microtime(true) - $this->startedAt < $timeout) {
            return false;
        }
        $timedOut = $status['running'] === true;
        if ($timedOut) {
            proc_terminate($proc, 9); // SIGKILL
            usleep(20000);
            $status = proc_get_status($proc);
        }
        proc_close($proc);
        $this->proc = null;
        $this->release($this->lock);
        $this->lock = null;

        $verb = $this->verb;
        $this->verb = '';
        if ($timedOut) {
            Log::line('memory-3layer '.$verb.': the client did not finish within '.$timeout.'s and was killed; the next run syncs again');
        } elseif ((int) $status['exitcode'] !== 0) {
            Log::line('memory-3layer '.$verb.': the client exited with code '.(int) $status['exitcode']
                .'; see '.$this->stateDir.'/'.self::LOG_FILE);
        }

        return true;
    }

    /**
     * Takes the sync lock for this instance, or returns null when another sync holds it (or the lock file
     * cannot be used). The lock is an flock of a file in the state directory: it is released by the system
     * even when the bot dies, so a crash cannot block the syncs forever.
     *
     * @return resource|null
     */
    private function acquireLock(string $verb)
    {
        $path = $this->stateDir.'/'.self::LOCK_FILE;
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            Log::line('memory-3layer '.$verb.': the lock file '.$path.' cannot be opened; the run continues without a sync');

            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            Log::line('memory-3layer '.$verb.': another sync is running, skipped');

            return null;
        }

        return $lock;
    }

    /** @param resource|null $lock */
    private function release($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
