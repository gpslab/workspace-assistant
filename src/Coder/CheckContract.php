<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Repository-provided check commands; no shell is involved. */
final class CheckContract
{
    /**
     * @param array<string, mixed> $jobDiagnostics
     *
     * @return array{targeted:list<list<string>>, full:list<list<string>>}
     */
    public static function forRepository(string $repository, array $jobDiagnostics): array
    {
        $composer = $repository.'/composer.json';
        if (!is_file($composer)) {
            throw new \RuntimeException('The target repository has no supported local check contract.');
        }
        /** @var array{scripts?:array<string, mixed>}|null $data */
        $data = json_decode((string) file_get_contents($composer), true);
        $scripts = is_array($data) && is_array($data['scripts'] ?? null) ? array_keys($data['scripts']) : [];
        foreach (['cs:check', 'stan', 'test'] as $required) {
            if (!in_array($required, $scripts, true)) {
                throw new \RuntimeException("The target repository check contract lacks composer {$required}.");
            }
        }
        $targeted = $jobDiagnostics['targeted_checks'] ?? [['composer', 'test']];
        if (!is_array($targeted) || !array_is_list($targeted)) {
            throw new \RuntimeException('targeted_checks must be a list of argv arrays.');
        }

        return [
            'targeted' => self::commands($targeted),
            'full' => [['composer', 'cs:check'], ['composer', 'stan'], ['composer', 'test']],
        ];
    }

    /**
     * @param list<mixed> $commands
     *
     * @return list<list<string>>
     */
    private static function commands(array $commands): array
    {
        $result = [];
        foreach ($commands as $command) {
            if (!is_array($command) || $command === [] || array_any($command, static fn (mixed $arg): bool => !is_string($arg) || $arg === '')) {
                throw new \RuntimeException('Every local check must be a non-empty argv string array.');
            }
            $result[] = array_values($command);
        }

        return $result;
    }
}
