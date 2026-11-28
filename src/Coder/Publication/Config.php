<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Env;

final readonly class Config
{
    /** @param list<string> $protectedPaths */
    public function __construct(
        public string $baseBranch = 'master',
        public array $protectedPaths = [],
        public int $requestTimeoutSeconds = 30,
    ) {
        if ($baseBranch === '' || $requestTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Coder publication needs a base branch and a positive request timeout.');
        }
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            (string) $env->get('CODER_BASE_BRANCH', 'master'),
            array_values($env->list('CODER_PROTECTED_PATHS')),
            max(1, (int) $env->get('CODER_PUBLICATION_TIMEOUT', '30')),
        );
    }
}
