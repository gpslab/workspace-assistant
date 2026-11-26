<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\RunnerState;

/** Runs one AgentRunner behind a session leader and supervises its complete process group. */
final class ProcessSupervisor
{
    public const string RUN_ID_ENV = 'WORKSPACE_ASSISTANT_CODER_RUN_ID';

    /**
     * @param \Closure():AgentRunner             $factory
     * @param \Closure(array<string,mixed>):void $onUpdate
     * @param \Closure():bool                    $isCancelled
     *
     * @return array<string, mixed>
     */
    public function run(
        \Closure $factory,
        string $prompt,
        ?string $sessionId,
        string $runId,
        string $runDirectory,
        int $timeout,
        \Closure $onUpdate,
        \Closure $isCancelled,
    ): array {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
            throw new \RuntimeException('The coder worker requires pcntl and POSIX process-group support.');
        }
        if (!is_dir($runDirectory) && !mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) {
            throw new \RuntimeException('Unable to create the coder run directory.');
        }
        $statusPath = $runDirectory.'/status.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork the coder engine supervisor.');
        }
        if ($pid === 0) {
            $this->child($factory, $prompt, $sessionId, $runId, $pid = (int) posix_getpid(), $statusPath);
        }

        $status = ['phase' => 'launching', 'run_id' => $runId, 'supervisor_pid' => $pid, 'engine_pid' => null, 'pgid' => $pid];
        $onUpdate($status);
        $deadline = microtime(true) + $timeout;
        try {
            while (true) {
                $disk = self::readStatus($statusPath);
                if ($disk !== null && $disk !== $status) {
                    if (($disk['run_id'] ?? null) !== $runId) {
                        throw new \RuntimeException('The coder engine reported a different run identity.');
                    }
                    $status = $disk;
                    $onUpdate($status);
                }
                if ($isCancelled()) {
                    self::terminateGroup($pid);
                    $this->reap($pid);
                    $status['phase'] = 'cancelled';

                    return $status;
                }
                if (microtime(true) >= $deadline) {
                    self::terminateGroup($pid);
                    $this->reap($pid);
                    $status['phase'] = 'failed';
                    $status['error'] = 'The overall coder run timeout expired.';

                    return $status;
                }
                if (in_array($status['phase'] ?? '', ['done', 'failed'], true)) {
                    self::terminateGroup($pid);
                    $this->reap($pid);

                    return $status;
                }
                $wait = pcntl_waitpid($pid, $waitStatus, WNOHANG);
                if ($wait === $pid) {
                    $disk = self::readStatus($statusPath);
                    if ($disk !== null && in_array($disk['phase'] ?? '', ['done', 'failed'], true)) {
                        self::terminateGroup($pid);

                        return $disk;
                    }
                    self::terminateGroup($pid);
                    $status['phase'] = 'failed';
                    $status['error'] = 'The coder engine supervisor exited without a durable result.';

                    return $status;
                }
                usleep(50_000);
            }
        } catch (\Throwable $e) {
            self::terminateGroup($pid);
            $this->reap($pid);
            throw $e;
        }
    }

    /** Stop a surviving group only when a live member carries the persisted run identity. */
    public static function reconcile(?int $pgid, ?string $runId): bool
    {
        if ($pgid === null || $pgid < 1 || !self::groupAlive($pgid)) {
            return false;
        }
        if ($runId === null || $runId === '' || !self::groupMatches($pgid, $runId)) {
            throw new \RuntimeException('A surviving process group does not match the persisted coder run identity.');
        }
        self::terminateGroup($pgid);

        return true;
    }

    public static function terminateGroup(int $pgid): void
    {
        if ($pgid < 1 || $pgid === posix_getpgid(posix_getpid()) || !self::groupAlive($pgid)) {
            return;
        }
        posix_kill(-$pgid, SIGTERM);
        $deadline = microtime(true) + 0.5;
        while (self::groupAlive($pgid) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (self::groupAlive($pgid)) {
            posix_kill(-$pgid, SIGKILL);
        }
    }

    /** @param \Closure():AgentRunner $factory */
    private function child(\Closure $factory, string $prompt, ?string $sessionId, string $runId, int $pid, string $statusPath): void
    {
        try {
            if (posix_setsid() === -1) {
                throw new \RuntimeException('Unable to create the coder process group.');
            }
            putenv(self::RUN_ID_ENV.'='.$runId);
            ExecutionEnvironment::apply();
            $runner = $factory();
            $runner->start($prompt, $sessionId);
            if ($runner->pid() < 1) {
                throw new \RuntimeException('The coder engine did not report its PID.');
            }
            $status = [
                'phase' => 'running', 'run_id' => $runId, 'supervisor_pid' => $pid,
                'engine_pid' => $runner->pid(), 'pgid' => $pid, 'session_id' => $runner->sessionId(),
            ];
            self::writeStatus($statusPath, $status);
            while (($state = $runner->poll()) === RunnerState::Running) {
                if ($runner->sessionId() !== $status['session_id']) {
                    $status['session_id'] = $runner->sessionId();
                    self::writeStatus($statusPath, $status);
                }
                usleep(50_000);
            }
            if ($state === RunnerState::Timeout) {
                $status['phase'] = 'failed';
                $status['error'] = $runner->timeoutReason();
            } else {
                $answer = $runner->result();
                $status['phase'] = $answer->isError ? 'failed' : 'done';
                $status['session_id'] = $answer->sessionId ?? $runner->sessionId();
                $status['answer'] = $answer->text;
                $status['error'] = $answer->isError ? $answer->text : null;
                $status['progress'] = $runner->getProgressLines();
                $status['diagnostics_path'] = $runner->diagnosticsPath();
            }
            self::writeStatus($statusPath, $status);
        } catch (\Throwable $e) {
            self::writeStatus($statusPath, [
                'phase' => 'failed', 'run_id' => $runId, 'supervisor_pid' => $pid,
                'engine_pid' => isset($runner) ? $runner->pid() : null, 'pgid' => $pid,
                'session_id' => isset($runner) ? $runner->sessionId() : $sessionId, 'error' => $e->getMessage(),
            ]);
        }

        // A fork must not run the parent's PHPUnit/application shutdown handlers.
        posix_kill(posix_getpid(), SIGKILL);
        exit(250);
    }

    private function reap(int $pid): void
    {
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                return;
            }
            usleep(20_000);
        }
    }

    /** @return array<string, mixed>|null */
    private static function readStatus(string $path): ?array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $status */
    private static function writeStatus(string $path, array $status): void
    {
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Unable to persist coder engine status.');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('Unable to publish coder engine status.');
        }
    }

    /** @phpstan-impure */
    private static function groupAlive(int $pgid): bool
    {
        return self::groupMembers($pgid) !== [];
    }

    private static function groupMatches(int $pgid, string $runId): bool
    {
        $needle = self::RUN_ID_ENV.'='.$runId;
        foreach (self::groupMembers($pgid) as $pid) {
            $environment = @file_get_contents('/proc/'.$pid.'/environ');
            if (is_string($environment) && in_array($needle, explode("\0", $environment), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> live (non-zombie) process ids */
    private static function groupMembers(int $pgid): array
    {
        $members = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $path) {
            $pid = (int) basename($path);
            $stat = @file_get_contents($path.'/stat');
            if (!is_string($stat) || posix_getpgid($pid) !== $pgid) {
                continue;
            }
            $end = strrpos($stat, ')');
            $fields = $end === false ? [] : (preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: []);
            if (($fields[0] ?? '') !== 'Z') {
                $members[] = $pid;
            }
        }

        return $members;
    }
}
