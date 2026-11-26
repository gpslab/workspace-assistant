<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** The fixed engine-facing tool for targeted checks during implementation. */
final class ImplementationChecks
{
    public const string PROMPT_MARKER = 'CODER_TARGETED_CHECK_ARGV: ';

    private const string CONTRACT_FILE = 'targeted-contract.json';
    private const string REPORT_FILE = 'targeted-results.json';

    /**
     * @param list<list<string>> $commands
     */
    public static function prompt(
        string $prompt,
        array $commands,
        string $checkDefinitions,
        string $runDirectory,
        string $worktree,
        int $timeout,
    ): string {
        if (!is_dir($runDirectory) && !mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) {
            throw new \RuntimeException('Unable to create the coder run directory.');
        }
        $contractPath = $runDirectory.'/'.self::CONTRACT_FILE;
        self::write($contractPath, ['commands' => $commands, 'check_definitions' => $checkDefinitions, 'timeout' => $timeout]);
        $argv = [
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/coder-targeted-check.php',
            $contractPath,
            $worktree,
            $runDirectory.'/'.self::REPORT_FILE,
        ];

        return $prompt."\n\n"
            ."## Targeted implementation checks\n\n"
            ."After editing, and before finishing this implementation run, invoke this exact argument vector:\n\n"
            .self::PROMPT_MARKER.json_encode($argv, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n"
            .'The fixed helper runs the base-pinned targeted checks and writes their results. '
            .'If it fails, fix the implementation and invoke it again until every targeted check passes. '
            .'Do not edit its contract or result files. Do not run the full final check set yourself; '
            .'the worker runs that set once after this implementation run finishes.';
    }

    /**
     * @param list<list<string>> $expected
     *
     * @return list<array{argv:list<string>,exit:int,seconds:float,timed_out:bool}>
     */
    public static function results(string $runDirectory, array $expected): array
    {
        $data = self::read($runDirectory.'/'.self::REPORT_FILE);
        $records = $data['checks'] ?? null;
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

    /**
     * Execute the helper CLI arguments and return its process exit code.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function execute(array $arguments): int
    {
        if (count($arguments) !== 3) {
            throw new \InvalidArgumentException('Usage: coder-targeted-check.php CONTRACT WORKTREE REPORT');
        }
        $paths = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new \InvalidArgumentException('Every targeted check path must be a non-empty string.');
            }
            $paths[] = $argument;
        }
        [$contractPath, $worktree, $reportPath] = $paths;
        $contract = self::read($contractPath);
        $commands = $contract['commands'] ?? null;
        $checkDefinitions = $contract['check_definitions'] ?? null;
        $timeout = $contract['timeout'] ?? null;
        if (!is_array($commands) || !array_is_list($commands) || !is_string($checkDefinitions) || !is_int($timeout) || $timeout < 1) {
            throw new \RuntimeException('The targeted implementation check contract is invalid.');
        }
        $current = file_get_contents($worktree.'/composer.json');
        if (!is_string($current) || CheckContract::definitions($current) !== $checkDefinitions) {
            throw new \RuntimeException('A coder job cannot change the Composer check definitions.');
        }
        $records = (new CheckRunner($timeout))->run(CheckContract::targetedCommands($commands), $worktree);
        self::write($reportPath, ['checks' => $records]);
        foreach ($records as $record) {
            if ($record['exit'] !== 0) {
                return 1;
            }
        }

        return 0;
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
