<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Memory;

use GpsLab\WorkspaceAssistant\Memory\MemoryContext;
use GpsLab\WorkspaceAssistant\Tests\StderrCaptureFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The memory block of an engine that reads no user scope, against a fake `memory-3layer` client: the bot
 * reads the files the client names and injects their content, never the paths, and never at the cost of a run.
 */
#[CoversClass(MemoryContext::class)]
final class MemoryContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/memory-context-'.uniqid('', true);
        mkdir($this->root.'/memory', 0775, true);
        mkdir($this->root.'/repo', 0775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testUnsetClientInjectsNothing(): void
    {
        $context = new MemoryContext('', $this->root.'/repo', 1024);

        self::assertFalse($context->enabled());
        self::assertSame('', $context->block());
    }

    public function testAZeroCapTurnsTheInjectionOff(): void
    {
        $bin = $this->client('echo "'.$this->memoryFile('SESSION.md', 'memory').'";');
        $context = new MemoryContext($bin, $this->root.'/repo', 0);

        self::assertFalse($context->enabled());
        self::assertSame('', $context->block());
    }

    /**
     * The client names PATHS (that is what `memory-3layer context` prints) and the run gets the CONTENT:
     * an OpenCode run has no access to the data directory of memory-3layer, so a path would be worthless.
     */
    public function testInjectsTheContentOfTheNamedFilesAndNeverThePaths(): void
    {
        $identity = $this->memoryFile('IDENTITY.md', 'I am the instance identity.');
        $session = $this->memoryFile('SESSION.md', 'The session works on issue #188.');
        $bin = $this->client('echo "L0: '.$identity.'\n"; echo "session: '.$session.'\n";');

        $block = $this->block($bin);

        self::assertStringContainsString('I am the instance identity.', $block);
        self::assertStringContainsString('The session works on issue #188.', $block);
        self::assertStringContainsString('### IDENTITY.md', $block);
        self::assertStringContainsString('### SESSION.md', $block);
        self::assertStringNotContainsString($this->root, $block);
    }

    /** The client runs in the managed repository: the memory of a project is resolved from there. */
    public function testTheClientRunsInTheManagedRepository(): void
    {
        file_put_contents($this->root.'/repo/PROJECT.md', 'the memory of the managed project');
        $bin = $this->client('echo getcwd()."/PROJECT.md";');

        self::assertStringContainsString('the memory of the managed project', $this->block($bin));
    }

    public function testAFileLargerThanTheCapIsInjectedFromItsNewestEnd(): void
    {
        $this->memoryFile('SESSION.md', str_repeat('A', 500).'THE-NEWEST-PART');
        $bin = $this->client('echo "'.$this->root.'/memory/SESSION.md";');

        $block = $this->block($bin, maxBytes: 20);

        self::assertStringContainsString('THE-NEWEST-PART', $block);
        self::assertStringNotContainsString(str_repeat('A', 30), $block);
        self::assertStringContainsString('### SESSION.md (cut: the newest part only)', $block);
    }

    public function testAFileWithinTheCapIsInjectedWholeAndUnmarked(): void
    {
        $bin = $this->client('echo "'.$this->memoryFile('SESSION.md', 'short and complete').'";');

        $block = $this->block($bin);

        self::assertStringContainsString('### SESSION.md', $block);
        self::assertStringContainsString('short and complete', $block);
        self::assertStringNotContainsString('cut: the newest part only', $block);
    }

    /** A missing binary is a line in the log, not an exception in the middle of a run. */
    public function testAMissingClientIsLoggedAndInjectsNothing(): void
    {
        $block = '';
        $log = $this->capturedLog(function () use (&$block): void {
            $block = $this->block($this->root.'/gone');
        });

        self::assertSame('', $block);
        self::assertStringContainsString('memory-3layer context', $log);
        self::assertStringContainsString('could not be started', $log);
    }

    public function testAClientThatFailsIsLoggedAndInjectsNothing(): void
    {
        $bin = $this->client('fwrite(STDERR, "no project here"); exit(3);');

        $block = '';
        $log = $this->capturedLog(function () use (&$block, $bin): void {
            $block = $this->block($bin);
        });

        self::assertSame('', $block);
        self::assertStringContainsString('memory-3layer context', $log);
        self::assertStringContainsString('exited with code 3', $log);
        self::assertStringContainsString('no project here', $log);
    }

    /**
     * A hung client must not hold a run: the call is bounded, the client is killed at the timeout and the
     * run goes on without memory.
     */
    public function testAHungClientIsKilledAtTheTimeoutAndTheRunGoesOn(): void
    {
        $bin = $this->client('usleep(30000000); echo "'.$this->memoryFile('SESSION.md', 'too late').'";');

        $block = '';
        $started = microtime(true);
        $log = $this->capturedLog(function () use (&$block, $bin): void {
            $block = $this->block($bin, timeoutSec: 1);
        });
        $elapsed = microtime(true) - $started;

        self::assertSame('', $block);
        self::assertLessThan(5.0, $elapsed);
        self::assertStringContainsString('and was killed', $log);
    }

    /** A project without memory is normal: nothing named, or nothing readable, is not a warning. */
    public function testPathsThatAreNotReadableFilesAreSkippedWithoutAWarning(): void
    {
        $bin = $this->client('echo "'.$this->memoryFile('SESSION.md', 'the memory that exists').'\n"; echo "'.$this->root.'/memory/ABSENT-ONE.md\n";');

        $block = '';
        $log = $this->capturedLog(function () use (&$block, $bin): void {
            $block = $this->block($bin);
        });

        self::assertStringContainsString('the memory that exists', $block);
        self::assertStringNotContainsString('ABSENT-ONE', $block);
        self::assertSame('', $log);
    }

    public function testNothingNamedGivesNoBlockAndNoWarning(): void
    {
        $block = 'not empty';
        $log = $this->capturedLog(function () use (&$block): void {
            $block = $this->block($this->client('echo "";'));
        });

        self::assertSame('', $block);
        self::assertSame('', $log);
    }

    /** The client may report its data directory as `~/...`; the bot expands that itself. */
    public function testAHomeRelativePathIsExpanded(): void
    {
        $this->memoryFile('IDENTITY.md', 'identity from home');
        $bin = $this->client('echo "L0: ~/IDENTITY.md";');
        $home = getenv('HOME');
        putenv('HOME='.$this->root.'/memory');
        try {
            $block = $this->block($bin);
        } finally {
            putenv($home === false ? 'HOME' : 'HOME='.$home);
        }

        self::assertStringContainsString('identity from home', $block);
    }

    private function memoryFile(string $name, string $content): string
    {
        $path = $this->root.'/memory/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /** A fake `memory-3layer`: an executable script with the given PHP body. */
    private function client(string $body): string
    {
        $bin = $this->root.'/memory-3layer';
        file_put_contents($bin, '#!'.PHP_BINARY."\n<?php\n".$body."\n");
        chmod($bin, 0o755);

        return $bin;
    }

    private function block(string $bin, int $maxBytes = 4096, int $timeoutSec = 5): string
    {
        return (new MemoryContext($bin, $this->root.'/repo', $maxBytes, $timeoutSec))->block();
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
