<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Publication\CleanupScheduler;
use GpsLab\WorkspaceAssistant\Coder\Publication\LifecycleController;
use GpsLab\WorkspaceAssistant\Coder\Publication\PublicationController;
use GpsLab\WorkspaceAssistant\Coder\Store;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(LifecycleController::class)]
#[CoversClass(CleanupScheduler::class)]
final class LifecycleControllerTest extends PublicationTestCase
{
    /** @return iterable<string, array{string,string}> */
    public static function terminalStates(): iterable
    {
        yield 'merged pull request' => ['merged', Store::DONE];
        yield 'closed unmerged pull request' => ['pr_closed', Store::CANCELLED];
        yield 'closed issue' => ['issue_closed', Store::CANCELLED];
        yield 'author cancellation' => ['cancel', Store::CANCELLED];
    }

    #[DataProvider('terminalStates')]
    public function testTerminalHandlingReleasesEverythingAndSuppressesLaterWork(string $cause, string $expected): void
    {
        $request = $this->job();
        $this->controller()->publish($request, 'Title', 'Body');
        $lifecycle = $this->lifecycle();
        if ($cause === 'merged' || $cause === 'pr_closed') {
            $this->transport->pullRequests['acme/repo#70']['state'] = 'closed';
            $this->transport->pullRequests['acme/repo#70']['merged'] = $cause === 'merged';
            if ($cause === 'merged') {
                $this->transport->issues['acme/repo#7']['state'] = 'closed';
            }
        } elseif ($cause === 'issue_closed') {
            $this->transport->issues['acme/repo#7']['state'] = 'closed';
        }

        // Re-adding a lifecycle label after publication must not defeat terminal cleanup.
        $this->transport->issues['acme/repo#7']['labels'][] = ['name' => PublicationController::DO_IT];
        $state = $cause === 'cancel' ? $lifecycle->cancel($request) : $lifecycle->poll($request);

        self::assertSame($expected, $state);
        $job = $this->store->job($request->jobId);
        self::assertNotNull($job);
        self::assertSame($expected, $job['state']);
        self::assertSame([], $this->transport->issues['acme/repo#7']['labels']);
        self::assertArrayNotHasKey('acme/repo:'.$request->branch, $this->transport->branches);
        self::assertNull($job['worker_id']);
        self::assertNull($job['lease_expires_at']);
        $worker = $this->store->worker(1);
        self::assertNotNull($worker);
        self::assertNull($worker['active_job_id']);
        self::assertSame($this->now, $job['cursor_closed_at']);
        self::assertSame($this->now, $job['cleanup_scheduled_at']);
        self::assertSame($request->worktree, $job['cleanup_path']);
        self::assertSame([['argv' => ['composer', 'test'], 'exit' => 0]], $job['diagnostics']['checks']['full']);
        self::assertSame($expected, $job['diagnostics']['terminal']['state']);
        self::assertNull($this->store->assign(1, 300, 110));
        self::assertCount(1, $this->store->jobs());
        $calls = count($this->transport->calls);
        self::assertSame($expected, $lifecycle->poll($request));
        self::assertSame($calls, count($this->transport->calls), 'Terminal polling emits no new GitHub work.');
    }

    public function testExplicitRetryAfterClosedPullRequestPublishesANewHeadAndPullRequest(): void
    {
        $first = $this->job();
        $controller = $this->controller();
        $controller->publish($first, 'First attempt', 'Body');
        $this->transport->pullRequests['acme/repo#70']['state'] = 'closed';
        self::assertSame(Store::CANCELLED, $this->lifecycle()->poll($first));

        $retry = $this->job(content: "retry change\n", retryOf: $first);
        self::assertSame($first->branch, $retry->branch);
        self::assertNotSame($first->head, $retry->head);
        $published = $controller->publish($retry, 'Retry', 'Body');

        self::assertSame(71, $published['pull_request']['number']);
        self::assertSame('open', $published['pull_request']['state']);
        self::assertSame('closed', $this->transport->pullRequests['acme/repo#70']['state']);
        self::assertSame($retry->head, $this->transport->branches['acme/repo:'.$retry->branch]);
        $job = $this->store->job($retry->jobId);
        self::assertNotNull($job);
        self::assertSame(71, $job['pull_request_number']);
    }

    public function testCancellationAfterRejectedDiffSkipsWorktreeValidationForTerminalCleanup(): void
    {
        $request = $this->job('.env.production', "secret=true\n");
        try {
            $this->controller()->publish($request, 'Title', 'Body');
            self::fail('The protected path must reject publication.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('protected', $e->getMessage());
        }
        rename($request->worktree.'/.git', $request->worktree.'/.git-unavailable');

        self::assertSame(Store::CANCELLED, $this->lifecycle()->cancel($request));

        self::assertSame([], $this->transport->issues['acme/repo#7']['labels']);
        $job = $this->store->job($request->jobId);
        self::assertNotNull($job);
        self::assertSame(Store::CANCELLED, $job['state']);
        self::assertNotNull($job['cleanup_scheduled_at']);
        $worker = $this->store->worker(1);
        self::assertNotNull($worker);
        self::assertNull($worker['active_job_id']);
    }

    public function testUncertainTerminalLabelRemovalIsReconciledBeforeRelease(): void
    {
        $request = $this->job();
        $this->controller()->publish($request, 'Title', 'Body');
        $lifecycle = $this->lifecycle();
        $this->transport->crashAfter = 'remove_label';
        try {
            $lifecycle->cancel($request);
            self::fail('The injected terminal-label crash must stop release.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }
        $pending = $this->store->job($request->jobId);
        $leasedWorker = $this->store->worker(1);
        self::assertNotNull($pending);
        self::assertNotNull($leasedWorker);
        self::assertSame(Store::WAITING_CI_REVIEW, $pending['state']);
        self::assertSame($request->jobId, $leasedWorker['active_job_id']);

        self::assertSame(Store::CANCELLED, $lifecycle->cancel($request));
        $removals = array_filter($this->transport->writes('remove_label'), static fn (array $call): bool => $call['parameters']['label'] === PublicationController::IN_PROGRESS);
        self::assertCount(1, $removals);
        $terminal = $this->store->job($request->jobId);
        self::assertNotNull($terminal);
        self::assertSame(Store::CANCELLED, $terminal['state']);
        $worker = $this->store->worker(1);
        self::assertNotNull($worker);
        self::assertNull($worker['active_job_id']);
    }

    private function lifecycle(): LifecycleController
    {
        return new LifecycleController(
            $this->store,
            $this->clients,
            $this->controller(),
            new CleanupScheduler($this->store, $this->root.'/managed', $this->worktreeRoot),
            fn (): int => $this->now,
        );
    }
}
