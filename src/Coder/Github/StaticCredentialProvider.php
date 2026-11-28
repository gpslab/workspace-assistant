<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** A named service-user credential. Coder credentials must never request workflow scope. */
final readonly class StaticCredentialProvider implements CredentialProvider
{
    /** @param list<string> $scopes */
    public function __construct(
        private string $login,
        private string $token,
        private string $configDirectory,
        private array $scopes = [],
        private bool $coder = false,
    ) {
        if (trim($login) === '' || $token === '' || $configDirectory === '') {
            throw new \InvalidArgumentException('A named GitHub credential needs a login, token and isolated config directory.');
        }
        if ($coder && array_any($scopes, static fn (string $scope): bool => strcasecmp(trim($scope), 'workflow') === 0)) {
            throw new \InvalidArgumentException('The coder GitHub credential must not request workflow scope.');
        }
    }

    public function expectedLogin(): string
    {
        return $this->login;
    }

    public function apiEnvironment(): array
    {
        return [
            'GH_TOKEN' => $this->token,
            'GH_CONFIG_DIR' => $this->configDirectory,
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    public function pushEnvironment(): array
    {
        return $this->apiEnvironment() + [
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$this->token),
        ];
    }
}
