<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

use GpsLab\WorkspaceAssistant\Env;

/** Runtime configuration for the two separately named controller service users. */
final class CredentialProviders
{
    /** @return array{reviewer:StaticCredentialProvider, coder:StaticCredentialProvider} */
    public static function fromEnv(Env $env, string $stateDirectory): array
    {
        return [
            'reviewer' => new StaticCredentialProvider(
                $env->require('CODER_REVIEWER_GITHUB_LOGIN'),
                $env->require('CODER_REVIEWER_GITHUB_TOKEN'),
                $stateDirectory.'/github-reviewer',
            ),
            'coder' => new StaticCredentialProvider(
                $env->require('CODER_GITHUB_LOGIN'),
                $env->require('CODER_GITHUB_TOKEN'),
                $stateDirectory.'/github-coder',
                array_values($env->list('CODER_GITHUB_TOKEN_SCOPES')),
                true,
            ),
        ];
    }
}
