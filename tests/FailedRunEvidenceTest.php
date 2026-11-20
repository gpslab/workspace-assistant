<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\FailedRunEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailedRunEvidence::class)]
final class FailedRunEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/failed-run-evidence-'.uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testRecordCreatesPrivateMaskedEvidence(): void
    {
        $token = 'ghp_'.str_repeat('C', 36);

        $path = FailedRunEvidence::record(
            $this->root,
            'claude',
            '20260101-120000-1-abcd',
            9,
            'stream '.$token,
            'tail '.$token,
            'failed '.$token,
            14,
        );

        self::assertNotNull($path);
        $dir = $this->root.'/'.FailedRunEvidence::DIR;
        self::assertSame(0700, fileperms($dir) & 0777);
        self::assertSame(0600, fileperms($path) & 0777);

        $body = (string) file_get_contents($path);
        self::assertStringContainsString('exit code: 9', $body);
        self::assertStringContainsString('stream', $body);
        self::assertStringContainsString('tail', $body);
        self::assertStringNotContainsString($token, $body);
        self::assertSame(3, substr_count($body, '[REDACTED]'));
    }

    public function testPruneDropsEvidenceOlderThanTheRetention(): void
    {
        $dir = $this->root.'/'.FailedRunEvidence::DIR;
        mkdir($dir, 0700, true);
        $now = 1_700_000_000;

        $old = $dir.'/20250101-120000-1-abcd.log';
        $fresh = $dir.'/20260101-120000-1-abcd.log';
        touch($old, $now - 20 * 86400);
        touch($fresh, $now - 86400);

        FailedRunEvidence::prune($dir, 14, $now);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
    }

    public function testPruneKeepsTheNewestTwenty(): void
    {
        $dir = $this->root.'/'.FailedRunEvidence::DIR;
        mkdir($dir, 0700, true);
        $now = 1_700_000_000;

        $paths = [];
        for ($i = 0; $i < 25; ++$i) {
            $path = $dir.'/20260101-12'.sprintf('%02d', $i).'00-1-abcd.log';
            touch($path, $now - $i * 60);
            $paths[$i] = $path;
        }

        FailedRunEvidence::prune($dir, 14, $now);

        self::assertCount(FailedRunEvidence::KEEP_FILES, glob($dir.'/*.log') ?: []);
        // The newest 20 survive; the 5 oldest are dropped. Which ones, not just how many: an
        // inverted comparator that deletes the newest files would still leave 20 behind.
        for ($i = 0; $i < 20; ++$i) {
            self::assertFileExists($paths[$i], 'newest file '.$i.' must survive');
        }
        for ($i = 20; $i < 25; ++$i) {
            self::assertFileDoesNotExist($paths[$i], 'oldest file '.$i.' must be pruned');
        }
    }

    public function testPruneWithZeroDaysKeepsOnlyTheCap(): void
    {
        $dir = $this->root.'/'.FailedRunEvidence::DIR;
        mkdir($dir, 0700, true);
        $now = 1_700_000_000;

        $paths = [];
        for ($i = 0; $i < 22; ++$i) {
            $path = $dir.'/20260101-12'.sprintf('%02d', $i).'00-1-abcd.log';
            touch($path, $now - $i * 86400);
            $paths[$i] = $path;
        }

        FailedRunEvidence::prune($dir, 0, $now);

        self::assertCount(FailedRunEvidence::KEEP_FILES, glob($dir.'/*.log') ?: []);
        // Only the cap drops files here (the age limit is off), so the exact set separates the
        // cap from the age limit: the newest 20 survive, the 2 oldest are dropped.
        for ($i = 0; $i < 20; ++$i) {
            self::assertFileExists($paths[$i], 'newest file '.$i.' must survive');
        }
        for ($i = 20; $i < 22; ++$i) {
            self::assertFileDoesNotExist($paths[$i], 'oldest file '.$i.' must be pruned by the cap');
        }
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
