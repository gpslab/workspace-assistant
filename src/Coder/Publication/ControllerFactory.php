<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\CredentialProviders;
use GpsLab\WorkspaceAssistant\Coder\Github\GhCliTransport;
use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\Github\NamedGithubClient;
use GpsLab\WorkspaceAssistant\Coder\Github\StartupValidation;
use GpsLab\WorkspaceAssistant\Coder\Routing;
use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\Env;

/** Explicit controller composition. It starts no worker and configures no production OS user. */
final readonly class ControllerFactory
{
    /** @return array{publication:PublicationController, lifecycle:LifecycleController} */
    public static function fromEnv(Env $env, Store $store, string $repository, string $stateDirectory, string $worktreeDirectory): array
    {
        $config = Config::fromEnv($env);
        $providers = CredentialProviders::fromEnv($env, $stateDirectory);
        $transport = new GhCliTransport($repository);
        $clients = new IdentityRouter(
            new NamedGithubClient($transport, $providers['reviewer'], $config->requestTimeoutSeconds),
            new NamedGithubClient($transport, $providers['coder'], $config->requestTimeoutSeconds),
        );
        // Fail fast on actor aliasing and available read access. This is not a future-write proof.
        (new StartupValidation($clients))->validate(
            $env->require('CODER_INTAKE_AUTHOR'), Routing::fromEnv($env)->coderRepositories(),
        );
        $publication = new PublicationController($store, $clients, new DiffGuard($config->protectedPaths), $config);

        return [
            'publication' => $publication,
            'lifecycle' => new LifecycleController(
                $store, $clients, $publication, new CleanupScheduler($store, $repository, $worktreeDirectory),
            ),
        ];
    }
}
