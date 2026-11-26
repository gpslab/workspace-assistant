<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Small argv-only synchronous process runner used for git and repository checks. */
final class Process
{
    /**
     * @param list<string>             $argv
     * @param array<string, string>  $environment
     *
     * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}
     */
    public static function run(array $argv, string $cwd, int $timeout, array $environment): array
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('A process argv cannot be empty.');
        }
        $pipes = [];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start '.implode(' ', $argv).'.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, 15);
                usleep(300_000);
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc, 9);
                }
                break;
            }
            usleep(20_000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $timedOut ? 124 : $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
    }
}
