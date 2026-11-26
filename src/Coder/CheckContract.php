<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Repository-provided check commands; no shell is involved. */
final class CheckContract
{
    /**
     * The execution engine cannot replace the gates it is graded by. The contract comes from the
     * immutable base commit and the checkout must still carry exactly the same Composer definitions.
     *
     * @param array<string, mixed> $jobDiagnostics
     * @param \Closure():void|null $onTick
     *
     * @return array{targeted:list<list<string>>, full:list<list<string>>, composer:string}
     */
    public static function forRepository(string $repository, array $jobDiagnostics, ?\Closure $onTick = null): array
    {
        $baseHead = $jobDiagnostics['base_head'] ?? null;
        if (!is_string($baseHead) || $baseHead === '') {
            throw new \RuntimeException('The coder check contract requires a base head.');
        }
        $result = Process::run(
            ['git', 'show', $baseHead.':composer.json'],
            $repository,
            30,
            ExecutionEnvironment::sanitize(),
            $onTick,
        );
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('The base commit has no readable composer.json check contract.');
        }
        $composer = $result['stdout'];
        if (file_get_contents($repository.'/composer.json') !== $composer) {
            throw new \RuntimeException('A coder job cannot change the Composer check definitions.');
        }
        /** @var array{scripts?:array<string, mixed>}|null $data */
        $data = json_decode($composer, true);
        $scripts = is_array($data) && is_array($data['scripts'] ?? null) ? array_keys($data['scripts']) : [];
        foreach (['cs:check', 'stan', 'test'] as $required) {
            if (!in_array($required, $scripts, true)) {
                throw new \RuntimeException("The target repository check contract lacks composer {$required}.");
            }
        }
        $targeted = $jobDiagnostics['targeted_checks'] ?? [['composer', 'test']];
        if (!is_array($targeted) || !array_is_list($targeted) || $targeted === []) {
            throw new \RuntimeException('targeted_checks must be a non-empty list of argv arrays.');
        }

        return [
            'targeted' => self::targetedCommands($targeted),
            'full' => [['composer', 'cs:check'], ['composer', 'stan'], ['composer', 'test']],
            'composer' => $composer,
        ];
    }

    /**
     * Job diagnostics cannot introduce arbitrary worker commands. Test-specific targeting remains
     * available through PHPUnit filter options passed to the fixed Composer test script.
     *
     * @param list<mixed> $commands
     *
     * @return list<list<string>>
     */
    public static function targetedCommands(array $commands): array
    {
        if ($commands === []) {
            throw new \RuntimeException('At least one targeted implementation check is required.');
        }
        $result = [];
        foreach ($commands as $command) {
            if (!is_array($command) || count($command) < 2 || ($command[0] ?? null) !== 'composer' || ($command[1] ?? null) !== 'test') {
                throw new \RuntimeException('A targeted check must invoke only composer test with optional test filters.');
            }
            $arguments = array_slice($command, 2);
            foreach ($arguments as $argument) {
                if (!is_string($argument) || preg_match('/^--(?:filter|testsuite)(?:=.+)?$/', $argument) !== 1) {
                    throw new \RuntimeException('A targeted composer test accepts only --filter or --testsuite options.');
                }
            }
            $result[] = array_values($command);
        }

        return $result;
    }
}
