<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\Github\NamedGithubClient;
use GpsLab\WorkspaceAssistant\Coder\Github\StartupValidation;
use GpsLab\WorkspaceAssistant\Coder\Github\StaticCredentialProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NamedGithubClient::class)]
#[CoversClass(IdentityRouter::class)]
#[CoversClass(StartupValidation::class)]
#[CoversClass(StaticCredentialProvider::class)]
final class IdentityTest extends PublicationTestCase
{
    public function testNamedClientsHaveIndependentCachesAndNeverMutateGlobalAuthorization(): void
    {
        putenv('GH_TOKEN=inherited-global');
        putenv('GH_CONFIG_DIR=/inherited/global-config');
        try {
            self::assertSame('reviewer', $this->clients->reviewer->selfLogin());
            self::assertSame('coder', $this->clients->coder->selfLogin());
            $this->transport->actors = ['reviewer-token' => 'changed-reviewer', 'coder-token' => 'changed-coder'];
            self::assertSame('reviewer', $this->clients->reviewer->selfLogin());
            self::assertSame('coder', $this->clients->coder->selfLogin());
            self::assertSame(['self', 'self'], array_column($this->transport->calls, 'action'));
            self::assertSame('inherited-global', getenv('GH_TOKEN'));
            self::assertSame('/inherited/global-config', getenv('GH_CONFIG_DIR'));
        } finally {
            putenv('GH_TOKEN');
            putenv('GH_CONFIG_DIR');
        }
    }

    public function testStartupValidatesDistinctActorsAndReadOnlyRepositoryProbes(): void
    {
        (new StartupValidation($this->clients))->validate('human', ['acme/repo']);

        self::assertSame(['reviewer', 'coder', 'reviewer', 'coder'], array_column($this->transport->calls, 'actor'));
        self::assertSame(['self', 'self', 'probe', 'probe'], array_column($this->transport->calls, 'action'));
    }

    public function testStartupRejectsSharedHumanReviewerAndCoderLogins(): void
    {
        $this->transport->actors = ['one' => 'shared', 'two' => 'shared'];
        $clients = new IdentityRouter(
            new NamedGithubClient($this->transport, new StaticCredentialProvider('shared', 'one', $this->stateDir.'/one')),
            new NamedGithubClient($this->transport, new StaticCredentialProvider('shared', 'two', $this->stateDir.'/two')),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different actors');
        (new StartupValidation($clients))->validate('shared', []);
    }

    public function testStartupRejectsFailedNonMutatingProbe(): void
    {
        $this->transport->failedProbes['acme/repo'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe');
        (new StartupValidation($this->clients))->validate('human', ['acme/repo']);
    }

    public function testCoderCredentialCannotRequestWorkflowScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workflow');
        new StaticCredentialProvider('coder', 'token', $this->stateDir.'/coder', ['repo', 'workflow'], true);
    }

    public function testUnknownActionHasNoImplicitIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clients->forAction('unspecified');
    }
}
