<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Memory;

use GpsLab\WorkspaceAssistant\Memory\MemorySync;
use GpsLab\WorkspaceAssistant\Tests\StderrCaptureFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The background `memory-3layer pull` / `push` of the bot: it never blocks a run, it logs what went wrong
 * and it never lets two syncs run at the same time.
 */
#[CoversClass(MemorySync::class)]
final class MemorySyncTest extends TestCase
{
    private string $root;

    /** File the fake client appends every invocation to. */
    private string $calls;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/memory-sync-'.uniqid('', true);
        mkdir($this->root.'/state', 0775, true);
        mkdir($this->root.'/repo', 0775, true);
        $this->calls = $this->root.'/calls.txt';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testUnsetClientRunsNothing(): void
    {
        $sync = new MemorySync('', $this->root.'/state', $this->root.'/repo');

        self::assertFalse($sync->enabled());
        self::assertFalse($sync->start(MemorySync::PULL));
        self::assertFileDoesNotExist($this->root.'/state/'.MemorySync::LOCK_FILE);
    }

    public function testAMissingClientIsLoggedAndTheRunGoesOn(): void
    {
        $sync = new MemorySync($this->root.'/gone', $this->root.'/state', $this->root.'/repo');

        $started = '';
        $log = $this->capturedLog(function () use ($sync, &$started): void {
            $started = $sync->start(MemorySync::PULL) ? 'yes' : 'no';
        });

        self::assertSame('no', $started);
        self::assertStringContainsString('memory-3layer pull', $log);
        self::assertStringContainsString('could not be started', $log);
        // The lock is not left behind by a client that never started.
        self::assertNull($this->lockHolder());
    }

    public function testRunsTheClientInTheBackgroundAndReleasesTheLock(): void
    {
        $sync = $this->sync();
        self::assertTrue($sync->start(MemorySync::PULL));
        self::assertTrue($sync->isRunning());
        $this->wait($sync);

        self::assertSame(['pull'], $this->calls());
        // The lock is free again: the next sync of the instance runs.
        self::assertNull($this->lockHolder());
        self::assertTrue($sync->start(MemorySync::PUSH));
        $this->wait($sync);
        self::assertSame(['pull', 'push'], $this->calls());
    }

    /**
     * Push and pull share one lock: a second sync cannot run next to the first, but a verb of THIS
     * instance is deferred, not dropped, and runs as soon as the first one is reaped. A short run whose
     * pull is still in flight must still get its push out.
     */
    public function testASyncIsDeferredWhileAnotherOfThisInstanceRuns(): void
    {
        $sync = $this->sync(sleepMs: 700);
        self::assertTrue($sync->start(MemorySync::PULL));

        $second = '';
        $log = $this->capturedLog(function () use ($sync, &$second): void {
            $second = $sync->start(MemorySync::PUSH) ? 'yes' : 'no';
        });
        $this->wait($sync);

        self::assertSame('no', $second);
        self::assertStringContainsString('another sync is running, deferred', $log);
        // Both ran, in order: the pull finished, then the deferred push was started by the bot's poll().
        self::assertSame(['pull', 'push'], $this->calls());
    }

    /** A pull that arrives while a push is already deferred must not overwrite it: a push lost loses data. */
    public function testAPendingPushIsNotOverwrittenByANewPull(): void
    {
        $sync = $this->sync(sleepMs: 700);
        self::assertTrue($sync->start(MemorySync::PULL));
        self::assertFalse($sync->start(MemorySync::PUSH)); // deferred
        self::assertFalse($sync->start(MemorySync::PULL)); // must not clobber the deferred push

        $this->wait($sync);

        self::assertSame(['pull', 'push'], $this->calls());
    }

