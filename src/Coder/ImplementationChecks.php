<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Worker-owned execution and attestation for targeted checks during implementation. */
final class ImplementationChecks
{
    public const string PROMPT_MARKER = 'CODER_TARGETED_CHECK_ARGV: ';

    private const string SOCKET_FILE = 'targeted-checks.sock';
    private const string REPORT_FILE = 'targeted-results.json';

    private bool $stopped = false;

    private function __construct(
        private readonly int $brokerPid,
        private readonly string $secret,
        private readonly string $runDirectory,
        private readonly string $worktree,
        private readonly string $checkDefinitions,
        private readonly int $timeout,
    ) {
    }

    /**
     * Start the worker-owned broker. Only the broker can run checks and sign their report; the
     * engine-facing helper is an unauthenticated client because invoking it is not itself proof.
     *
     * @param list<list<string>> $commands
     */
    public static function start(
        array $commands,
        string $checkDefinitions,
        string $runDirectory,
        string $worktree,
        int $timeout,
        ?\Closure $beforeBrokerStart = null,
    ): self {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setpgid')) {
            throw new \RuntimeException('The coder worker requires pcntl and POSIX process-group support for targeted checks.');
        }
        if (!is_dir($runDirectory) && !mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) {
            throw new \RuntimeException('Unable to create the coder run directory.');
        }
        $socketPath = $runDirectory.'/'.self::SOCKET_FILE;
        $server = @stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        if ($server === false) {
            throw new \RuntimeException('Unable to start the targeted check broker: '.$errorMessage, $errorCode ?? 0);
        }
        chmod($socketPath, 0600);
        $secret = bin2hex(random_bytes(32));
        $parentPid = (int) getmypid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            @unlink($socketPath);
            throw new \RuntimeException('Unable to fork the targeted check broker.');
        }
        if ($pid === 0) {
            if ($beforeBrokerStart !== null) {
                $beforeBrokerStart();
            }
            if (!posix_setpgid(0, 0)) {
                fclose($server);
                posix_kill(posix_getpid(), SIGKILL);
                exit(250);
            }
            self::serve($server, $parentPid, $commands, $checkDefinitions, $runDirectory, $worktree, $timeout, $secret);
        }
        if (!posix_setpgid($pid, $pid)) {
            // Close the race with stop(): the parent establishes the same group before returning.
            posix_kill($pid, SIGKILL);
            posix_kill(-$pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            fclose($server);
            @unlink($socketPath);
            throw new \RuntimeException('Unable to create the targeted check process group.');
        }
        fclose($server);

        return new self($pid, $secret, $runDirectory, $worktree, $checkDefinitions, $timeout);
    }

    /** @return array<string, mixed> */
    public function identity(string $runId): array
    {
        return ProcessSupervisor::identity($this->brokerPid, $runId) + ['kind' => 'targeted', 'active' => true];
    }

    public function prompt(string $prompt): string
    {
        $argv = [
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/coder-targeted-check.php',
            $this->runDirectory.'/'.self::SOCKET_FILE,
            (string) $this->timeout,
        ];

        return $prompt."\n\n"
            ."## Targeted implementation checks\n\n"
            ."After editing, and before finishing this implementation run, invoke this exact argument vector:\n\n"
            .self::PROMPT_MARKER.json_encode($argv, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n"
            .'The fixed helper asks the worker-owned broker to run the base-pinned targeted checks. '
            .'If it fails, fix the implementation and invoke it again until every targeted check passes. '
            .'Do not interfere with its broker or result files. Do not run the full final check set yourself; '
            .'the worker runs that set once after this implementation run finishes.';
    }

    /**
     * @param list<list<string>> $expected
     *
     * @return list<array{argv:list<string>,exit:int,seconds:float,timed_out:bool}>
     */
    public function results(array $expected): array
    {
        $data = self::read($this->runDirectory.'/'.self::REPORT_FILE);
        $payload = $data['payload'] ?? null;
        $signature = $data['signature'] ?? null;
        if (!is_string($payload) || !is_string($signature)
            || !hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)
        ) {
            throw new \RuntimeException('The targeted implementation check report has no valid worker attestation.');
        }
        $attested = json_decode($payload, true);
        if (!is_array($attested)
            || ($attested['commands'] ?? null) !== $expected
            || ($attested['check_definitions'] ?? null) !== $this->checkDefinitions
            || ($attested['worktree'] ?? null) !== $this->worktree
        ) {
            throw new \RuntimeException('The targeted implementation check attestation does not match its pinned contract.');
        }
        $records = $attested['checks'] ?? null;
        if (!is_array($records) || !array_is_list($records) || count($records) !== count($expected)) {
            throw new \RuntimeException('The engine did not record the required targeted implementation checks.');
        }
        $result = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)
                || ($record['argv'] ?? null) !== $expected[$index]
                || !is_int($record['exit'] ?? null)
                || (!is_int($record['seconds'] ?? null) && !is_float($record['seconds'] ?? null))
                || $record['seconds'] < 0
                || !is_bool($record['timed_out'] ?? null)
            ) {
                throw new \RuntimeException('The targeted implementation check report does not match its pinned contract.');
            }
            $result[] = [
                'argv' => $record['argv'],
                'exit' => $record['exit'],
                'seconds' => (float) $record['seconds'],
                'timed_out' => $record['timed_out'],
            ];
        }

        return $result;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $status = 0;
        // The broker leads a dedicated group so Composer and every check descendant stop with it.
        // Signal the group even if the broker exited first and left an orphaned check behind.
        posix_kill(-$this->brokerPid, SIGKILL);
        pcntl_waitpid($this->brokerPid, $status);
        @unlink($this->runDirectory.'/'.self::SOCKET_FILE);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Connect to the worker-owned broker. The report is never accepted from this engine-facing client.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function execute(array $arguments): int
    {
        if (count($arguments) !== 2) {
            throw new \InvalidArgumentException('Usage: coder-targeted-check.php SOCKET TIMEOUT');
        }
        [$socketPath, $timeout] = array_values($arguments);
        if (!is_string($socketPath) || $socketPath === '' || !is_string($timeout) || !ctype_digit($timeout) || (int) $timeout < 1) {
            throw new \InvalidArgumentException('The targeted check socket and timeout are invalid.');
        }
        $connection = @stream_socket_client('unix://'.$socketPath, $errorCode, $errorMessage, 5);
        if ($connection === false) {
            throw new \RuntimeException('Unable to contact the targeted check broker: '.$errorMessage, $errorCode ?? 0);
        }
        stream_set_timeout($connection, (int) $timeout + 5);
        if (fwrite($connection, "run\n") !== 4) {
            fclose($connection);
            throw new \RuntimeException('Unable to request targeted implementation checks.');
        }
        $json = stream_get_line($connection, 65536, "\n");
        $timedOut = stream_get_meta_data($connection)['timed_out'];
        fclose($connection);
        if (!is_string($json) || $timedOut) {
            throw new \RuntimeException('The targeted check broker did not return a result.');
        }
        $response = json_decode($json, true);
        if (!is_array($response) || !in_array($response['exit'] ?? null, [0, 1], true)) {
            $error = is_array($response) && is_string($response['error'] ?? null)
                ? $response['error'] : 'The targeted check broker returned an invalid result.';
            throw new \RuntimeException($error);
        }

        return $response['exit'];
    }

    /**
     * @param resource           $server
     * @param list<list<string>> $commands
     */
    private static function serve(
        $server,
        int $parentPid,
        array $commands,
        string $checkDefinitions,
        string $runDirectory,
        string $worktree,
        int $timeout,
        string $secret,
    ): never {
        while (posix_getppid() === $parentPid) {
            $connection = @stream_socket_accept($server, 1.0);
            if ($connection === false) {
                continue;
            }
            $request = stream_get_line($connection, 1024, "\n");
            if ($request !== 'run') {
                self::respond($connection, ['exit' => 2, 'error' => 'The targeted check request is invalid.']);
                continue;
            }
            try {
                $current = file_get_contents($worktree.'/composer.json');
                if (!is_string($current) || CheckContract::definitions($current) !== $checkDefinitions) {
                    throw new \RuntimeException('A coder job cannot change the Composer check definitions.');
                }
                $records = (new CheckRunner($timeout))->run(CheckContract::targetedCommands($commands), $worktree);
                $payload = json_encode([
                    'commands' => $commands,
                    'check_definitions' => $checkDefinitions,
                    'worktree' => $worktree,
                    'checks' => $records,
                ], JSON_THROW_ON_ERROR);
                self::write($runDirectory.'/'.self::REPORT_FILE, [
                    'payload' => $payload,
                    'signature' => hash_hmac('sha256', $payload, $secret),
                ]);
                $exit = 0;
                foreach ($records as $record) {
                    if ($record['exit'] !== 0) {
                        $exit = 1;
                    }
                }
                self::respond($connection, ['exit' => $exit, 'error' => null]);
            } catch (\Throwable $e) {
                self::respond($connection, ['exit' => 2, 'error' => $e->getMessage()]);
            }
        }
        fclose($server);

        // A fork must not run the parent's PHPUnit/application shutdown handlers.
        posix_kill(posix_getpid(), SIGKILL);
        exit(250);
    }

    /**
     * @param resource                      $connection
     * @param array{exit:int,error:?string} $response
     */
    private static function respond($connection, array $response): void
    {
        fwrite($connection, json_encode($response, JSON_THROW_ON_ERROR)."\n");
        fclose($connection);
    }

    /** @return array<string, mixed> */
    private static function read(string $path): array
    {
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('The targeted implementation check file is missing.');
        }
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('The targeted implementation check file is not valid JSON.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function write(string $path, array $data): void
    {
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write a targeted implementation check file.');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('Unable to publish a targeted implementation check file.');
        }
    }
}
