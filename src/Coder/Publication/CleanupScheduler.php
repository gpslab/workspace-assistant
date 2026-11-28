<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Store;

/** Records only a validated deterministic cleanup target; deletion belongs to a later janitor. */
final readonly class CleanupScheduler
{
    public function __construct(
        private Store $store,
        private string $repository,
        private string $worktreeRoot,
    ) {
    }

    public function schedule(int $jobId, string $path, ?int $now = null): void
    {
        $root = realpath($this->worktreeRoot);
        $repository = realpath($this->repository);
        if ($root === false || $repository === false || $path !== $root.'/job-'.$jobId) {
            throw new \RuntimeException('Coder cleanup rejected: the worktree is not the deterministic job path.');
        }
        if (self::inside($root, $repository) || self::inside($repository, $root)) {
            throw new \RuntimeException('Coder cleanup rejected: worktrees and the managed repository overlap.');
        }
        if (!$this->store->scheduleCleanup($jobId, $path, $now)) {
            throw new \RuntimeException('Coder cleanup can be scheduled only once after a terminal transition.');
        }
    }

    private static function inside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with(rtrim($path, '/').'/', rtrim($parent, '/').'/');
    }
}
