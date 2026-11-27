<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/**
 * Durable storage and transactional leasing for standalone coder jobs.
 *
 * This store is deliberately independent of the Bot Database. Every instance owns its PDO connection;
 * there is no static connection or process-global state. Active states are starting, running,
 * reconciling, waiting_ci_review and awaiting_action. Assignment alone moves queued to starting.
 * The other valid non-terminal transitions are:
 *
 * - starting -> running, reconciling, waiting_ci_review, awaiting_action
 * - running -> reconciling, waiting_ci_review, awaiting_action
 * - reconciling -> running, waiting_ci_review, awaiting_action (or queued via requeue())
 * - waiting_ci_review -> running, reconciling, awaiting_action
 * - awaiting_action -> starting, running, reconciling, waiting_ci_review
 *
 * Any non-terminal job can be cancelled. A current lease holder can finish a job as done or failed.
 * Terminal jobs never return to the assignment queue. Expiry only moves a job to reconciling; an
 * explicit requeue() after reconciliation makes it assignable with a new lease generation.
 */
final class Store
{
    public const string QUEUED = 'queued';
    public const string STARTING = 'starting';
    public const string RUNNING = 'running';
    public const string RECONCILING = 'reconciling';
    public const string WAITING_CI_REVIEW = 'waiting_ci_review';
    public const string AWAITING_ACTION = 'awaiting_action';
    public const string CANCELLED = 'cancelled';
    public const string FAILED = 'failed';
    public const string DONE = 'done';

    public const array STATES = [
        self::QUEUED,
        self::STARTING,
        self::RUNNING,
        self::RECONCILING,
        self::WAITING_CI_REVIEW,
        self::AWAITING_ACTION,
        self::CANCELLED,
        self::FAILED,
        self::DONE,
    ];

    private const array TERMINAL_STATES = [self::CANCELLED, self::FAILED, self::DONE];

    private const array TRANSITIONS = [
        self::STARTING => [self::RUNNING, self::RECONCILING, self::WAITING_CI_REVIEW, self::AWAITING_ACTION],
        self::RUNNING => [self::RECONCILING, self::WAITING_CI_REVIEW, self::AWAITING_ACTION],
        self::RECONCILING => [self::RUNNING, self::WAITING_CI_REVIEW, self::AWAITING_ACTION],
        self::WAITING_CI_REVIEW => [self::RUNNING, self::RECONCILING, self::AWAITING_ACTION],
        self::AWAITING_ACTION => [self::STARTING, self::RUNNING, self::RECONCILING, self::WAITING_CI_REVIEW],
    ];

