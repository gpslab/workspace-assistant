<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Small argv-only synchronous process runner used for git and repository checks. */
final class Process
{
    /**
     * @param list<string>          $argv
     * @param array<string, string> $environment
     * @param \Closure():void|null  $onTick
     *
     * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}
     */
    public static function run(
        array $argv,
        string $cwd,
        int $timeout,
        array $environment,
        ?\Closure $onTick = null,
        bool $dedicatedGroup = false,
        ?\Closure $onGroupStart = null,
    ): array {
        if ($argv === []) {
            throw new \InvalidArgumentException('A process argv cannot be empty.');
        }
        if ($onTick !== null) {
            $onTick();
        }
        $pipes = [];
        $command = $argv;
        $processEnvironment = $environment;
        if (in_array('', $environment, true)) {
            // proc_open() drops empty values from an environment array on some PHP builds. env -i
            // preserves them without a shell; an empty credential.helper is a meaningful git value.
            $command = ['env', '-i'];
            foreach ($environment as $name => $value) {
                $command[] = $name.'='.$value;
            }
            $command = [...$command, ...$argv];
            $processEnvironment = [];
        }
        if ($dedicatedGroup) {
            // proc_open() returns after exec, when the parent can no longer change the child's group.
            // This argv-only launcher establishes the group first; env performs the usual PATH lookup.
            $command = [
                PHP_BINARY,
                '-r',
                'if (!posix_setpgid(0, 0)) { exit(250); } pcntl_exec("/usr/bin/env", array_slice($argv, 1)); exit(250);',
                ...$command,
            ];
        }
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $processEnvironment);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start '.implode(' ', $argv).'.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pgid = $dedicatedGroup ? proc_get_status($proc)['pid'] : null;
        $stdout = $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        try {
            if ($onGroupStart !== null && $pgid !== null) {
                $onGroupStart($pgid);
            }
            while (true) {
                if ($onTick !== null) {
                    $onTick();
                }
                $status = proc_get_status($proc);
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    if ($pgid !== null) {
                        ProcessSupervisor::terminateGroup($pgid);
                        // Also stop the launcher if cancellation raced its pre-exec setpgid().
                        proc_terminate($proc, 9);
                    } else {
                        proc_terminate($proc, 15);
                        usleep(300_000);
                        if (proc_get_status($proc)['running']) {
                            proc_terminate($proc, 9);
                        }
                    }
                    break;
                }
                usleep(20_000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } catch (\Throwable $e) {
            if ($pgid !== null) {
                ProcessSupervisor::terminateGroup($pgid);
                proc_terminate($proc, 9);
            } else {
                proc_terminate($proc, 9);
            }
            throw $e;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        }

        return ['exit' => $timedOut ? 124 : $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
    }
}
