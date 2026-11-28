<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** Fail-fast actor and read-access validation; it makes no universal future-write permission claim. */
final readonly class StartupValidation
{
    public function __construct(private IdentityRouter $clients)
    {
    }

    /** @param list<string> $repositories */
    public function validate(string $humanLogin, array $repositories): void
    {
        $human = trim($humanLogin);
        if ($human === '') {
            throw new \RuntimeException('The trusted human GitHub login is required.');
        }
        $reviewer = $this->clients->reviewer->selfLogin();
        $coder = $this->clients->coder->selfLogin();
        $logins = [$human, $reviewer, $coder];
        $normalized = array_map(strtolower(...), $logins);
        if (count(array_unique($normalized)) !== 3) {
            throw new \RuntimeException('The human, reviewer and coder GitHub logins must be three different actors.');
        }
        foreach (array_values(array_unique($repositories)) as $repository) {
            $this->clients->reviewer->probeRepository($repository);
            $this->clients->coder->probeRepository($repository);
        }
    }
}
