<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\ExecutionEnvironment;
use GpsLab\WorkspaceAssistant\Coder\Process;
use GpsLab\WorkspaceAssistant\PatchSecrets;

/** Revalidates the complete committed diff immediately before every controller write. */
final readonly class DiffGuard
{
    private const array ALWAYS_PROTECTED = [
        '.github/workflows/*', '.env', '.env.*', '*.pem', '*.key', '*credentials*',
    ];

    /** @param list<string> $protectedPaths */
    public function __construct(private array $protectedPaths = [])
    {
    }

    /** @return list<string> every old and new path in the base-to-head diff */
    public function validate(string $worktree, string $baseHead, string $expectedHead): array
    {
        if (preg_match('/^[0-9a-f]{40}$/i', $baseHead) !== 1 || preg_match('/^[0-9a-f]{40}$/i', $expectedHead) !== 1) {
            throw new \RuntimeException('Coder publication requires complete base and expected head SHAs.');
        }
        $head = $this->git($worktree, ['rev-parse', 'HEAD']);
        if (!hash_equals(strtolower($expectedHead), strtolower($head))) {
            throw new \RuntimeException('Coder publication rejected: the worktree head is stale.');
        }
        if ($this->git($worktree, ['status', '--porcelain']) !== '') {
            throw new \RuntimeException('Coder publication rejected: the worktree has an unvalidated working-tree diff.');
        }
        $raw = $this->git($worktree, ['diff', '--name-only', '--no-renames', '-z', $baseHead, $expectedHead], false);
        $paths = array_values(array_filter(explode("\0", $raw), static fn (string $path): bool => $path !== ''));
        foreach ($paths as $path) {
            foreach ([...self::ALWAYS_PROTECTED, ...$this->protectedPaths] as $pattern) {
                if (fnmatch($pattern, $path)) {
                    throw new \RuntimeException("Coder publication rejected: protected path '{$path}' changed.");
                }
            }
        }
        $patch = $this->git($worktree, ['diff', '-p', '-U0', '--no-color', '--no-ext-diff', '--no-textconv', $baseHead, $expectedHead], false);
        if ((new PatchSecrets())->scan($patch, false) !== []) {
            throw new \RuntimeException('Coder publication rejected: the complete diff contains a secret.');
        }

        return $paths;
    }

    /** @param non-empty-list<string> $args */
    private function git(string $worktree, array $args, bool $trim = true): string
    {
        $result = Process::run(['git', ...$args], $worktree, 30, ExecutionEnvironment::sanitize());
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Coder full-diff validation failed: '.trim($result['stderr']));
        }

        return $trim ? trim($result['stdout']) : $result['stdout'];
    }
}
