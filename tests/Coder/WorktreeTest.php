<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Coder\Process;
use GpsLab\WorkspaceAssistant\Coder\Worktree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Worktree::class)]
#[CoversClass(Process::class)]
final class WorktreeTest extends TestCase
{
    private string $root;
    private string $repository;
    private string $baseHead;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/coder-worktree-'.uniqid('', true);
        $this->repository = $this->root.'/repository';
        mkdir($this->repository, 0700, true);
        file_put_contents($this->repository.'/README.md', "base\n");
        $this->git($this->repository, ['init', '-q']);
        $this->git($this->repository, ['config', 'user.name', 'Repository Owner']);
        $this->git($this->repository, ['config', 'user.email', 'owner@example.test']);
        $this->git($this->repository, ['add', '-A']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Initial fixture']);
        $this->baseHead = $this->git($this->repository, ['rev-parse', 'HEAD']);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testWorktreeRootMustBeOutsideManagedRepository(): void
    {
        $worktrees = new Worktree($this->repository, $this->repository.'/.coder-worktrees', 'Coder Author', 'coder@example.test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the managed repository');
        $worktrees->prepare(6);
    }

    public function testManagedRepositoryMustNotBeInsideWorktreeRoot(): void
    {
        $worktrees = new Worktree($this->repository, $this->root, 'Coder Author', 'coder@example.test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the managed repository');
        $worktrees->prepare(6);
    }

    public function testFirstAndResumedCommitsKeepTheConfiguredAuthorWithoutHistoryRewrites(): void
    {
        $worktrees = new Worktree($this->repository, $this->root.'/isolated', 'Coder Author', 'coder@example.test');
        $prepared = $worktrees->prepare(7);
        $path = $prepared['path'];
        file_put_contents($path.'/first.txt', "first\n");
        $first = $worktrees->commit($path, 7)['head'];
        self::assertSame($this->baseHead, $this->git($path, ['rev-parse', $first.'^']));

        // A stale local identity must be corrected before the resumed commit, not by rewriting history.
        $this->git($path, ['config', 'user.name', 'Wrong Author']);
        $this->git($path, ['config', 'user.email', 'wrong@example.test']);
        file_put_contents($path.'/second.txt', "second\n");
        $resumed = $worktrees->prepare(7, $prepared['base_head']);
        self::assertFalse($resumed['created']);
        self::assertSame('Coder Author', $this->git($path, ['config', 'user.name']));
        $second = $worktrees->commit($path, 7)['head'];

        self::assertSame($first, $this->git($path, ['rev-parse', $second.'^']));
        self::assertSame($first, $this->git($path, ['rev-parse', 'HEAD~1']));
        self::assertSame(
            ['Coder Author', 'Coder Author'],
            explode("\n", $this->git($path, ['log', '-2', '--format=%an'])),
        );
        self::assertSame(
            ['coder@example.test', 'coder@example.test'],
            explode("\n", $this->git($path, ['log', '-2', '--format=%ae'])),
        );
        self::assertSame($this->baseHead, $this->git($this->repository, ['rev-parse', 'HEAD']));
        self::assertSame('', $this->git($this->repository, ['status', '--porcelain']));
    }

    public function testUnexpectedExistingAuthorFailsCrashReconciliation(): void
    {
        $worktrees = new Worktree($this->repository, $this->root.'/isolated', 'Coder Author', 'coder@example.test');
        $prepared = $worktrees->prepare(8);
        $path = $prepared['path'];
        file_put_contents($path.'/foreign.txt', "foreign\n");
        $this->git($path, ['add', '-A']);
        $this->git($path, ['-c', 'user.name=Foreign Author', '-c', 'user.email=foreign@example.test', 'commit', '-q', '-m', 'Foreign commit']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected author');
        $worktrees->reconcile($path, $prepared['branch'], $prepared['base_head']);
    }

    /** @param list<string> $arguments */
    private function git(string $cwd, array $arguments): string
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $result = Process::run(['git', ...$arguments], $cwd, 30, $environment);
        self::assertSame(0, $result['exit'], $result['stderr'].$result['stdout']);

        return trim($result['stdout']);
    }
}
