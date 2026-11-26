<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Exception\SecretFoundException;
use GpsLab\WorkspaceAssistant\PatchSecrets;

/** Deterministic isolated worktrees and local-only, secret-scanned commits. */
final class Worktree
{
    /** @var array<string, string> */
    private readonly array $environment;

    public function __construct(
        private readonly string $repository,
        private readonly string $root,
        private readonly string $authorName,
        private readonly string $authorEmail,
    ) {
        $this->environment = ExecutionEnvironment::sanitize();
    }

    /**
     * @return array{path:string, branch:string, base_head:string, created:bool}
     */
    public function prepare(int $jobId, ?string $storedBaseHead = null): array
    {
        $path = $this->root.'/job-'.$jobId;
        $branch = self::branch($jobId);
        $repository = realpath($this->repository);
        if ($repository === false || !is_file($repository.'/.git/HEAD')) {
            throw new \RuntimeException('The managed repository is not a local git checkout.');
        }
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Unable to create the coder worktree directory.');
        }
        $root = realpath($this->root);
        if ($root === false || self::inside($root, $repository) || self::inside($repository, $root)) {
            throw new \RuntimeException('Coder worktrees must be outside the managed repository checkout.');
        }

        $created = !is_dir($path);
        if ($created) {
            $baseHead = $storedBaseHead ?? $this->git($repository, ['rev-parse', 'HEAD']);
            if ($storedBaseHead !== null) {
                $this->git($repository, ['cat-file', '-e', $storedBaseHead.'^{commit}']);
            }
            $this->git($repository, ['worktree', 'prune']);
            $branchExists = Process::run(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $repository, 30, $this->environment)['exit'] === 0;
            $args = $branchExists
                ? ['worktree', 'add', $path, $branch]
                : ['worktree', 'add', '-b', $branch, $path, $baseHead];
            $this->git($repository, $args);
        } else {
            if ($storedBaseHead === null) {
                throw new \RuntimeException('An existing coder worktree cannot resume without its base head.');
            }
            $baseHead = $storedBaseHead;
        }

        $this->configureAuthor($path);
        $this->reconcile($path, $branch, $baseHead);

        return ['path' => $path, 'branch' => $branch, 'base_head' => $baseHead, 'created' => $created];
    }

    public static function branch(int $jobId): string
    {
        return 'coder/job-'.$jobId;
    }

    /** @return array{head:string, status:string, commits:list<string>} */
    public function reconcile(string $path, string $branch, string $baseHead): array
    {
        if (!is_file($path.'/.git')) {
            throw new \RuntimeException('The isolated coder worktree is missing or is not a linked worktree.');
        }
        if ($this->git($path, ['branch', '--show-current']) !== $branch) {
            throw new \RuntimeException('The coder worktree is not on its deterministic branch.');
        }
        $this->git($path, ['cat-file', '-e', $baseHead.'^{commit}']);
        $this->configureAuthor($path);
        $commits = [];
        $log = $this->git($path, ['log', '--format=%H%x09%an%x09%ae', $baseHead.'..HEAD']);
        if ($log !== '') {
            foreach (explode("\n", $log) as $line) {
                [$hash, $name, $email] = array_pad(explode("\t", $line), 3, '');
                if ($name !== $this->authorName || $email !== $this->authorEmail) {
                    throw new \RuntimeException("Coder commit {$hash} has an unexpected author; history will not be rewritten.");
                }
                $commits[] = $hash;
            }
        }

        return [
            'head' => $this->git($path, ['rev-parse', 'HEAD']),
            'status' => $this->git($path, ['status', '--porcelain']),
            'commits' => $commits,
        ];
    }

    /** @return array{head:string, committed:bool} */
    public function commit(string $path, int $jobId): array
    {
        // This is deliberately repeated on resume and immediately before committing. No amend,
        // rebase, filter-branch or other history rewrite exists in this component.
        $this->configureAuthor($path);
        if ($this->git($path, ['config', '--local', 'user.name']) !== $this->authorName
            || $this->git($path, ['config', '--local', 'user.email']) !== $this->authorEmail) {
            throw new \RuntimeException('Coder git author configuration did not stick.');
        }
        $this->git($path, ['add', '-A']);
        $patch = $this->git($path, ['diff', '--cached', '-p', '-U0', '--no-color', '--no-ext-diff', '--no-textconv']);
        $findings = (new PatchSecrets())->scan($patch, false);
        if ($findings !== []) {
            $this->git($path, ['reset', '-q']);
            throw new SecretFoundException($findings);
        }
        $committed = Process::run(['git', 'diff', '--cached', '--quiet'], $path, 30, $this->environment)['exit'] !== 0;
        if ($committed) {
            $this->git($path, ['commit', '-m', 'Coder job '.$jobId]);
        }

        return ['head' => $this->git($path, ['rev-parse', 'HEAD']), 'committed' => $committed];
    }

    private function configureAuthor(string $path): void
    {
        $this->git($path, ['config', '--local', 'user.name', $this->authorName]);
        $this->git($path, ['config', '--local', 'user.email', $this->authorEmail]);
    }

    /** @param non-empty-list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $result = Process::run(['git', ...$args], $cwd, 30, $this->environment);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Local git operation failed: '.trim($result['stderr']."\n".$result['stdout']));
        }

        return trim($result['stdout']);
    }

    private static function inside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with(rtrim($path, '/').'/', rtrim($parent, '/').'/');
    }
}