    private const array MIGRATIONS = [
        1 => <<<'SQL'
            CREATE TABLE IF NOT EXISTS coder_workers (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                active_job_id INTEGER UNIQUE REFERENCES coder_jobs(id) ON DELETE SET NULL,
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS coder_jobs (
                id                       INTEGER PRIMARY KEY AUTOINCREMENT,
                repository               TEXT NOT NULL,
                issue_number             INTEGER,
                pull_request_number      INTEGER,
                branch                   TEXT NOT NULL,
                worktree                 TEXT,
                session_id               TEXT,
                worker_id                INTEGER REFERENCES coder_workers(id) ON DELETE SET NULL,
                current_head             TEXT,
                state                    TEXT NOT NULL DEFAULT 'queued' CHECK (state IN (
                    'queued', 'starting', 'running', 'reconciling', 'waiting_ci_review',
                    'awaiting_action', 'cancelled', 'failed', 'done'
                )),
                lease_token              TEXT,
                lease_generation         INTEGER NOT NULL DEFAULT 0,
                leased_at                INTEGER,
                heartbeat_at             INTEGER,
                lease_expires_at         INTEGER,
                cancellation_requested   INTEGER NOT NULL DEFAULT 0,
                cancellation_requested_at INTEGER,
                cancelled_at             INTEGER,
                retry_count              INTEGER NOT NULL DEFAULT 0,
                dispute_count            INTEGER NOT NULL DEFAULT 0,
                last_error               TEXT,
                progress                 TEXT,
                diagnostics              TEXT NOT NULL DEFAULT '{}',
                created_at               INTEGER NOT NULL,
                updated_at               INTEGER NOT NULL,
                completed_at             INTEGER
            );

            CREATE TABLE IF NOT EXISTS coder_processed_events (
                repository  TEXT NOT NULL,
                event_key   TEXT NOT NULL,
                job_id      INTEGER REFERENCES coder_jobs(id) ON DELETE SET NULL,
                processed_at INTEGER NOT NULL,
                PRIMARY KEY (repository, event_key)
            );

            CREATE TABLE IF NOT EXISTS coder_job_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id     INTEGER NOT NULL REFERENCES coder_jobs(id) ON DELETE CASCADE,
                kind       TEXT NOT NULL CHECK (kind IN ('retry', 'dispute')),
                reason     TEXT NOT NULL,
                payload    TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL
            );
            SQL,
        2 => <<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS coder_one_active_job_per_worker
                ON coder_jobs(worker_id)
                WHERE worker_id IS NOT NULL AND state IN (
                    'starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action'
                );
            CREATE UNIQUE INDEX IF NOT EXISTS coder_one_active_pr_per_worker
                ON coder_jobs(worker_id, pull_request_number)
                WHERE worker_id IS NOT NULL AND pull_request_number IS NOT NULL AND state IN (
                    'starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action'
                );
            CREATE INDEX IF NOT EXISTS coder_jobs_queue ON coder_jobs(state, id);
            CREATE INDEX IF NOT EXISTS coder_jobs_lease ON coder_jobs(lease_expires_at);
            CREATE INDEX IF NOT EXISTS coder_job_events_job ON coder_job_events(job_id, id);
            SQL,
    ];

    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $stateDir = 'state',
        private readonly int $busyTimeoutMs = 5000,
    ) {
        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('The SQLite busy timeout cannot be negative.');
        }
        $this->connection();
    }

    public function path(): string
    {
        return $this->stateDir.'/coder.sqlite';
    }

    /** Close only this instance's connection. The next operation reconnects and migrates. */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->connection();
    }

    /** @return array{journal_mode:string, busy_timeout:int, foreign_keys:int} */
    public function connectionSettings(): array
    {
        $pdo = $this->connection();

        return [
            'journal_mode' => (string) $this->query($pdo, 'PRAGMA journal_mode')->fetchColumn(),
            'busy_timeout' => (int) $this->query($pdo, 'PRAGMA busy_timeout')->fetchColumn(),
            'foreign_keys' => (int) $this->query($pdo, 'PRAGMA foreign_keys')->fetchColumn(),
        ];
    }

    public function schemaVersion(): int
    {
        return (int) $this->query($this->connection(), 'SELECT COALESCE(MAX(version), 0) FROM coder_schema_migrations')->fetchColumn();
    }

    /** @return list<int> */
    public function migrationVersions(): array
    {
        $rows = $this->query($this->connection(), 'SELECT version FROM coder_schema_migrations ORDER BY version')->fetchAll();

        return array_values(array_map(static fn (array $row): int => (int) $row['version'], $rows));
    }

    /** Register a stable worker identity, or allocate one when no id is supplied. */
    public function registerWorker(?int $workerId = null, ?int $now = null): int
    {
        $now ??= time();
        $pdo = $this->connection();
        if ($workerId !== null) {
            if ($workerId < 1) {
                throw new \InvalidArgumentException('A worker id must be positive.');
            }
            $pdo->prepare('INSERT OR IGNORE INTO coder_workers (id, created_at, updated_at) VALUES (:id, :now, :now)')
                ->execute(['id' => $workerId, 'now' => $now]);

            return $workerId;
        }
        $pdo->prepare('INSERT INTO coder_workers (created_at, updated_at) VALUES (:now, :now)')->execute(['now' => $now]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    public function enqueue(
        string $repository,
        string $branch,
        ?int $issueNumber = null,
        ?int $pullRequestNumber = null,
        ?string $worktree = null,
        ?string $sessionId = null,
        ?string $currentHead = null,
        array $diagnostics = [],
        ?int $now = null,
    ): int {
        $now ??= time();
        $this->connection()->prepare(
            <<<'SQL'
            INSERT INTO coder_jobs (
                repository, issue_number, pull_request_number, branch, worktree, session_id,
                current_head, state, diagnostics, created_at, updated_at
            ) VALUES (
                :repository, :issue, :pr, :branch, :worktree, :session,
                :head, 'queued', :diagnostics, :now, :now
            )
            SQL
        )->execute([
            'repository' => $repository,
            'issue' => $issueNumber,
            'pr' => $pullRequestNumber,
            'branch' => $branch,
            'worktree' => $worktree,
            'session' => $sessionId,
            'head' => $currentHead,
            'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR),
            'now' => $now,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function job(int $jobId): ?array
    {
        $stmt = $this->connection()->prepare('SELECT * FROM coder_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::jobRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function jobs(?string $state = null): array
    {
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Unknown coder job state '{$state}'.");
        }
        if ($state === null) {
            $stmt = $this->query($this->connection(), 'SELECT * FROM coder_jobs ORDER BY id');
        } else {
            $stmt = $this->connection()->prepare('SELECT * FROM coder_jobs WHERE state = :state ORDER BY id');
            $stmt->execute(['state' => $state]);
        }

        return array_values(array_map(self::jobRow(...), $stmt->fetchAll()));
    }

    /** @return array{id:int, active_job_id:?int, created_at:int, updated_at:int}|null */
    public function worker(int $workerId): ?array
    {
        $stmt = $this->connection()->prepare('SELECT * FROM coder_workers WHERE id = :id');
        $stmt->execute(['id' => $workerId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::workerRow($row);
    }

    /** @return list<array{id:int, active_job_id:?int, created_at:int, updated_at:int}> */
    public function workers(): array
    {
        $rows = $this->query($this->connection(), 'SELECT * FROM coder_workers ORDER BY id')->fetchAll();

        return array_values(array_map(self::workerRow(...), $rows));
    }

    /**
     * Atomically select one queued job and one free worker. A worker has capacity for exactly one
     * active job. A supplied worker id restricts assignment to that worker; otherwise the lowest
     * free worker id is selected. No rows are changed when either side is unavailable.
     *
     * @return array{job_id:int, worker_id:int, lease_token:string, lease_generation:int, lease_expires_at:int}|null
     */
    public function assign(?int $workerId = null, int $leaseSeconds = 300, ?int $now = null): ?array
    {
        if ($leaseSeconds < 1) {
            throw new \InvalidArgumentException('A lease must have a positive duration.');
        }
        $now ??= time();
        $lease = $this->transaction(function (\PDO $pdo) use ($workerId, $leaseSeconds, $now): ?array {
            $worker = $this->freeWorker($pdo, $workerId);
            if ($worker === null) {
                return null;
            }
            $jobId = $this->query(
                $pdo,
                "SELECT id FROM coder_jobs WHERE state = 'queued' AND cancellation_requested = 0 ORDER BY id LIMIT 1"
            )->fetchColumn();
            if ($jobId === false) {
                return null;
            }
            $jobId = (int) $jobId;
            $token = bin2hex(random_bytes(16));
            $expiresAt = $now + $leaseSeconds;
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = 'starting', worker_id = :worker, lease_token = :token,
                       lease_generation = lease_generation + 1, leased_at = :now,
                       heartbeat_at = :now, lease_expires_at = :expires, updated_at = :now,
                       completed_at = NULL
                 WHERE id = :job AND state = 'queued' AND worker_id IS NULL AND cancellation_requested = 0
                SQL
            );
            $stmt->execute(['worker' => $worker, 'token' => $token, 'now' => $now, 'expires' => $expiresAt, 'job' => $jobId]);
            if ($stmt->rowCount() !== 1) {
                return null;
            }
            $stmt = $pdo->prepare(
                'UPDATE coder_workers SET active_job_id = :job, updated_at = :now WHERE id = :worker AND active_job_id IS NULL'
            );
            $stmt->execute(['job' => $jobId, 'now' => $now, 'worker' => $worker]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('The selected coder worker became busy during assignment.');
            }

            $generation = $pdo->prepare('SELECT lease_generation FROM coder_jobs WHERE id = :job');
            $generation->execute(['job' => $jobId]);

            return [
                'job_id' => $jobId,
                'worker_id' => $worker,
                'lease_token' => $token,
                'lease_generation' => (int) $generation->fetchColumn(),
                'lease_expires_at' => $expiresAt,
            ];
        });

        return $lease;
    }

    /** Extend a live lease only when both worker id and unguessable lease token match. */
    public function heartbeat(int $jobId, int $workerId, string $leaseToken, int $leaseSeconds = 300, ?int $now = null): bool
    {
        if ($leaseSeconds < 1) {
            throw new \InvalidArgumentException('A lease must have a positive duration.');
        }
        $now ??= time();
        $stmt = $this->connection()->prepare(
            <<<'SQL'
            UPDATE coder_jobs
               SET heartbeat_at = :now, lease_expires_at = :expires, updated_at = :now
             WHERE id = :job AND worker_id = :worker AND lease_token = :token
               AND lease_expires_at > :now
               AND state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action')
            SQL
        );

        return $stmt->execute(['now' => $now, 'expires' => $now + $leaseSeconds, 'job' => $jobId, 'worker' => $workerId, 'token' => $leaseToken])
            && $stmt->rowCount() === 1;
    }

    /** Return a freshly assigned job to the queue when its local run lock is temporarily busy. */
    public function releaseLease(int $jobId, int $workerId, string $leaseToken, ?int $now = null): bool
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $workerId, $leaseToken, $now): bool {
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = 'queued', worker_id = NULL, lease_token = NULL,
                       leased_at = NULL, heartbeat_at = NULL, lease_expires_at = NULL, updated_at = :now
                 WHERE id = :job AND worker_id = :worker AND lease_token = :token
                   AND state = 'starting' AND lease_expires_at > :now AND cancellation_requested = 0
                SQL
            );
            $stmt->execute(['now' => $now, 'job' => $jobId, 'worker' => $workerId, 'token' => $leaseToken]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }
            $pdo->prepare('UPDATE coder_workers SET active_job_id = NULL, updated_at = :now WHERE id = :worker AND active_job_id = :job')
                ->execute(['now' => $now, 'worker' => $workerId, 'job' => $jobId]);

            return true;
        });
    }

    /**
     * Update execution identity and diagnostics without weakening the lease guard.
     * Null values leave the corresponding stored value unchanged.
     *
     * @param array<string, mixed>|null $diagnostics
     */
    public function updateContext(
        int $jobId,
        int $workerId,
        string $leaseToken,
        ?string $currentHead = null,
        ?string $sessionId = null,
        ?string $worktree = null,
        ?array $diagnostics = null,
        ?string $progress = null,
        ?string $lastError = null,
        ?int $now = null,
    ): bool {
        $now ??= time();
        $stmt = $this->connection()->prepare(
            <<<'SQL'
            UPDATE coder_jobs
               SET current_head = COALESCE(:head, current_head),
                   session_id = COALESCE(:session, session_id),
                   worktree = COALESCE(:worktree, worktree),
                   diagnostics = COALESCE(:diagnostics, diagnostics),
                   progress = COALESCE(:progress, progress),
                   last_error = COALESCE(:error, last_error), updated_at = :now
             WHERE id = :job AND worker_id = :worker AND lease_token = :token
               AND lease_expires_at > :now
               AND state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action')
            SQL
        );

        return $stmt->execute([
            'head' => $currentHead,
            'session' => $sessionId,
            'worktree' => $worktree,
            'diagnostics' => $diagnostics === null ? null : json_encode($diagnostics, JSON_THROW_ON_ERROR),
            'progress' => $progress,
            'error' => $lastError,
            'now' => $now,
            'job' => $jobId,
            'worker' => $workerId,
            'token' => $leaseToken,
        ]) && $stmt->rowCount() === 1;
    }

    /** A lease-holder state transition. queued and terminal states have their own explicit APIs. */
    public function transition(int $jobId, string $state, int $workerId, string $leaseToken, ?int $now = null): bool
    {
        if (!array_key_exists($state, self::TRANSITIONS)) {
            throw new \InvalidArgumentException("State '{$state}' is not a lease-holder transition target.");
        }
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $state, $workerId, $leaseToken, $now): bool {
            $current = $this->job($jobId);
            if ($current === null || !in_array($state, self::TRANSITIONS[$current['state']] ?? [], true)) {
                return false;
            }
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs SET state = :state, updated_at = :now
                 WHERE id = :job AND worker_id = :worker AND lease_token = :token
                   AND lease_expires_at > :now
                   AND state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action')
                SQL
            );
            $stmt->execute(['state' => $state, 'now' => $now, 'job' => $jobId, 'worker' => $workerId, 'token' => $leaseToken]);

            return $stmt->rowCount() === 1;
        });
    }

    /**
     * Reconcile every expired lease and return already reconciling jobs again until they reach a
     * final state. A worker that could not take the local lock therefore cannot strand a job forever.
     * Jobs stop being executable and workers become free, but expired jobs remain reconciling until
     * requeue() explicitly authorizes another assignment.
     *
     * @return list<int> job ids requiring reconciliation
     */
    public function reconcileExpiredLeases(?int $now = null): array
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($now): array {
            $stmt = $pdo->prepare(
                <<<'SQL'
                SELECT id FROM coder_jobs
                 WHERE (state = 'reconciling' AND worker_id IS NULL AND lease_token IS NULL)
                    OR (lease_expires_at IS NOT NULL AND lease_expires_at <= :now
                        AND state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action'))
                 ORDER BY id
                SQL
            );
            $stmt->execute(['now' => $now]);
            $ids = array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
            $release = $pdo->prepare('UPDATE coder_workers SET active_job_id = NULL, updated_at = :now WHERE active_job_id = :job');
            $expire = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = 'reconciling', worker_id = NULL, lease_token = NULL,
                       leased_at = NULL, heartbeat_at = NULL, lease_expires_at = NULL,
                       last_error = 'Lease expired; reconciliation required.', updated_at = :now
                 WHERE id = :job AND lease_expires_at <= :now
                SQL
            );
            foreach ($ids as $id) {
                $release->execute(['now' => $now, 'job' => $id]);
                $expire->execute(['now' => $now, 'job' => $id]);
            }

            return $ids;
        });
    }

    /** Explicitly finish reconciliation and make the job eligible for a fresh lease generation. */
    public function requeue(int $jobId, ?int $now = null): bool
    {
        $now ??= time();
        $stmt = $this->connection()->prepare(
            <<<'SQL'
            UPDATE coder_jobs SET state = 'queued', updated_at = :now
             WHERE id = :job AND state = 'reconciling' AND worker_id IS NULL AND lease_token IS NULL
               AND cancellation_requested = 0
            SQL
        );
        $stmt->execute(['now' => $now, 'job' => $jobId]);

        return $stmt->rowCount() === 1;
    }

    /** A failed reconciliation is terminal and retains the diagnostics needed for manual recovery. */
    public function failReconciliation(int $jobId, string $error, ?int $now = null): bool
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $error, $now): bool {
            $job = $this->job($jobId);
            if ($job === null || $job['state'] !== self::RECONCILING || $job['worker_id'] !== null) {
                return false;
            }
            $diagnostics = $job['diagnostics'];
            $diagnostics['reconciliation_error'] = $error;
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = 'failed', last_error = :error, diagnostics = :diagnostics,
                       completed_at = :now, updated_at = :now
                 WHERE id = :job AND state = 'reconciling' AND worker_id IS NULL AND lease_token IS NULL
                SQL
            );
            $stmt->execute([
                'error' => $error,
                'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR),
                'now' => $now,
                'job' => $jobId,
            ]);

            return $stmt->rowCount() === 1;
        });
    }

    /** Cancellation is durable and terminal; it also releases worker capacity transactionally. */
    public function cancel(int $jobId, ?int $now = null, ?string $reason = null): bool
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $now, $reason): bool {
            $job = $this->job($jobId);
            if ($job === null || in_array($job['state'], self::TERMINAL_STATES, true)) {
                return false;
            }
            $diagnostics = $job['diagnostics'];
            if ($reason !== null) {
                $diagnostics['cancellation'] = ['reason' => $reason, 'requested_at' => $now];
            }
            if ($job['worker_id'] !== null) {
                $pdo->prepare('UPDATE coder_workers SET active_job_id = NULL, updated_at = :now WHERE id = :worker AND active_job_id = :job')
                    ->execute(['now' => $now, 'worker' => $job['worker_id'], 'job' => $jobId]);
            }
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = 'cancelled', cancellation_requested = 1, cancellation_requested_at = :now,
                       cancelled_at = :now, completed_at = :now, lease_token = NULL,
                       leased_at = NULL, heartbeat_at = NULL, lease_expires_at = NULL, updated_at = :now,
                       diagnostics = :diagnostics
                 WHERE id = :job AND state NOT IN ('cancelled', 'failed', 'done')
                SQL
            );
            $stmt->execute(['now' => $now, 'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR), 'job' => $jobId]);

            return $stmt->rowCount() === 1;
        });
    }

    /** Preserve a process cleanup error without changing the already durable cancelled outcome. */
    public function recordCancellationError(int $jobId, string $error, ?int $now = null): bool
    {
        $now ??= time();
        $job = $this->job($jobId);
        if ($job === null || $job['state'] !== self::CANCELLED) {
            return false;
        }
        $diagnostics = $job['diagnostics'];
        $diagnostics['cancellation']['reconciliation_error'] = $error;
        $stmt = $this->connection()->prepare(
            "UPDATE coder_jobs SET diagnostics = :diagnostics, last_error = :error, updated_at = :now
             WHERE id = :job AND state = 'cancelled'"
        );
        $stmt->execute([
            'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR),
            'error' => $error,
            'now' => $now,
            'job' => $jobId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed>|null $diagnostics */
    public function complete(int $jobId, int $workerId, string $leaseToken, ?array $diagnostics = null, ?int $now = null): bool
    {
        return $this->finish($jobId, $workerId, $leaseToken, self::DONE, null, $diagnostics, $now);
    }

    /** @param array<string, mixed>|null $diagnostics */
    public function fail(int $jobId, int $workerId, string $leaseToken, string $error, ?array $diagnostics = null, ?int $now = null): bool
    {
        return $this->finish($jobId, $workerId, $leaseToken, self::FAILED, $error, $diagnostics, $now);
    }

    /** Record an intake event exactly once within a repository. */
    public function recordProcessedEvent(string $repository, string $eventKey, ?int $jobId = null, ?int $now = null): bool
    {
        $stmt = $this->connection()->prepare(
            'INSERT OR IGNORE INTO coder_processed_events (repository, event_key, job_id, processed_at) VALUES (:repository, :key, :job, :now)'
        );
        $stmt->execute(['repository' => $repository, 'key' => $eventKey, 'job' => $jobId, 'now' => $now ?? time()]);

        return $stmt->rowCount() === 1;
    }

    /** @return list<string> */
    public function processedEventKeys(int $jobId): array
    {
        $stmt = $this->connection()->prepare('SELECT event_key FROM coder_processed_events WHERE job_id = :job ORDER BY event_key');
        $stmt->execute(['job' => $jobId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Record an explicit retry without changing the job state. A later, deliberate queue operation
     * must decide whether retrying is valid; polling cannot resurrect work by writing this event.
     *
     * @param array<string, mixed> $payload
     */
    public function recordRetry(int $jobId, string $reason, array $payload = [], ?int $now = null): int
    {
        return $this->recordCounterEvent($jobId, 'retry', $reason, $payload, $now);
    }

    /** @param array<string, mixed> $payload */
    public function recordDispute(int $jobId, string $reason, array $payload = [], ?int $now = null): int
    {
        return $this->recordCounterEvent($jobId, 'dispute', $reason, $payload, $now);
    }

    /** @return list<array{id:int, job_id:int, kind:string, reason:string, payload:array<string, mixed>, created_at:int}> */
    public function events(int $jobId): array
    {
        $stmt = $this->connection()->prepare('SELECT * FROM coder_job_events WHERE job_id = :job ORDER BY id');
        $stmt->execute(['job' => $jobId]);
        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $events[] = [
                'id' => (int) $row['id'],
                'job_id' => (int) $row['job_id'],
                'kind' => (string) $row['kind'],
                'reason' => (string) $row['reason'],
                'payload' => is_array($payload) ? $payload : [],
                'created_at' => (int) $row['created_at'],
            ];
        }

        return $events;
    }

    private function connection(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!is_dir($this->stateDir) && !mkdir($this->stateDir, 0777, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException("Unable to create coder state directory '{$this->stateDir}'.");
        }
        $pdo = new \PDO('sqlite:'.$this->path());
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = '.$this->busyTimeoutMs);
        $journal = $this->enableWal($pdo);
        if (strtolower($journal) !== 'wal') {
            throw new \RuntimeException('The coder store could not enable SQLite WAL journaling.');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo = $pdo;
        $this->migrate();

        return $pdo;
    }

    private function migrate(): void
    {
        $this->transaction(function (\PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS coder_schema_migrations (version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)'
            );
            $applied = array_map('intval', $this->query($pdo, 'SELECT version FROM coder_schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
            $current = $applied === [] ? 0 : max($applied);
            foreach (self::MIGRATIONS as $version => $sql) {
                if (in_array($version, $applied, true)) {
                    continue;
                }
                if ($version !== $current + 1) {
                    throw new \RuntimeException("Coder schema migration {$version} is out of order.");
                }
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO coder_schema_migrations (version, applied_at) VALUES (:version, :now)')
                    ->execute(['version' => $version, 'now' => time()]);
                $current = $version;
            }
        });
    }

    /**
     * Changing journal mode can report SQLITE_BUSY immediately instead of invoking SQLite's busy
     * handler. Retry that specific error for the configured timeout while another process migrates.
     */
    private function enableWal(\PDO $pdo): string
    {
        $deadline = hrtime(true) + $this->busyTimeoutMs * 1_000_000;
        while (true) {
            try {
                return (string) $this->query($pdo, 'PRAGMA journal_mode = WAL')->fetchColumn();
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 5 || hrtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(10_000);
            }
        }
    }

    private function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException('SQLite could not execute a coder store query.');
        }

        return $stmt;
    }

    private function freeWorker(\PDO $pdo, ?int $workerId): ?int
    {
        $sql = <<<'SQL'
            SELECT w.id FROM coder_workers w
             WHERE w.active_job_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM coder_jobs j
                    WHERE j.worker_id = w.id
                      AND j.state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action')
               )
            SQL;
        if ($workerId === null) {
            $stmt = $this->query($pdo, $sql.' ORDER BY w.id LIMIT 1');
        } else {
            $stmt = $pdo->prepare($sql.' AND w.id = :worker');
            $stmt->execute(['worker' => $workerId]);
        }
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed>|null $diagnostics */
    private function finish(int $jobId, int $workerId, string $leaseToken, string $state, ?string $error, ?array $diagnostics, ?int $now): bool
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $workerId, $leaseToken, $state, $error, $diagnostics, $now): bool {
            $stmt = $pdo->prepare(
                <<<'SQL'
                UPDATE coder_jobs
                   SET state = :state, last_error = :error, diagnostics = COALESCE(:diagnostics, diagnostics),
                       completed_at = :now, updated_at = :now, lease_token = NULL,
                       leased_at = NULL, heartbeat_at = NULL, lease_expires_at = NULL
                 WHERE id = :job AND worker_id = :worker AND lease_token = :token
                   AND lease_expires_at > :now
                   AND state IN ('starting', 'running', 'reconciling', 'waiting_ci_review', 'awaiting_action')
                SQL
            );
            $stmt->execute([
                'state' => $state,
                'error' => $error,
                'diagnostics' => $diagnostics === null ? null : json_encode($diagnostics, JSON_THROW_ON_ERROR),
                'now' => $now,
                'job' => $jobId,
                'worker' => $workerId,
                'token' => $leaseToken,
            ]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }
            $pdo->prepare('UPDATE coder_workers SET active_job_id = NULL, updated_at = :now WHERE id = :worker AND active_job_id = :job')
                ->execute(['now' => $now, 'worker' => $workerId, 'job' => $jobId]);

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    private function recordCounterEvent(int $jobId, string $kind, string $reason, array $payload, ?int $now): int
    {
        $now ??= time();

        return $this->transaction(function (\PDO $pdo) use ($jobId, $kind, $reason, $payload, $now): int {
            $column = $kind === 'retry' ? 'retry_count' : 'dispute_count';
            $stmt = $pdo->prepare("UPDATE coder_jobs SET {$column} = {$column} + 1, updated_at = :now WHERE id = :job");
            $stmt->execute(['now' => $now, 'job' => $jobId]);
            if ($stmt->rowCount() !== 1) {
                throw new \InvalidArgumentException("Coder job {$jobId} does not exist.");
            }
            $pdo->prepare('INSERT INTO coder_job_events (job_id, kind, reason, payload, created_at) VALUES (:job, :kind, :reason, :payload, :now)')
                ->execute([
                    'job' => $jobId,
                    'kind' => $kind,
                    'reason' => $reason,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'now' => $now,
                ]);

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * SQLite's immediate transaction takes the write lock before reading either side of assignment.
     * Together with conditional updates and unique active-job indexes this closes the read-then-write
     * race between independent processes.
     *
     * @template T
     *
     * @param \Closure(\PDO):T $operation
     *
     * @return T
     */
    private function transaction(\Closure $operation): mixed
    {
        $pdo = $this->connection();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation($pdo);
            $pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // Preserve the original failure; a lost connection is re-established on next use.
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function jobRow(array $row): array
    {
        foreach (['id', 'issue_number', 'pull_request_number', 'worker_id', 'leased_at', 'heartbeat_at', 'lease_expires_at', 'cancellation_requested_at', 'cancelled_at', 'completed_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        foreach (['lease_generation', 'retry_count', 'dispute_count', 'created_at', 'updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['cancellation_requested'] = (int) $row['cancellation_requested'] === 1;
        $diagnostics = json_decode((string) $row['diagnostics'], true, 512, JSON_THROW_ON_ERROR);
        $row['diagnostics'] = is_array($diagnostics) ? $diagnostics : [];
        unset($row['lease_token']);

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array{id:int, active_job_id:?int, created_at:int, updated_at:int}
     */
    private static function workerRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'active_job_id' => $row['active_job_id'] === null ? null : (int) $row['active_job_id'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }
}
