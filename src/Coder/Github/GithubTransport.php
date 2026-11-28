<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** Authenticated process boundary. Every call receives its identity environment explicitly. */
interface GithubTransport
{
    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function call(string $action, string $repository, array $parameters, array $environment, int $timeoutSeconds): array;
}
