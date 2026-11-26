<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

final class CheckRunner
{
    /** @var array<string, string> */
    private readonly array $environment;

    public function __construct(private readonly int $timeout)
    {
        $environment = ExecutionEnvironment::sanitize();
        $environment['BASH_DEFAULT_TIMEOUT_MS'] = (string) Config::BASH_DEFAULT_TIMEOUT_MS;
        $environment['BASH_MAX_TIMEOUT_MS'] = (string) Config::BASH_MAX_TIMEOUT_MS;
        $this->environment = $environment;
    }

    /**
     * @param list<list<string>>   $commands
     * @param \Closure():void|null $onTick
     *
     * @return list<array{argv:list<string>, exit:int, seconds:float, timed_out:bool}>
     */
    public function run(array $commands, string $worktree, ?\Closure $onTick = null): array
    {
        $records = [];
        foreach ($commands as $argv) {
            $started = microtime(true);
            $result = Process::run($argv, $worktree, $this->timeout, $this->environment, $onTick);
            $records[] = [
                'argv' => $argv,
                'exit' => $result['exit'],
                'seconds' => round(microtime(true) - $started, 3),
                'timed_out' => $result['timed_out'],
            ];
        }

        return $records;
    }
}
