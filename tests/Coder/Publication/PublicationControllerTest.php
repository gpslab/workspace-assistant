<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\GithubClient;
use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\Publication\PublicationController;
use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\Coder\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(PublicationController::class)]
final class PublicationControllerTest extends PublicationTestCase
{
    public function testEveryCoderWriteIncludingPushUsesCoderAndReviewerStaysReviewer(): void
    {
        $request = $this->job();
        $this->transport->staleCreatedTitle = true;
        $controller = $this->controller();

        $published = $controller->publish($request, 'Implement fixture', 'Body');
        $controller->markReady($request);
        $controller->deleteBranch($request);
        $this->clients->forAction(IdentityRouter::REVIEW)->comment('acme/repo', 7, 'Reviewer operation');

        self::assertSame(70, $published['pull_request']['number']);
        $job = $this->store->job($request->jobId);
        self::assertNotNull($job);
        self::assertSame(Store::WAITING_CI_REVIEW, $job['state']);
        $coderWrites = array_slice($this->transport->writes(), 0, -1);
        self::assertNotEmpty($coderWrites);
        foreach ($coderWrites as $call) {
            self::assertSame('coder', $call['actor'], $call['action']);
        }
        self::assertSame('reviewer', $this->transport->writes('comment')[1]['actor']);
        self::assertTrue($this->transport->writes('push')[0]['push']);
        self::assertSame(
            ['push', 'create_pr', 'update_pr', 'add_label', 'remove_label', 'comment', 'ready', 'delete_branch'],
            array_column($coderWrites, 'action'),
        );
        self::assertStringContainsString('<!-- openronin:bot -->', $this->transport->comments['acme/repo#7'][0]['body']);
        self::assertSame([['name' => PublicationController::IN_PROGRESS]], $this->transport->issues['acme/repo#7']['labels']);
    }

    public function testNewLeaseGenerationReusesConfirmedJobAndHeadKeys(): void
    {
        $request = $this->job();
        $controller = $this->controller();
        $controller->publish($request, 'Implement fixture', 'Body');
        self::assertSame([$request->jobId], $this->store->reconcileExpiredLeases(400));
        self::assertTrue($this->store->requeue($request->jobId, 401));
        $lease = $this->store->assign(1, 300, 402);
        self::assertNotNull($lease);
        self::assertSame(2, $lease['lease_generation']);
        $job = $this->store->job($request->jobId);
        self::assertNotNull($job);
        $diagnostics = $job['diagnostics'];
        $diagnostics['local_result']['lease_generation'] = 2;
        self::assertTrue($this->store->updateContext(
            $request->jobId, 1, $lease['lease_token'], diagnostics: $diagnostics, now: 403,
        ));
        self::assertTrue($this->store->transition($request->jobId, Store::RUNNING, 1, $lease['lease_token'], 404));
        self::assertTrue($this->store->transition($request->jobId, Store::AWAITING_ACTION, 1, $lease['lease_token'], 405));
        $retry = new \GpsLab\WorkspaceAssistant\Coder\PublicationRequest(
            $request->jobId, $request->runId, $request->branch, $request->worktree, $request->head,
            $request->sessionId, 1, $lease['lease_token'], 2,
        );
        $this->now = 406;

        $controller->publish($retry, 'Implement fixture', 'Body');

        self::assertCount(1, $this->transport->pullRequests);
        self::assertCount(1, $this->transport->comments['acme/repo#7']);
        self::assertCount(1, $this->transport->writes('push'));
    }

    public function testExistingBranchAndPullRequestAreReconciledWithoutDuplicates(): void
    {
        $request = $this->job();
        $this->transport->branches['acme/repo:'.$request->branch] = $request->head;
        $this->transport->pullRequests['acme/repo#91'] = $this->transport->newPr('acme/repo', 91, $request->branch, 'Stale title', 'Body');

        $result = $this->controller()->publish($request, 'Implement fixture', 'Body');

        self::assertSame(91, $result['pull_request']['number']);
        $job = $this->store->job($request->jobId);
        self::assertNotNull($job);
        self::assertSame(91, $job['pull_request_number']);
        self::assertSame([], $this->transport->writes('push'));
        self::assertSame([], $this->transport->writes('create_pr'));
        self::assertCount(1, $this->transport->writes('update_pr'));
        self::assertSame('Implement fixture', $result['pull_request']['title']);
        self::assertCount(1, $this->transport->comments['acme/repo#7']);
    }