    /**
     * The lock is the lock FILE, not a flag of this object: a sync another process runs is skipped here
     * as well, and the flock is released by the system when the holder dies.
     */
    public function testASyncIsSkippedWhenAnotherProcessHoldsTheLock(): void
    {
        $lock = fopen($this->root.'/state/'.MemorySync::LOCK_FILE, 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $sync = $this->sync();

        try {
            $started = '';
            $log = $this->capturedLog(function () use ($sync, &$started): void {
                $started = $sync->start(MemorySync::PULL) ? 'yes' : 'no';
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame('no', $started);
        self::assertStringContainsString('another sync is running, skipped', $log);
        self::assertFileDoesNotExist($this->calls);
    }

    public function testAClientStillRunningAtTheTimeoutIsKilled(): void
    {
        $sync = $this->sync(sleepMs: 20000, timeoutSec: 1);

        $started = microtime(true);
        self::assertTrue($sync->start(MemorySync::PULL));
        $log = $this->capturedLog(fn () => $this->wait($sync));
        $elapsed = microtime(true) - $started;

        self::assertFalse($sync->isRunning());
        self::assertLessThan(5.0, $elapsed);
        self::assertStringContainsString('did not finish within 1s and was killed', $log);
        // A killed client does not keep the lock.
        self::assertNull($this->lockHolder());
    }

    public function testAFailedClientIsLoggedWithItsExitCodeAndItsOutput(): void
    {
        // A client that talks to both streams: two separate ['file', $log, 'w'] opens would truncate the
        // log at two offsets and one stream would overwrite the other, so both must survive here.
        $sync = $this->sync(exitCode: 3, stdout: 'Pulling…', stderr: 'the server refused the token');

        self::assertTrue($sync->start(MemorySync::PUSH));
        $log = $this->capturedLog(fn () => $this->wait($sync));

        self::assertStringContainsString('memory-3layer push', $log);
        self::assertStringContainsString('exited with code 3', $log);
        self::assertStringContainsString('state/'.MemorySync::LOG_FILE, $log);
        // What the client said is kept, not sent anywhere: it is the file the log line points at.
        $output = (string) file_get_contents($this->root.'/state/'.MemorySync::LOG_FILE);
        self::assertStringContainsString('Pulling…', $output);
        self::assertStringContainsString('the server refused the token', $output);
    }

    public function testASuccessfulSyncIsNotLogged(): void
    {
        $sync = $this->sync();

        self::assertTrue($sync->start(MemorySync::PULL));
        $log = $this->capturedLog(fn () => $this->wait($sync));

        self::assertSame('', $log);
        self::assertSame(['pull'], $this->calls());
    }

    /** poll() is called on every tick of the bot loop, with or without a sync in flight. */
    public function testPollWithoutAStartedSyncDoesNothing(): void
    {
        $sync = $this->sync();
        $sync->poll();
        $sync->poll();

        self::assertFalse($sync->isRunning());
        self::assertFileDoesNotExist($this->calls);
    }

    public function testTheClientRunsInTheManagedRepository(): void
    {
        $sync = $this->sync(body: 'file_put_contents('.var_export($this->calls, true).', getcwd()."\n", FILE_APPEND);');

        self::assertTrue($sync->start(MemorySync::PULL));
        $this->wait($sync);

        self::assertSame([$this->root.'/repo'], $this->calls());
    }

    private function sync(int $sleepMs = 0, int $timeoutSec = 30, int $exitCode = 0, string $stderr = '', string $stdout = '', ?string $body = null): MemorySync
    {
        if ($body === null) {
            $body = 'file_put_contents('.var_export($this->calls, true).', $argv[1]."\n", FILE_APPEND);'
                .($stdout !== '' ? ' fwrite(STDOUT, '.var_export($stdout, true).');' : '')
                .($stderr !== '' ? ' fwrite(STDERR, '.var_export($stderr, true).');' : '')
                .($sleepMs > 0 ? ' usleep('.($sleepMs * 1000).');' : '')
                .' exit('.$exitCode.');';
        }
        $bin = $this->root.'/memory-3layer';
        file_put_contents($bin, '#!'.PHP_BINARY."\n<?php\n".$body."\n");
        chmod($bin, 0o755);

        return new MemorySync($bin, $this->root.'/state', $this->root.'/repo', $timeoutSec);
    }

    /** @return list<string> every verb the client was called with, in order */
    private function calls(): array
    {
        if (!is_file($this->calls)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($this->calls)), static fn (string $l): bool => $l !== ''));
    }

    /** The client is reaped by the bot loop; a test does the same by polling. */
    private function wait(MemorySync $sync): void
    {
        for ($i = 0; $i < 500 && $sync->isRunning(); ++$i) {
            $sync->poll();
            usleep(10000);
        }
        self::assertFalse($sync->isRunning(), 'the sync did not finish');
    }

    /** Whether another process holds the lock file: null when it is free. */
    private function lockHolder(): ?bool
    {
        $path = $this->root.'/state/'.MemorySync::LOCK_FILE;
        if (!is_file($path)) {
            return null;
        }
        $lock = fopen($path, 'c');
        self::assertIsResource($lock);
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return $free ? null : true;
    }

    /**
     * Log::line writes to the STDERR constant, so a write filter is put on the stream around the call.
     *
     * @param \Closure(): void $run
     */
    private function capturedLog(\Closure $run): string
    {
        if (!in_array('stderr_capture', stream_get_filters(), true)) {
            stream_filter_register('stderr_capture', StderrCaptureFilter::class);
        }
        StderrCaptureFilter::$buffer = '';
        $filter = stream_filter_append(STDERR, 'stderr_capture', STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);
        try {
            $run();
        } finally {
            stream_filter_remove($filter);
        }

        return StderrCaptureFilter::$buffer;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
