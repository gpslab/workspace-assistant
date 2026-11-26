<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Store::class)]
#[CoversClass(Database::class)]
final class StoreTest extends TestCase
{
    private string $dir;
    private Store $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/coder-store-'.uniqid('', true);
        $this->store = new Store($this->dir);
    }

    protected function tearDown(): void
    {
        $this->store->disconnect();
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testStoreIsIndependentMigratedAndConfiguredForMultipleProcesses(): void
    {
        new Database($this->dir);
        $other = new Store($this->dir, 4321);

        self::assertFileExists($this->dir.'/state.sqlite');
        self::assertFileExists($this->dir.'/coder.sqlite');
        self::assertSame($this->dir.'/coder.sqlite', $other->path());
        self::assertSame(2, $other->schemaVersion());
        self::assertSame([1, 2], $other->migrationVersions());
        self::assertSame(
            ['journal_mode' => 'wal', 'busy_timeout' => 4321, 'foreign_keys' => 1],
            $other->connectionSettings()
        );

        $other->enqueue('example/one', 'coder/one');
        $other->enqueue('example/two', 'coder/two');
        self::assertCount(2, $this->store->jobs());
        $other->disconnect();
    }

    public function testMigrationsAreOrderedIdempotentAndResumeAnOlderVersion(): void
    {
        $this->store->enqueue('example/repo', 'coder/one');
        $this->store->reconnect();
        self::assertSame([1, 2], $this->store->migrationVersions());
        self::assertCount(1, $this->store->jobs());

        $this->store->disconnect();
        $pdo = new \PDO('sqlite:'.$this->dir.'/coder.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP INDEX coder_one_active_pr_per_worker');
        $pdo->exec('DELETE FROM coder_schema_migrations WHERE version = 2');
        $pdo = null;

        $upgraded = new Store($this->dir);
        self::assertSame([1, 2], $upgraded->migrationVersions());
        $index = $upgraded->connectionSettings();
        self::assertSame('wal', $index['journal_mode']);
        $pdo = new \PDO('sqlite:'.$this->dir.'/coder.sqlite');
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'coder_one_active_pr_per_worker'");
        self::assertNotFalse($stmt);
        $found = $stmt->fetchColumn();
        self::assertSame(1, (int) $found);
        $upgraded->disconnect();
    }

    public function testConcurrentProcessesMigrateFreshStoreAndRecoverConnections(): void
    {
        $dir = $this->dir.'/fresh';
        mkdir($dir);
        $open = static function () use ($dir): array {
            $store = new Store($dir, 2345);
            $version = $store->schemaVersion();
            $store->disconnect();
            $settings = $store->connectionSettings();
            $store->disconnect();

            return ['version' => $version, 'settings' => $settings];
        };

        try {
            $results = $this->concurrently([$open, $open]);
            self::assertSame([2, 2], array_column($results, 'version'));
            self::assertSame(['wal', 'wal'], array_column(array_column($results, 'settings'), 'journal_mode'));
            self::assertSame([2345, 2345], array_column(array_column($results, 'settings'), 'busy_timeout'));

            $store = new Store($dir);
            $id = $store->enqueue('example/repo', 'coder/concurrent-migration');
            $store->disconnect();
            self::assertSame($id, $store->job($id)['id'] ?? null);
            $store->disconnect();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testForcedDisconnectAndExplicitReconnectPreserveJobs(): void
    {
        $id = $this->store->enqueue('example/repo', 'coder/reconnect');

        $this->store->disconnect();
        self::assertSame($id, $this->store->jobs()[0]['id']);
        $this->store->reconnect();
        self::assertSame('example/repo', $this->job($id)['repository']);
    }

    public function testCollectionsIdentityFieldsAndLifecycleSurviveMultipleJobsAndWorkers(): void
    {
        self::assertSame(11, $this->store->registerWorker(11, 10));
        self::assertSame(22, $this->store->registerWorker(22, 10));
        $first = $this->store->enqueue(
            repository: 'example/repo',
            branch: 'coder/issue-7',
            issueNumber: 7,
            pullRequestNumber: 70,
            worktree: '/tmp/worktree-7',
            sessionId: 'session-7',
            currentHead: 'aaa111',
            diagnostics: ['source' => 'test'],
            now: 20,
        );
        $second = $this->store->enqueue('example/other', 'coder/issue-8', issueNumber: 8, now: 21);

        $lease = $this->store->assign(11, 60, 30);
        self::assertNotNull($lease);
        self::assertSame(['job_id' => $first, 'worker_id' => 11, 'lease_generation' => 1, 'lease_expires_at' => 90], [
            'job_id' => $lease['job_id'],
            'worker_id' => $lease['worker_id'],
            'lease_generation' => $lease['lease_generation'],
            'lease_expires_at' => $lease['lease_expires_at'],
        ]);
        self::assertSame($first, $this->worker(11)['active_job_id']);
        self::assertNull($this->store->assign(11, 60, 31), 'A busy worker cannot take a second job.');

        self::assertTrue($this->store->updateContext(
            $first,
            11,
            $lease['lease_token'],
            currentHead: 'bbb222',
            sessionId: 'session-70',
            worktree: '/tmp/worktree-70',
            diagnostics: ['step' => 'tests', 'attempt' => 1],
            progress: 'Running tests',
            now: 32,
        ));
        self::assertFalse($this->store->transition($first, Store::STARTING, 11, $lease['lease_token'], 33));
        self::assertTrue($this->store->transition($first, Store::RUNNING, 11, $lease['lease_token'], 33));
        self::assertTrue($this->store->transition($first, Store::WAITING_CI_REVIEW, 11, $lease['lease_token'], 34));
        self::assertTrue($this->store->transition($first, Store::AWAITING_ACTION, 11, $lease['lease_token'], 35));

        $job = $this->job($first);
        self::assertSame('example/repo', $job['repository']);
        self::assertSame(7, $job['issue_number']);
        self::assertSame(70, $job['pull_request_number']);
        self::assertSame('coder/issue-7', $job['branch']);
        self::assertSame('/tmp/worktree-70', $job['worktree']);
        self::assertSame('session-70', $job['session_id']);
        self::assertSame('bbb222', $job['current_head']);
        self::assertSame(Store::AWAITING_ACTION, $job['state']);
        self::assertSame(['step' => 'tests', 'attempt' => 1], $job['diagnostics']);
        self::assertSame('Running tests', $job['progress']);
        self::assertArrayNotHasKey('lease_token', $job);
        self::assertArrayNotHasKey('lease_token', $this->store->jobs()[0]);

        $secondLease = $this->store->assign(22, 60, 40);
        self::assertNotNull($secondLease);
        self::assertSame($second, $secondLease['job_id']);
        self::assertCount(2, $this->store->workers());
        self::assertCount(2, $this->store->jobs());
        self::assertSame([$second], array_column($this->store->jobs(Store::STARTING), 'id'));
        self::assertSame([$first], array_column($this->store->jobs(Store::AWAITING_ACTION), 'id'));
    }

    public function testHeartbeatRequiresCurrentOwnershipAndExtendsTheLease(): void
    {
        $this->store->registerWorker(1);
        $id = $this->store->enqueue('example/repo', 'coder/heartbeat');
        $lease = $this->store->assign(1, 20, 100);
        self::assertNotNull($lease);

        self::assertFalse($this->store->heartbeat($id, 2, $lease['lease_token'], 20, 101));
        self::assertFalse($this->store->heartbeat($id, 1, 'wrong-token', 20, 101));
        self::assertTrue($this->store->heartbeat($id, 1, $lease['lease_token'], 30, 105));
        self::assertSame(105, $this->job($id)['heartbeat_at']);
        self::assertSame(135, $this->job($id)['lease_expires_at']);
        self::assertFalse($this->store->heartbeat($id, 1, $lease['lease_token'], 30, 135));
    }

    public function testExpiredLeaseRejectsCompletionFailureAndTransitionBeforeReconciliation(): void
    {
        $ids = [];
        foreach (['complete', 'fail', 'transition'] as $index => $operation) {
            $workerId = $index + 1;
            $this->store->registerWorker($workerId, 100);
            $id = $this->store->enqueue('example/repo', 'coder/expired-'.$operation, now: 100);
            $ids[] = $id;
            $lease = $this->store->assign($workerId, 10, 100);
            self::assertNotNull($lease);
            $before = $this->job($id);

            $updated = match ($operation) {
                'complete' => $this->store->complete($id, $workerId, $lease['lease_token'], now: 110),
                'fail' => $this->store->fail($id, $workerId, $lease['lease_token'], 'late failure', now: 110),
                'transition' => $this->store->transition($id, Store::RUNNING, $workerId, $lease['lease_token'], 110),
            };
            self::assertFalse($updated, "An expired lease cannot {$operation} a job before reconciliation.");
            self::assertSame($before, $this->job($id));
            self::assertSame($id, $this->worker($workerId)['active_job_id']);
        }

        self::assertSame($ids, $this->store->reconcileExpiredLeases(110));
    }

    public function testExpiredLeaseNeedsReconciliationAndOldTokenCannotTakeOver(): void
    {
        $this->store->registerWorker(1);
        $this->store->registerWorker(2);
        $id = $this->store->enqueue('example/repo', 'coder/expiry');
        $old = $this->store->assign(1, 10, 100);
        self::assertNotNull($old);

        self::assertFalse($this->store->heartbeat($id, 1, $old['lease_token'], 10, 110));
        self::assertSame([$id], $this->store->reconcileExpiredLeases(110));
        self::assertSame(Store::RECONCILING, $this->job($id)['state']);
        self::assertNull($this->job($id)['worker_id']);
        self::assertNull($this->worker(1)['active_job_id']);
        self::assertNull($this->store->assign(2, 10, 110), 'Reconciling is not assignable without requeue.');
        self::assertFalse($this->store->complete($id, 1, $old['lease_token'], [], 110));

        self::assertTrue($this->store->requeue($id, 111));
        $new = $this->store->assign(2, 10, 112);
        self::assertNotNull($new);
        self::assertSame(2, $new['lease_generation']);
        self::assertNotSame($old['lease_token'], $new['lease_token']);
        self::assertFalse($this->store->heartbeat($id, 1, $old['lease_token'], 10, 113));
        self::assertFalse($this->store->transition($id, Store::RUNNING, 1, $old['lease_token'], 113));
        self::assertFalse($this->store->fail($id, 1, $old['lease_token'], 'stale', [], 113));
        self::assertTrue($this->store->heartbeat($id, 2, $new['lease_token'], 10, 113));
    }

    public function testCompletionAndFailureReleaseCapacityAndNeverResurrectTerminalJobs(): void
    {
        $this->store->registerWorker(1);
        $done = $this->store->enqueue('example/repo', 'coder/done');
        $failed = $this->store->enqueue('example/repo', 'coder/failed');
        $doneLease = $this->store->assign(null, 30, 100);
        self::assertNotNull($doneLease);
        self::assertSame(1, $doneLease['worker_id']);
        self::assertTrue($this->store->complete($done, 1, $doneLease['lease_token'], ['result' => 'ok'], 110));
        self::assertSame(Store::DONE, $this->job($done)['state']);
        self::assertSame(['result' => 'ok'], $this->job($done)['diagnostics']);
        self::assertNull($this->worker(1)['active_job_id']);

        $failedLease = $this->store->assign(1, 30, 111);
        self::assertNotNull($failedLease);
        self::assertSame($failed, $failedLease['job_id']);
        self::assertTrue($this->store->updateContext(
            $failed,
            1,
            $failedLease['lease_token'],
            diagnostics: ['step' => 'test'],
            now: 112,
        ));
        self::assertTrue($this->store->fail($failed, 1, $failedLease['lease_token'], 'tests failed', now: 113));
        self::assertSame(Store::FAILED, $this->job($failed)['state']);
        self::assertSame('tests failed', $this->job($failed)['last_error']);
        self::assertSame(['step' => 'test'], $this->job($failed)['diagnostics']);
        self::assertFalse($this->store->requeue($done, 114));
        self::assertFalse($this->store->requeue($failed, 114));
        self::assertNull($this->store->assign(1, 30, 114));
        self::assertFalse($this->store->complete($done, 1, $doneLease['lease_token'], [], 115));
    }

    public function testCancellationIsDurableTerminalAndObservedAfterReload(): void
    {
        $this->store->registerWorker(5);
        $id = $this->store->enqueue('example/repo', 'coder/cancel');
        $lease = $this->store->assign(5, 30, 100);
        self::assertNotNull($lease);

        self::assertTrue($this->store->cancel($id, 105));
        $reloaded = new Store($this->dir);
        $job = $reloaded->job($id);
        self::assertNotNull($job);
        self::assertSame(Store::CANCELLED, $job['state']);
        self::assertTrue($job['cancellation_requested']);
        self::assertSame(105, $job['cancellation_requested_at']);
        self::assertSame(105, $job['cancelled_at']);
        $worker = $reloaded->worker(5);
        self::assertNotNull($worker);
        self::assertNull($worker['active_job_id']);
        self::assertFalse($reloaded->heartbeat($id, 5, $lease['lease_token'], 30, 106));
        self::assertFalse($reloaded->cancel($id, 107));
        self::assertNull($reloaded->assign(5, 30, 108));
        $reloaded->disconnect();
    }

    public function testProcessedKeysAreIdempotentAndRetriesAndDisputesAreExplicitRows(): void
    {
        $id = $this->store->enqueue('example/repo', 'coder/events');
        self::assertTrue($this->store->recordProcessedEvent('example/repo', 'delivery-1', $id, 10));
        self::assertFalse($this->store->recordProcessedEvent('example/repo', 'delivery-1', $id, 11));
        self::assertTrue($this->store->recordProcessedEvent('example/other', 'delivery-1', null, 12));
        self::assertSame(['delivery-1'], $this->store->processedEventKeys($id));

        $retry = $this->store->recordRetry($id, 'transient failure', ['head' => 'abc'], 20);
        $dispute = $this->store->recordDispute($id, 'ownership disputed', ['worker' => 3], 21);
        self::assertSame(1, $retry);
        self::assertSame(2, $dispute);
        self::assertSame(Store::QUEUED, $this->job($id)['state']);
        self::assertSame(1, $this->job($id)['retry_count']);
        self::assertSame(1, $this->job($id)['dispute_count']);
        $events = $this->store->events($id);
        self::assertSame(['retry', 'dispute'], array_column($events, 'kind'));
        self::assertSame(['head' => 'abc'], $events[0]['payload']);
        self::assertSame(['worker' => 3], $events[1]['payload']);
    }

    public function testConcurrentProcessesCannotLeaseOneJobToTwoWorkers(): void
    {
        $this->store->registerWorker(1);
        $this->store->registerWorker(2);
        $id = $this->store->enqueue('example/repo', 'coder/race');

        $results = $this->concurrentAssignments([1, 2]);
        $won = array_values(array_filter($results, static fn (array $result): bool => $result['lease'] !== null));

        self::assertCount(1, $won, 'Exactly one process must win the queued job.');
        $winningLease = $won[0]['lease'];
        self::assertSame($id, $winningLease['job_id']);
        self::assertSame($winningLease['worker_id'], $this->job($id)['worker_id']);
        self::assertSame(1, $this->job($id)['lease_generation']);
        self::assertCount(1, array_filter($this->store->workers(), static fn (array $worker): bool => $worker['active_job_id'] !== null));
    }

    public function testConcurrentProcessesCannotExceedOneJobWorkerCapacity(): void
    {
        $this->store->registerWorker(1);
        $this->store->enqueue('example/repo', 'coder/first');
        $this->store->enqueue('example/repo', 'coder/second');

        $results = $this->concurrentAssignments([1, 1]);
        $won = array_values(array_filter($results, static fn (array $result): bool => $result['lease'] !== null));

        self::assertCount(1, $won, 'A free worker wins once; the competing process sees no capacity.');
        self::assertCount(1, $this->store->jobs(Store::STARTING));
        self::assertCount(1, $this->store->jobs(Store::QUEUED));
    }

    public function testHeartbeatExpiryCancellationAndReloadAcrossProcessTermination(): void
    {
        $this->store->registerWorker(9);
        $id = $this->store->enqueue('example/repo', 'coder/process');
        $results = $this->concurrentAssignments([9]);
        $lease = $results[0]['lease'];
        self::assertNotNull($lease);

        $heartbeat = $this->inProcess(function () use ($id, $lease): array {
            $store = new Store($this->dir);
            $ok = $store->heartbeat($id, 9, $lease['lease_token'], 50, 105);
            $updated = $store->updateContext(
                $id,
                9,
                $lease['lease_token'],
                currentHead: 'head-after-restart',
                sessionId: 'session-after-restart',
                worktree: '/tmp/worktree-after-restart',
                diagnostics: ['step' => 'persisted'],
                progress: 'Checkpoint persisted',
                now: 105,
            );
            $store->recordRetry($id, 'retry after restart', ['source' => 'process'], 106);
            $store->recordDispute($id, 'dispute after restart', ['source' => 'process'], 107);
            $store->recordProcessedEvent('example/repo', 'process-event', $id, 108);
            $store->disconnect();

            return [
                'ok' => $ok,
                'updated' => $updated,
                'job' => $store->job($id) ?? throw new \RuntimeException('The assigned job disappeared.'),
                'events' => $store->events($id),
            ];
        });
        self::assertTrue($heartbeat['ok']);
        self::assertTrue($heartbeat['updated']);
        self::assertSame(155, $heartbeat['job']['lease_expires_at']);
        self::assertSame('head-after-restart', $heartbeat['job']['current_head']);
        self::assertSame('session-after-restart', $heartbeat['job']['session_id']);
        self::assertSame('/tmp/worktree-after-restart', $heartbeat['job']['worktree']);
        self::assertSame(['step' => 'persisted'], $heartbeat['job']['diagnostics']);
        self::assertSame('Checkpoint persisted', $heartbeat['job']['progress']);
        self::assertSame(1, $heartbeat['job']['retry_count']);
        self::assertSame(1, $heartbeat['job']['dispute_count']);
        self::assertCount(2, $heartbeat['events']);
        self::assertSame(['process-event'], $this->store->processedEventKeys($id));

        $expired = $this->inProcess(function () use ($id): array {
            $store = new Store($this->dir);

            return ['ids' => $store->reconcileExpiredLeases(155), 'job' => $store->job($id) ?? throw new \RuntimeException('The reconciled job disappeared.')];
        });
        self::assertSame([$id], $expired['ids']);
        self::assertSame(Store::RECONCILING, $expired['job']['state']);

        self::assertTrue($this->store->requeue($id, 156));
        $cancelled = $this->inProcess(function () use ($id): array {
            $store = new Store($this->dir);
            $store->cancel($id, 157);
            $store->disconnect();

            return $store->job($id) ?? throw new \RuntimeException('The cancelled job disappeared.');
        });
        self::assertSame(Store::CANCELLED, $cancelled['state']);
        self::assertTrue($cancelled['cancellation_requested']);
        self::assertSame(1, $cancelled['retry_count']);
        self::assertSame(1, $cancelled['dispute_count']);
        self::assertSame(['process-event'], $this->store->processedEventKeys($id));
    }

    /** @return array<string, mixed> */
    private function job(int $id): array
    {
        $job = $this->store->job($id);
        self::assertNotNull($job);

        return $job;
    }

    /** @return array{id:int, active_job_id:?int, created_at:int, updated_at:int} */
    private function worker(int $id): array
    {
        $worker = $this->store->worker($id);
        self::assertNotNull($worker);

        return $worker;
    }

    /**
     * @param list<int> $workerIds
     *
     * @return list<array{lease:array<string, mixed>|null}>
     */
    private function concurrentAssignments(array $workerIds): array
    {
        $operations = array_map(fn (int $workerId): \Closure => function () use ($workerId): array {
            $store = new Store($this->dir);

            return ['lease' => $store->assign($workerId, 30, 100)];
        }, $workerIds);

        return $this->concurrently($operations);
    }

    /**
     * Start real PHP processes, hold them at a barrier, then release all operations together.
     *
     * @param list<\Closure():mixed> $operations
     *
     * @return list<mixed>
     */
    private function concurrently(array $operations): array
    {
        $children = [];
        foreach ($operations as $index => $operation) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($pair);
            [$parent, $child] = $pair;
            $resultFile = $this->dir.'/concurrent-'.$index.'-'.uniqid('', true).'.json';
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                try {
                    $result = $operation();
                    file_put_contents($resultFile, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                    $code = 0;
                } catch (\Throwable $e) {
                    file_put_contents($resultFile, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                    $code = 1;
                }
                fclose($child);
                // exec avoids running the parent's PHPUnit shutdown handlers in the fork.
                pcntl_exec(PHP_BINARY, ['-r', 'exit('.$code.');']);
                exit(1);
            }
            fclose($child);
            $children[] = ['pid' => $pid, 'socket' => $parent, 'file' => $resultFile];
        }
        foreach ($children as $child) {
            fwrite($child['socket'], 'x');
            fclose($child['socket']);
        }
        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $raw = file_get_contents($child['file']);
            self::assertNotFalse($raw);
            self::assertSame(0, pcntl_wexitstatus($status), $raw);
            $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result);
            $results[] = $result['result'];
            unlink($child['file']);
        }

        return $results;
    }

    /** Run one operation in a process that exits before the parent reloads the store. */
    private function inProcess(\Closure $operation): mixed
    {
        $resultFile = $this->dir.'/process-'.uniqid('', true).'.json';
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            try {
                $result = $operation();
                file_put_contents($resultFile, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                $code = 0;
            } catch (\Throwable $e) {
                file_put_contents($resultFile, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                $code = 1;
            }
            pcntl_exec(PHP_BINARY, ['-r', 'exit('.$code.');']);
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        $raw = file_get_contents($resultFile);
        self::assertNotFalse($raw);
        self::assertSame(0, pcntl_wexitstatus($status), $raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        unlink($resultFile);
        self::assertArrayNotHasKey('error', $data);

        return $data['result'];
    }
}
