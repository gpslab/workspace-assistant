<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The self-test of the Kimi gate shim: `bin/kimi-hook.php --self-test` against the state directory a
 * run home will be created under. The shim checks that it can write what the gate needs; a parse
 * error or a missing bootstrap makes it exit non-zero.
 *
 * Kimi's hooks fail open — a hook that is killed, times out or is missing lets the call through — so
 * a shim that cannot even start is worse than a refusal, and this is how a gated run notices before
 * it relies on one. {@see \GpsLab\WorkspaceAssistant\JobRunner} runs it before a gated run, and
 * {@see KimiGateGuard} runs it before a gated mode is let into the queue.
 *
 * Arguments are passed as an array to `proc_open`: no shell, no injection. The pipes are read
 * non-blocking with a `stream_select` deadline so that a stuck shim (a blocking bootstrap `include`,
 * a hung filesystem, a PHP binary waiting on stdin) cannot freeze the polling loop, and both pipes
 * are drained on every pass so a full buffer can never stall the other.
 */
final class KimiGateSelfTest
{
    /** Deadline of one self-test (a local bootstrap and writability check, normally < 1 s). */
    public const int TIMEOUT_SECONDS = 5;

    /** Path of the shim to test; null = the one of this installation (`bin/kimi-hook.php`). */
    public function __construct(
        private readonly ?string $shim = null,
    ) {
    }

    /**
     * Runs the self-test and returns the failure text (English, for `bot.log`), or null when it passed.
     *
     * @param string $stateDir the directory the shim is tested against; '' = the temporary directory
     */
    public function run(string $stateDir): ?string
    {
        $shim = $this->shim ?? dirname(__DIR__, 2).'/bin/kimi-hook.php';
        if (!is_file($shim)) {
            return 'The Kimi gate shim is not installed: '.$shim;
        }
        $home = $stateDir !== '' ? $stateDir : sys_get_temp_dir();
        $proc = @proc_open(
            [PHP_BINARY, $shim, '--self-test'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['KIMI_CODE_HOME' => $home]),
        );
        if (!is_resource($proc)) {
            return 'The Kimi gate self-test could not be started.';
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $timedOut = false;

        while (true) {
            // Drain both pipes each pass: never wait for EOF on one while the other fills its buffer.
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $running = proc_get_status($proc)['running'];
            if (!$running) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remaining = $deadline - microtime(true);
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1_000_000);
            @stream_select($read, $write, $except, $sec, $usec);
        }

        if ($timedOut) {
            proc_terminate($proc, 15); // SIGTERM
            usleep(300_000);
            $status = proc_get_status($proc);
            if ($status['running'] === true) {
                proc_terminate($proc, 9); // SIGKILL
            }
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($timedOut) {
            return 'Kimi gate self-test timed out';
        }
        if ($code === 0) {
            return null;
        }
        $reason = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

        return 'Kimi gate self-test failed'.($reason !== '' ? ': '.$reason : ' (exit '.$code.')');
    }
}