    public function testReadyRetryReconcilesRestDraftStateWithoutRepeatingTheWrite(): void
    {
        $request = $this->job();
        $controller = $this->controller();
        $controller->publish($request, 'Implement fixture', 'Body');
        $this->transport->crashAfter = 'ready';
        try {
            $controller->markReady($request);
            self::fail('The injected readiness crash must surface as an uncertain outcome.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }

        $controller->markReady($request);

        self::assertCount(1, $this->transport->writes('ready'));
        self::assertFalse($this->transport->pullRequests['acme/repo#70']['draft']);
    }

    public function testExpiredLeaseRejectsAllWrites(): void
    {
        $request = $this->job();
        $this->now = 400;

        try {
            $this->controller()->publish($request, 'Title', 'Body');
            self::fail('An expired lease must fail closed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('lease', $e->getMessage());
        }
        self::assertSame([], $this->transport->writes());
    }

    public function testCancellationAfterLocalCompletionRejectsAllWrites(): void
    {
        $request = $this->job();
        self::assertTrue($this->store->requestCancellation($request->jobId, 1, $request->leaseToken, 'author', 104));

        $this->expectException(\RuntimeException::class);
        try {
            $this->controller()->publish($request, 'Title', 'Body');
        } finally {
            self::assertSame([], $this->transport->writes());
        }
    }

    public function testStaleStoredHeadRejectsAllWrites(): void
    {
        $request = $this->job();
        self::assertTrue($this->store->updateContext(
            $request->jobId, 1, $request->leaseToken, currentHead: str_repeat('a', 40), now: 104,
        ));

        $this->expectException(\RuntimeException::class);
        try {
            $this->controller()->publish($request, 'Title', 'Body');
        } finally {
            self::assertSame([], $this->transport->writes());
        }
    }

    /** @return iterable<string, array{string,string,list<string>}> */
    public static function protectedDiffs(): iterable
    {
        yield 'workflow' => ['.github/workflows/ci.yml', "safe: true\n", []];
        yield 'secret file' => ['.env.production', "safe=true\n", []];
        yield 'secret content' => ['src/config.txt', "token=ghp_abcdefghijklmnopqrstuvwxyzABCDEFGHIJ1234\n", []];
        yield 'configured path' => ['config/private/policy.txt', "safe\n", ['config/private/*']];
    }

    /** @param list<string> $protected */
    #[DataProvider('protectedDiffs')]
    public function testProtectedOrSecretDiffRejectsBeforePush(string $path, string $content, array $protected): void
    {
        $request = $this->job($path, $content);

        $this->expectException(\RuntimeException::class);
        try {
            $this->controller($protected)->publish($request, 'Title', 'Body');
        } finally {
            self::assertSame([], $this->transport->writes());
        }
    }

    public function testFailedFullDiffValidationRejectsBeforePush(): void
    {
        $request = $this->job();
        rename($request->worktree.'/.git', $request->worktree.'/.git-unavailable');

        $this->expectException(\RuntimeException::class);
        try {
            $this->controller()->publish($request, 'Title', 'Body');
        } finally {
            self::assertSame([], $this->transport->writes());
        }
    }

    /** @return iterable<string, array{string,string,int}> */
    public static function crashPoints(): iterable
    {
        foreach (['push', 'create_pr', 'add_label', 'comment'] as $action) {
            yield $action.' before' => [$action, 'before', 2];
            yield $action.' after' => [$action, 'after', 1];
        }
    }

    #[DataProvider('crashPoints')]
    public function testCrashRecoveryReconcilesBeforeRetryWithoutDuplicateSideEffects(string $action, string $when, int $attempts): void
    {
        $request = $this->job();
        $this->transport->{'crash'.ucfirst($when)} = $action;
        $controller = $this->controller();
        try {
            $controller->publish($request, 'Title', 'Body');
            self::fail('The injected crash must surface as an uncertain outcome.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }

        $controller->publish($request, 'Title', 'Body');

        self::assertCount($attempts, $this->transport->writes($action));
        self::assertCount(1, $this->transport->pullRequests);
        self::assertCount(1, $this->transport->comments['acme/repo#7']);
        self::assertSame([$request->head], array_values($this->transport->branches));
        self::assertSame([['name' => PublicationController::IN_PROGRESS]], $this->transport->issues['acme/repo#7']['labels']);
        foreach ($this->transport->writes() as $call) {
            self::assertSame('coder', $call['actor']);
        }
    }

    public function testWorkerHasNoGithubWriteClientOrPublicationMethod(): void
    {
        $constructor = new \ReflectionMethod(Worker::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertFalse($type instanceof \ReflectionNamedType && is_a($type->getName(), GithubClient::class, true));
        }
        $public = array_filter((new \ReflectionClass(Worker::class))->getMethods(\ReflectionMethod::IS_PUBLIC), static fn (\ReflectionMethod $method): bool => !$method->isConstructor());
        self::assertSame(['runNext', 'cancel'], array_column($public, 'name'));
    }
}
