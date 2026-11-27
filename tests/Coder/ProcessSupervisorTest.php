<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Coder\ProcessSupervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessSupervisor::class)]
final class ProcessSupervisorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/coder-supervisor-'.uniqid('', true);
        mkdir($this->root.'/worktree', 0700, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testCrashRecoveryMatchesSupervisorByStartTimeAfterEngineExits(): void
    {
        /** @var array<string, mixed> $latest */
        $latest = [];
        $reconciled = null;
        $supervisor = new ProcessSupervisor();

        $status = $supervisor->run(
            fn (): FakeCoderRunner => new FakeCoderRunner($this->root.'/worktree', $this->root, $this->root.'/logs', 'crash'),
            'Crash fixture',
            null,
            'crash-run',
            $this->root.'/run',
            10,
            static function (array $update) use (&$latest): void {
                $latest = $update;
            },
            function () use (&$latest, &$reconciled): bool {
                $enginePid = $latest['engine_pid'] ?? null;
                if (($latest['phase'] ?? null) !== 'running' || !is_int($enginePid) || file_exists('/proc/'.$enginePid)) {
                    return false;
                }
                // No engine environment marker remains: only the forked supervisor can prove identity.
                $pgid = $latest['pgid'] ?? null;
                if (!is_int($pgid)) {
                    return false;
                }
                $reconciled = ProcessSupervisor::reconcile($pgid, 'crash-run', $latest);

                return true;
            },
        );

        self::assertTrue($reconciled, 'The surviving supervisor-only group must be reconciled without /proc/environ.');
        self::assertSame('cancelled', $status['phase']);
        $pgid = $status['pgid'] ?? null;
        self::assertIsInt($pgid);
        self::assertFalse($this->groupAlive($pgid));
    }

    private function groupAlive(int $pgid): bool
    {
        foreach (glob('/proc/[0-9]*') ?: [] as $path) {
            $pid = (int) basename($path);
            $stat = @file_get_contents($path.'/stat');
            if (!is_string($stat) || posix_getpgid($pid) !== $pgid) {
                continue;
            }
            $end = strrpos($stat, ')');
            $fields = $end === false ? [] : (preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: []);
            if (($fields[0] ?? '') !== 'Z') {
                return true;
            }
        }

        return false;
    }
}
