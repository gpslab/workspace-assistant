<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Everything the installer takes from the outside world, injectable so tests run offline and
 * without a terminal. {@see self::system()} gives the real implementations.
 *
 * Contracts of the closures:
 * - run:  (list<string> $command, array<string, string> $env, int $timeoutSeconds, ?string $cwd, bool $inheritStdin)
 *         -> array{int, string, string}: exit code, stdout, stderr. Exit code {@see self::EXIT_TIMEOUT} means
 *         the call timed out, {@see self::EXIT_NOT_FOUND} that the program could not be started.
 * - http: (string $url, int $timeoutSeconds) -> array{int, string}|null: status and body, null on a transport failure.
 * - extensionLoaded: (string $name) -> bool
 * - isTty: () -> bool, whether stdin is a terminal
 */
final class InstallerServices
{
    public const EXIT_TIMEOUT = -1;
    public const EXIT_NOT_FOUND = 127;

    /**
     * @param \Closure(list<string>, array<string, string>, int, ?string, bool): array{int, string, string} $run
     * @param \Closure(string, int): (array{int, string}|null)                                              $http
     * @param \Closure(string): bool                                                                        $extensionLoaded
     * @param \Closure(): bool                                                                              $isTty
     * @param resource                                                                                      $in
     */
    public function __construct(
        public readonly \Closure $run,
        public readonly \Closure $http,
        public readonly \Closure $extensionLoaded,
        public readonly \Closure $isTty,
        public readonly mixed $in,
        public readonly string $phpVersion,
        public readonly string $phpBinary,
    ) {
    }

    public static function system(): self
    {
        return new self(
            self::runProcess(...),
            self::httpGet(...),
            extension_loaded(...),
            static fn (): bool => stream_isatty(STDIN),
            STDIN,
            PHP_VERSION,
            PHP_BINARY,
        );
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    public static function runProcess(array $command, array $env, int $timeout, ?string $cwd, bool $inheritStdin): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $descriptors[0] = $inheritStdin ? STDIN : ['pipe', 'r'];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env === [] ? null : $env);
        if (!is_resource($process)) {
            return [self::EXIT_NOT_FOUND, '', 'cannot start '.($command[0] ?? '')];
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        $finished = -1;
        while (true) {
            $status = proc_get_status($process);
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $stream) {
                    $chunk = (string) fread($stream, 8192);
                    if ($stream === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            if (!$status['running']) {
                $finished = $status['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        // proc_get_status reports the real code only once, so prefer it when the process ended by itself.
        $code = $finished >= 0 ? $finished : $exit;

        return [$timedOut ? self::EXIT_TIMEOUT : $code, $out, $err];
    }

    /**
     * @return array{int, string}|null
     */
    public static function httpGet(string $url, int $timeout): ?array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return is_string($body) ? [$status, $body] : null;
    }
}
