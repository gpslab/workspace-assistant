<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\GitHooks;
use GpsLab\WorkspaceAssistant\SystemPrompt;

/** Explicit one-job worker. It has no polling daemon and performs no forge writes. */
final class Worker
{
    /** @var \Closure(string):AgentRunner */
    private readonly \Closure $runnerFactory;
    private readonly Worktree $worktrees;
    private readonly ProcessSupervisor $supervisor;
    private readonly CheckRunner $checks;
    private int $workerId = 0;

    /** @param \Closure(string):AgentRunner|null $runnerFactory */
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        ?\Closure $runnerFactory = null,
        ?ProcessSupervisor $supervisor = null,
        ?CheckRunner $checks = null,
    ) {
        $this->runnerFactory = $runnerFactory ?? fn (string $worktree): AgentRunner => $this->claudeRunner($worktree);
        $this->worktrees = new Worktree($config->repository, $config->worktreeDir, $config->authorName, $config->authorEmail);
        $this->supervisor = $supervisor ?? new ProcessSupervisor();
        $this->checks = $checks ?? new CheckRunner($config->checkTimeout);
    }

    /** Recover expired local runs, then lease and execute at most one queued job. */
    public function runNext(): ?LocalResult
    {
        $this->recoverExpired();
        if ($this->workerId === 0) {
            $this->workerId = $this->store->registerWorker();
        }
        $lease = $this->store->assign($this->workerId, $this->config->leaseSeconds);
        if ($lease === null) {
            return null;
        }
        $jobId = $lease['job_id'];
        $runId = 'job-'.$jobId.'-lease-'.$lease['lease_generation'];
        $lock = $this->lock($jobId);
        if ($lock === null) {
            $this->store->fail($jobId, $this->workerId, $lease['lease_token'], 'Another worker holds the local run lock.');

            return new LocalResult(LocalResult::FAILED, $jobId, $runId, Worktree::branch($jobId), $this->config->worktreeDir.'/job-'.$jobId, null, null);
        }

        try {
            $job = $this->store->job($jobId);
            if ($job === null) {
                throw new \RuntimeException('The leased coder job disappeared.');
            }

            return $this->execute($job, $lease, $runId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** blocked, pause and author cancellation all use the same durable terminal gate. */
    public function cancel(int $jobId, string $reason = 'author'): LocalResult
    {
        if (!in_array($reason, ['blocked', 'pause', 'author'], true)) {
            throw new \InvalidArgumentException('Unknown coder cancellation reason.');
        }
        $job = $this->store->job($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Coder job does not exist.');
        }
        if (!$this->store->cancel($jobId, null, $reason)) {
            throw new \RuntimeException('Only an active or queued coder job can be cancelled.');
        }
        $diagnostics = $job['diagnostics'];
        ProcessSupervisor::reconcile(self::nullableInt($diagnostics['pgid'] ?? null), self::nullableString($diagnostics['run_id'] ?? null));

        return new LocalResult(
            LocalResult::CANCELLED, $jobId, (string) ($diagnostics['run_id'] ?? ''),
            Worktree::branch($jobId), (string) ($job['worktree'] ?? $this->config->worktreeDir.'/job-'.$jobId),
            self::nullableString($job['current_head']), self::nullableString($job['session_id']),
        );
    }

    /**
     * @param array<string, mixed>                                                                         $job
     * @param array{job_id:int,worker_id:int,lease_token:string,lease_generation:int,lease_expires_at:int} $lease
     */
    private function execute(array $job, array $lease, string $runId): LocalResult
    {
        $jobId = $job['id'];
        $diagnostics = $job['diagnostics'];
        $path = $this->config->worktreeDir.'/job-'.$jobId;
        $branch = Worktree::branch($jobId);
        $sessionId = self::nullableString($job['session_id']);
        $head = self::nullableString($job['current_head']);

        try {
            ProcessSupervisor::reconcile(self::nullableInt($diagnostics['pgid'] ?? null), self::nullableString($diagnostics['run_id'] ?? null));
            $prepared = $this->worktrees->prepare($jobId, self::nullableString($diagnostics['base_head'] ?? null));
            $path = $prepared['path'];
            $head = $this->worktrees->reconcile($path, $branch, $prepared['base_head'])['head'];
            $diagnostics = array_merge($diagnostics, $prepared, [
                'run_id' => $runId, 'phase' => 'prepared', 'engine_pid' => null, 'pgid' => null,
                'timeouts' => [
                    'overall_run_seconds' => $this->config->runTimeout,
                    'bash_default_ms' => Config::BASH_DEFAULT_TIMEOUT_MS,
                    'bash_max_ms' => Config::BASH_MAX_TIMEOUT_MS,
                ],
            ]);
            $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Preparing isolated checkout');
            if (!$this->store->transition($jobId, Store::RUNNING, $this->workerId, $lease['lease_token'])) {
                throw new \RuntimeException('The coder lease was lost before execution.');
            }
            $contract = CheckContract::forRepository($path, $diagnostics);

            if (($diagnostics['engine_complete'] ?? false) !== true) {
                $prompt = $diagnostics['prompt'] ?? null;
                if (!is_string($prompt) || trim($prompt) === '') {
                    throw new \RuntimeException('A leased fixture job must carry a non-empty diagnostics.prompt.');
                }
                $diagnostics['phase'] = 'engine';
                $diagnostics['run_id'] = $runId;
                $nextHeartbeat = 0.0;
                $status = $this->supervisor->run(
                    fn (): AgentRunner => ($this->runnerFactory)($path),
                    $prompt,
                    $sessionId,
                    $runId,
                    $this->config->stateDir.'/coder-runs/'.$runId,
                    $this->config->runTimeout,
                    function (array $update) use (&$diagnostics, &$sessionId, $jobId, $lease, $path, $head): void {
                        $diagnostics = array_merge($diagnostics, $update);
                        $sessionId = self::nullableString($update['session_id'] ?? null) ?? $sessionId;
                        $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Coder engine running');
                    },
                    function () use ($jobId, $lease, &$nextHeartbeat): bool {
                        $current = $this->store->job($jobId);
                        if ($current === null || $current['state'] === Store::CANCELLED) {
                            return true;
                        }
                        if (microtime(true) >= $nextHeartbeat) {
                            $nextHeartbeat = microtime(true) + max(1, intdiv($this->config->leaseSeconds, 3));
                            if (!$this->store->heartbeat($jobId, $this->workerId, $lease['lease_token'], $this->config->leaseSeconds)) {
                                return true;
                            }
                        }

                        return $current['state'] !== Store::RUNNING;
                    },
                );
                $diagnostics = array_merge($diagnostics, $status);
                $sessionId = self::nullableString($status['session_id'] ?? null) ?? $sessionId;
                if ($status['phase'] === 'cancelled') {
                    return $this->cancelledResult($jobId, $runId, $branch, $path, $head, $sessionId);
                }
                if ($status['phase'] !== 'done') {
                    throw new \RuntimeException((string) ($status['error'] ?? 'The coder engine failed.'));
                }
                if ($sessionId === null) {
                    throw new \RuntimeException('The coder engine returned no resumable session id.');
                }
                $diagnostics['engine_complete'] = true;
                $diagnostics['phase'] = 'engine_done';
                $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Coder engine finished');
            }

            if (!isset($diagnostics['local_commit'])) {
                if (!isset($diagnostics['checks']['targeted'])) {
                    $diagnostics['phase'] = 'targeted_checks';
                    $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Running targeted checks');
                    $diagnostics['checks']['targeted'] = $this->checks->run($contract['targeted'], $path);
                    $diagnostics['phase'] = 'targeted_done';
                    $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Targeted checks recorded');
                    $this->assertChecksPassed($diagnostics['checks']['targeted']);
                }
                if (!isset($diagnostics['checks']['full'])) {
                    $diagnostics['phase'] = 'full_checks';
                    $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Running full final checks');
                    $diagnostics['checks']['full'] = $this->checks->run($contract['full'], $path);
                    $diagnostics['phase'] = 'full_done';
                    $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Full final checks recorded');
                    $this->assertChecksPassed($diagnostics['checks']['full']);
                }
                if (($this->store->job($jobId)['state'] ?? null) === Store::CANCELLED) {
                    return $this->cancelledResult($jobId, $runId, $branch, $path, $head, $sessionId);
                }
                $diagnostics['phase'] = 'commit';
                $commit = $this->worktrees->commit($path, $jobId);
                $head = $commit['head'];
                $diagnostics['local_commit'] = $commit;
                $diagnostics['phase'] = 'committed';
                $this->persist($jobId, $lease, $diagnostics, $head, $sessionId, $path, 'Local commit created');
            } else {
                $head = self::nullableString($job['current_head']) ?? $head;
            }

            if ($sessionId === null) {
                throw new \RuntimeException('A publication request requires a session and a local head.');
            }
            $request = new PublicationRequest($jobId, $runId, $branch, $path, $head, $sessionId);
            $diagnostics['local_result'] = $request->toArray();
            $diagnostics['phase'] = 'done';
            if (!$this->store->complete($jobId, $this->workerId, $lease['lease_token'], $diagnostics)) {
                return $this->cancelledResult($jobId, $runId, $branch, $path, $head, $sessionId);
            }

            return new LocalResult(LocalResult::PUBLICATION_REQUESTED, $jobId, $runId, $branch, $path, $head, $sessionId, $diagnostics);
        } catch (\Throwable $e) {
            $current = $this->store->job($jobId);
            if (($current['state'] ?? null) === Store::CANCELLED) {
                return $this->cancelledResult($jobId, $runId, $branch, $path, $head, $sessionId);
            }
            $diagnostics['error'] = $e->getMessage();
            $this->store->fail($jobId, $this->workerId, $lease['lease_token'], $e->getMessage(), $diagnostics);

            return new LocalResult(LocalResult::FAILED, $jobId, $runId, $branch, $path, $head, $sessionId, $diagnostics);
        }
    }

    private function recoverExpired(): void
    {
        foreach ($this->store->reconcileExpiredLeases() as $jobId) {
            $lock = $this->lock($jobId);
            if ($lock === null) {
                continue;
            }
            try {
                $job = $this->store->job($jobId);
                $diagnostics = $job['diagnostics'] ?? [];
                ProcessSupervisor::reconcile(self::nullableInt($diagnostics['pgid'] ?? null), self::nullableString($diagnostics['run_id'] ?? null));
                if (isset($diagnostics['base_head'], $diagnostics['branch']) && is_string($diagnostics['base_head']) && is_string($diagnostics['branch'])) {
                    $prepared = $this->worktrees->prepare($jobId, $diagnostics['base_head']);
                    $this->worktrees->reconcile($prepared['path'], $diagnostics['branch'], $diagnostics['base_head']);
                }
                $this->store->requeue($jobId);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                                      $diagnostics
     * @param array{job_id:int,worker_id:int,lease_token:string,lease_generation:int,lease_expires_at:int} $lease
     */
    private function persist(int $jobId, array $lease, array $diagnostics, ?string $head, ?string $sessionId, string $path, string $progress): void
    {
        if (!$this->store->updateContext($jobId, $this->workerId, $lease['lease_token'], $head, $sessionId, $path, $diagnostics, $progress)) {
            throw new \RuntimeException('The coder lease was lost while persisting execution state.');
        }
    }

    /** @param list<array{argv:list<string>,exit:int,seconds:float,timed_out:bool}> $records */
    private function assertChecksPassed(array $records): void
    {
        foreach ($records as $record) {
            if ($record['exit'] !== 0) {
                throw new \RuntimeException('Local check failed: '.implode(' ', $record['argv']).' (exit '.$record['exit'].').');
            }
        }
    }

    private function cancelledResult(int $jobId, string $runId, string $branch, string $path, ?string $head, ?string $sessionId): LocalResult
    {
        return new LocalResult(LocalResult::CANCELLED, $jobId, $runId, $branch, $path, $head, $sessionId);
    }

    /** @return resource|null */
    private function lock(int $jobId)
    {
        $dir = $this->config->stateDir.'/coder-locks';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the coder lock directory.');
        }
        $lock = fopen($dir.'/job-'.$jobId.'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the coder run lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    private function claudeRunner(string $worktree): ClaudeRunner
    {
        $hook = PHP_BINARY.' '.escapeshellarg(dirname(__DIR__, 2).'/bin/secret-guard-hook.php');
        $settings = (string) json_encode(['hooks' => ['PreToolUse' => [[
            'matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => $hook]],
        ]]]], JSON_THROW_ON_ERROR);

        return new ClaudeRunner(
            $this->config->claudeBin,
            $worktree,
            0, // The supervisor owns the independent overall run timeout.
            $this->config->claudeExtraArgs,
            0,
            mode: SystemPrompt::MODE_ARCH,
            settingsJson: $settings,
            extraEnv: [...$this->config->engineEnvironment(), GitHooks::ENV_FILE_ENV => $this->config->envFile],
            stateDir: $this->config->stateDir,
            withAgents: false,
            homeDir: '',
            providerEnv: $this->config->providerEnv,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
