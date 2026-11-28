<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

interface CredentialProvider
{
    public function expectedLogin(): string;

    /** @return array<string, string> child-process environment, never a global environment mutation */
    public function apiEnvironment(): array;

    /** @return array<string, string> the git push identity, independently explicit from the API identity */
    public function pushEnvironment(): array;
}
