<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\MasterRecord;
use GpsLab\WorkspaceAssistant\Epic\Run;

/**
 * Bot state in SQLite. One row per session — keyed by (chat_id, thread_id).
 *
 * thread_id is the forum topic identifier (Telegram forum topic), where 0 means
 * "no topic": a private 1:1 chat and the General topic of a group. Real topics are always > 0
 * (thread_id in Telegram is the topic's message_id), so 0 is a safe synthetic value
 * for the chat's "default" session. NULL is intentionally NOT used for this: in
 * SQLite `NULL != NULL`, and a composite key/UPSERT on NULL silently breeds duplicates.
 *
 * No concurrency: the bot is a single process and handles jobs sequentially.
 * Multiplicity lives only in DB rows, not in the runtime — at any moment
 * at most one job is active (see Bot::$activeJob).
 */
final class Database
{
    private \PDO $pdo;

    public function __construct(string $stateDir)
    {
        $this->pdo = new \PDO('sqlite:'.$stateDir.'/state.sqlite');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->migrate();
        $this->pdo->exec(self::CREATE_QUEUE_SQL);
        // Queue created before the per-item locale: NULL means the bot locale.
        if (!$this->hasColumn('queue', 'locale')) {
            $this->pdo->exec('ALTER TABLE queue ADD COLUMN locale TEXT');
        }
        $this->pdo->exec(self::CREATE_QUEUE_MESSAGES_SQL);
        $this->pdo->exec(self::CREATE_KV_SQL);
        $this->pdo->exec(self::CREATE_EPICS_SQL);
        $this->pdo->exec(self::CREATE_EPIC_TASKS_SQL);
        $this->pdo->exec(self::CREATE_EPIC_RELEASES_SQL);
        if (!$this->hasColumn('epic_releases', 'status')) {
            $this->pdo->exec("ALTER TABLE epic_releases ADD COLUMN status TEXT NOT NULL DEFAULT 'open'");
        }
        if (!$this->hasColumn('epics', 'attention')) {
            $this->pdo->exec('ALTER TABLE epics ADD COLUMN attention TEXT');
        }
        foreach (['last_tick_at', 'stale_ticks', 'final_runs', 'final_retries', 'tails_rounds', 'card_message_id', 'last_poll_at', 'ticks_total'] as $column) {
            if (!$this->hasColumn('epics', $column)) {
                $this->pdo->exec("ALTER TABLE epics ADD COLUMN {$column} INTEGER NOT NULL DEFAULT 0");
            }
        }
        // A DB created before the queue_messages table: the items' first messages are already in the queue.
        $this->pdo->exec('INSERT OR IGNORE INTO queue_messages (chat_id, message_id, queue_id) SELECT chat_id, message_id, id FROM queue');
    }

    private const string CREATE_QUEUE_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS queue (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id        INTEGER NOT NULL,
            thread_id      INTEGER NOT NULL DEFAULT 0,
            message_id     INTEGER NOT NULL,
            kind           TEXT NOT NULL DEFAULT 'chat',
            mode           TEXT NOT NULL,
            parts          TEXT NOT NULL DEFAULT '[]',
            placeholder_id INTEGER NOT NULL DEFAULT 0,
            closed         INTEGER NOT NULL DEFAULT 0,
            running        INTEGER NOT NULL DEFAULT 0,
            locale         TEXT,
            UNIQUE (chat_id, message_id)
        )
        SQL;

    /** All message_ids of all messages of an item (first, glued, album frames) — protection against redelivery of updates. */
    private const string CREATE_QUEUE_MESSAGES_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS queue_messages (
            chat_id    INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            queue_id   INTEGER NOT NULL,
            UNIQUE (chat_id, message_id)
        )
        SQL;

    private const string CREATE_KV_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS kv (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        )
        SQL;

    /** Epic: one per topic; fingerprint, last_tick_at and stale_ticks are maintained by the orchestrator, done/blocked_reason are the run's signal. */
    /** Cap on the tail rounds after the epic's final check. */
    public const int MAX_TAILS_ROUNDS = 2;

    private const string CREATE_EPICS_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS epics (
            slug           TEXT PRIMARY KEY,
            chat_id        INTEGER NOT NULL,
            thread_id      INTEGER NOT NULL DEFAULT 0,
            status         TEXT NOT NULL DEFAULT 'running',
            fingerprint    TEXT,
            attention      TEXT,
            blocked_reason TEXT,
            done           INTEGER NOT NULL DEFAULT 0,
            last_tick_at   INTEGER NOT NULL DEFAULT 0,
            stale_ticks    INTEGER NOT NULL DEFAULT 0,
            final_runs     INTEGER NOT NULL DEFAULT 0,
            final_retries  INTEGER NOT NULL DEFAULT 0,
            tails_rounds   INTEGER NOT NULL DEFAULT 0,
            card_message_id INTEGER NOT NULL DEFAULT 0,
            last_poll_at   INTEGER NOT NULL DEFAULT 0
        )
        SQL;

    /** An epic's task set: source = plan|added; counters is JSON with the gates' counters. */
    private const string CREATE_EPIC_TASKS_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS epic_tasks (
            slug     TEXT NOT NULL,
            task     TEXT NOT NULL,
            source   TEXT NOT NULL DEFAULT 'plan',
            after    TEXT NOT NULL DEFAULT '[]',
            hold     INTEGER NOT NULL DEFAULT 0,
            status   TEXT NOT NULL DEFAULT 'open',
            counters TEXT NOT NULL DEFAULT '{}',
            PRIMARY KEY (slug, task)
        )
        SQL;

    /** Contract releases from the plan. */
    private const string CREATE_EPIC_RELEASES_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS epic_releases (
            slug    TEXT NOT NULL,
            repo    TEXT NOT NULL,
            version TEXT NOT NULL,
            after   TEXT NOT NULL DEFAULT '[]',
            status  TEXT NOT NULL DEFAULT 'open',
            PRIMARY KEY (slug, repo, version)
        )
        SQL;

    private function migrate(): void
    {
        // Legacy schema (chat_id PRIMARY KEY, no thread_id) → rebuild into a
        // composite key. SQLite cannot change a PRIMARY KEY via ALTER, so we
        // rebuild the table transactionally: old rows become sessions
        // with thread_id = 0 (the chat's default session).
        if ($this->tableExists('sessions') && !$this->hasColumn('sessions', 'thread_id')) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec('ALTER TABLE sessions RENAME TO sessions_legacy');
                $this->pdo->exec(self::CREATE_SESSIONS_SQL);
                $this->pdo->exec(
                    <<<'SQL'
                    INSERT INTO sessions
                        (chat_id, thread_id, session_id, pid, started_at,
                         message_count, input_tokens, output_tokens, verbose, mode)
                    SELECT
                        chat_id, 0, session_id, pid, started_at,
                        message_count, input_tokens, output_tokens, verbose, 'arch'
                    FROM sessions_legacy
                    SQL
                );
                $this->pdo->exec('DROP TABLE sessions_legacy');
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }

            return;
        }

        $this->pdo->exec(self::CREATE_SESSIONS_SQL);

        // A DB created under the previous schema (thread_id is there, mode is not yet):
        // CREATE TABLE IF NOT EXISTS above is a no-op on an existing table, the column
        // must be added explicitly. The legacy branch above does not get here (early return) —
        // it rebuilds the table from CREATE_SESSIONS_SQL, which already has mode.
        if (!$this->hasColumn('sessions', 'mode')) {
            $this->pdo->exec("ALTER TABLE sessions ADD COLUMN mode TEXT NOT NULL DEFAULT 'arch'");
        }
        // Hash of the system prompt the session was last run with; NULL for rows created before it was tracked.
        if (!$this->hasColumn('sessions', 'prompt_hash')) {
            $this->pdo->exec('ALTER TABLE sessions ADD COLUMN prompt_hash TEXT');
        }
    }

    private const string CREATE_SESSIONS_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS sessions (
            chat_id        INTEGER NOT NULL,
            thread_id      INTEGER NOT NULL DEFAULT 0,
            session_id     TEXT,
            pid            INTEGER,
            started_at     TEXT,
            message_count  INTEGER NOT NULL DEFAULT 0,
            input_tokens   INTEGER NOT NULL DEFAULT 0,
            output_tokens  INTEGER NOT NULL DEFAULT 0,
            verbose        INTEGER NOT NULL DEFAULT 0,
            mode           TEXT NOT NULL DEFAULT 'pm',
            prompt_hash    TEXT,
            PRIMARY KEY (chat_id, thread_id)
        )
        SQL;

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $stmt->execute(['name' => $table]);

        return $stmt->fetch() !== false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        // PRAGMA does not accept placeholders; $table here is only the literal 'sessions'.
        $stmt = $this->pdo->query('PRAGMA table_info('.$table.')');
        if ($stmt === false) {
            return false;
        }
        foreach ($stmt->fetchAll() as $col) {
            if (($col['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(int $chatId, int $threadId = 0): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sessions WHERE chat_id = :chat AND thread_id = :thread'
        );
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function ensureRow(int $chatId, int $threadId): void
    {
        // mode is explicit, not the column DEFAULT: already-created DBs have
        // the old DEFAULT 'arch' baked into the schema, and SQLite cannot change it via ALTER.
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO sessions (chat_id, thread_id, mode) VALUES (:chat, :thread, :mode)'
        );
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId, 'mode' => SystemPrompt::DEFAULT_MODE]);
    }

    public function getSessionId(int $chatId, int $threadId = 0): ?string
    {
        $row = $this->getSession($chatId, $threadId);
        $id = $row['session_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Record the result of a Claude call: session_id, tokens, message counter.
     * A non-null $promptHash replaces the stored system prompt hash; null keeps it.
     */
    public function recordRun(int $chatId, int $threadId, string $sessionId, int $inputTokens, int $outputTokens, ?string $promptHash = null): void
    {
        $this->ensureRow($chatId, $threadId);
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sessions
               SET session_id    = :sid,
                   pid           = NULL,
                   message_count = message_count + 1,
                   input_tokens  = input_tokens + :in,
                   output_tokens = output_tokens + :out,
                   prompt_hash   = COALESCE(:hash, prompt_hash)
             WHERE chat_id = :chat AND thread_id = :thread
            SQL
        );
        $stmt->execute([
            'sid' => $sessionId,
            'in' => $inputTokens,
            'out' => $outputTokens,
            'hash' => $promptHash,
            'chat' => $chatId,
            'thread' => $threadId,
        ]);
    }

    /**
     * Bind session_id to the session WITHOUT touching the counters (messages/tokens).
     *
     * Needed to catch a forked session_id while Claude is still working (from the
     * stream-json init event). Otherwise on /cancel or a timeout the new transcript
     * with the just-sent request would be orphaned: the DB would keep the old
     * session_id (from before the request), and the next "continue" would resume the old session
     * without it. The counters are not touched — recordRun updates them on a successful
     * completion; for a killed run, undercounting one message is acceptable.
     */
    public function setSessionId(int $chatId, int $threadId, string $sessionId): void
    {
        $this->ensureRow($chatId, $threadId);
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET session_id = :sid WHERE chat_id = :chat AND thread_id = :thread'
        );
        $stmt->execute(['sid' => $sessionId, 'chat' => $chatId, 'thread' => $threadId]);
    }

    public function setPid(int $chatId, int $threadId, ?int $pid): void
    {
        $this->ensureRow($chatId, $threadId);
        $started = $pid !== null ? gmdate('c') : null;
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET pid = :pid, started_at = :started WHERE chat_id = :chat AND thread_id = :thread'
        );
        $stmt->execute(['pid' => $pid, 'started' => $started, 'chat' => $chatId, 'thread' => $threadId]);
    }

    /**
     * Reset the session (the /new command): forget session_id and the accumulated statistics.
     */
    public function resetSession(int $chatId, int $threadId = 0): void
    {
        $this->ensureRow($chatId, $threadId);
        $stmt = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sessions
               SET session_id = NULL,
                   pid = NULL,
                   started_at = NULL,
                   message_count = 0,
                   input_tokens = 0,
                   output_tokens = 0,
                   prompt_hash = NULL
             WHERE chat_id = :chat AND thread_id = :thread
            SQL
        );
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId]);
    }

    public function toggleVerbose(int $chatId, int $threadId = 0): bool
    {
        $this->ensureRow($chatId, $threadId);
        $this->pdo->prepare(
            'UPDATE sessions SET verbose = 1 - verbose WHERE chat_id = :chat AND thread_id = :thread'
        )->execute(['chat' => $chatId, 'thread' => $threadId]);

        return (bool) ($this->getSession($chatId, $threadId)['verbose'] ?? 0);
    }

    public function isVerbose(int $chatId, int $threadId = 0): bool
    {
        return (bool) ($this->getSession($chatId, $threadId)['verbose'] ?? 0);
    }

    /**
     * Session mode (see {@see SystemPrompt::MODES}); a new session gets
     * {@see SystemPrompt::DEFAULT_MODE}. Sticky: survives
     * resetSession() (like verbose), stored by the (chat_id, thread_id) key.
     *
     * An empty or unknown value in a row (manual DB edit, version
     * rollback) is treated as DEFAULT_MODE — with a line in the log; there is no need to crash or repair
     * the record, an unknown mode must not disarm the bot.
     */
    public function getMode(int $chatId, int $threadId = 0): string
    {
        $mode = $this->getSession($chatId, $threadId)['mode'] ?? null;
        if (is_string($mode) && $mode !== '') {
            if (SystemPrompt::isValidMode($mode)) {
                return $mode;
            }
            Log::line("Database: unknown mode '{$mode}' in session (chat {$chatId}, thread {$threadId}) — using '".SystemPrompt::DEFAULT_MODE."'.");
        }

        return SystemPrompt::DEFAULT_MODE;
    }

    public function setMode(int $chatId, int $threadId, string $mode): void
    {
        $this->ensureRow($chatId, $threadId);
        $this->pdo->prepare(
            'UPDATE sessions SET mode = :mode WHERE chat_id = :chat AND thread_id = :thread'
        )->execute(['mode' => $mode, 'chat' => $chatId, 'thread' => $threadId]);
    }

    /**
     * On bot start old PIDs are invalid (processes are dead or reused by the OS).
     */
    public function clearStalePids(): void
    {
        $this->pdo->exec('UPDATE sessions SET pid = NULL, started_at = NULL');
    }

    // ---- Request queue ----------------------------------------------------

    /**
     * Put an item at the end of the queue. FIFO order is set by the autoincrement
     * id (the moment of the item's first message). Uniqueness is (chat_id,
     * message_id), insertion via INSERT OR IGNORE: redelivery of an update
     * after a crash of the process does not create a second item. Returns the id
     * of the item or null if such an item already exists.
     *
     * @param list<array<string, mixed>> $parts messages of the item in order
     */
    public function queueAdd(int $chatId, int $threadId, int $messageId, string $kind, string $mode, array $parts, int $placeholderId = 0, ?string $locale = null): ?int
    {
        $ids = self::messageIdsOf($messageId, $parts);
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                if ($this->queueHasMessage($chatId, $id)) {
                    $this->pdo->rollBack();

                    return null;
                }
            }
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO queue (chat_id, thread_id, message_id, kind, mode, parts, placeholder_id, locale)
                 VALUES (:chat, :thread, :msg, :kind, :mode, :parts, :ph, :locale)'
            );
            $stmt->execute([
                'chat' => $chatId,
                'thread' => $threadId,
                'msg' => $messageId,
                'kind' => $kind,
                'mode' => $mode,
                'parts' => json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ph' => $placeholderId,
                'locale' => $locale,
            ]);
            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();

                return null;
            }
            $queueId = (int) $this->pdo->lastInsertId();
            $this->registerMessages($chatId, $queueId, $ids);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $queueId;
    }

    /**
     * message_id of the item's first message plus all ids from the parts (album frames, gluing).
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return list<int>
     */
    private static function messageIdsOf(int $messageId, array $parts): array
    {
        $ids = [$messageId];
        foreach ($parts as $part) {
            $ids[] = (int) ($part['message_id'] ?? 0);
            foreach ((array) ($part['message_ids'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id !== 0)));
    }

    /**
     * @param list<int> $ids
     */
    private function registerMessages(int $chatId, int $queueId, array $ids): void
    {
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO queue_messages (chat_id, message_id, queue_id) VALUES (:chat, :msg, :queue)');
        foreach ($ids as $id) {
            $stmt->execute(['chat' => $chatId, 'msg' => $id, 'queue' => $queueId]);
        }
    }

    public function queueHasMessage(int $chatId, int $messageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM queue_messages WHERE chat_id = :chat AND message_id = :msg');
        $stmt->execute(['chat' => $chatId, 'msg' => $messageId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Append a message to a pending item (gluing). A message whose
     * message_id (or any album frame id) already belongs to some
     * item is not duplicated (redelivery) — then false is returned.
     *
     * @param array<string, mixed> $part
     */
    public function queueAppendPart(int $id, array $part): bool
    {
        $item = $this->queueGet($id);
        if ($item === null) {
            return false;
        }
        $ids = self::messageIdsOf(0, [$part]);
        foreach ($ids as $messageId) {
            if ($this->queueHasMessage($item['chat_id'], $messageId)) {
                return false;
            }
        }
        $item['parts'][] = $part;
        $this->pdo->prepare('UPDATE queue SET parts = :parts WHERE id = :id')->execute([
            'parts' => json_encode($item['parts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $id,
        ]);
        $this->registerMessages($item['chat_id'], $id, $ids);

        return true;
    }

    /**
     * @return array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}|null
     */
    public function queueGet(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM queue WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::queueRow($row);
    }

    /**
     * Pending items (not started) in FIFO order.
     *
     * @return list<array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}>
     */
    public function queueWaiting(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM queue WHERE running = 0 ORDER BY id');

        return $stmt === false ? [] : array_values(array_map(self::queueRow(...), $stmt->fetchAll()));
    }

    /**
     * Items marked as started (the job is running or was killed by a restart).
     *
     * @return list<array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}>
     */
    public function queueRunning(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM queue WHERE running = 1 ORDER BY id');

        return $stmt === false ? [] : array_values(array_map(self::queueRow(...), $stmt->fetchAll()));
    }

    /**
     * @return array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}|null
     */
    public function queueHead(): ?array
    {
        return $this->queueWaiting()[0] ?? null;
    }

    /**
     * The last pending item of a topic — a candidate for gluing.
     *
     * @return array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}|null
     */
    public function queueLastWaiting(int $chatId, int $threadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM queue WHERE chat_id = :chat AND thread_id = :thread AND running = 0 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::queueRow($row);
    }

    /** Close the topic's gluing group: the next message starts a new item. */
    public function queueCloseGroup(int $chatId, int $threadId): void
    {
        $this->pdo->prepare(
            'UPDATE queue SET closed = 1 WHERE id = (
                SELECT MAX(id) FROM queue WHERE chat_id = :chat AND thread_id = :thread AND running = 0)'
        )->execute(['chat' => $chatId, 'thread' => $threadId]);
    }

    public function queueMarkRunning(int $id): void
    {
        $this->pdo->prepare('UPDATE queue SET running = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Return a started item to the head of the queue (id does not change — FIFO
     * is preserved). The gluing group is closed: the item's prompt has already gone to
     * claude (and into the transcript), new topic messages are not glued to it.
     */
    public function queueRelease(int $id): void
    {
        $this->pdo->prepare('UPDATE queue SET running = 0, placeholder_id = 0, closed = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    public function queueSetPlaceholder(int $id, int $placeholderId): void
    {
        $this->pdo->prepare('UPDATE queue SET placeholder_id = :ph WHERE id = :id')->execute(['ph' => $placeholderId, 'id' => $id]);
    }

    public function queueRemove(int $id): void
    {
        $this->pdo->prepare('DELETE FROM queue WHERE id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM queue_messages WHERE queue_id = :id')->execute(['id' => $id]);
    }

    /**
     * Remove all items of a topic (chat_id, thread_id). Other topics and chats, including
     * other topics of the same chat, are not affected.
     *
     * @return list<array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}>
     */
    public function queueCancelThread(int $chatId, int $threadId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM queue WHERE chat_id = :chat AND thread_id = :thread ORDER BY id');
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId]);
        $removed = array_values(array_map(self::queueRow(...), $stmt->fetchAll()));
        $this->pdo->prepare('DELETE FROM queue WHERE chat_id = :chat AND thread_id = :thread')
            ->execute(['chat' => $chatId, 'thread' => $threadId]);
        foreach ($removed as $item) {
            $this->pdo->prepare('DELETE FROM queue_messages WHERE queue_id = :id')->execute(['id' => $item['id']]);
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}
     */
    private static function queueRow(array $row): array
    {
        /** @var list<array<string, mixed>> $parts */
        $parts = json_decode((string) $row['parts'], true) ?: [];

        return [
            'id' => (int) $row['id'],
            'chat_id' => (int) $row['chat_id'],
            'thread_id' => (int) $row['thread_id'],
            'message_id' => (int) $row['message_id'],
            'kind' => (string) $row['kind'],
            'mode' => (string) $row['mode'],
            'locale' => isset($row['locale']) ? (string) $row['locale'] : null,
            'parts' => $parts,
            'placeholder_id' => (int) $row['placeholder_id'],
            'closed' => (int) $row['closed'] === 1,
            'running' => (int) $row['running'] === 1,
        ];
    }

    // ---- Epics -------------------------------------------------------------

    /**
     * Create a `running` epic with a task set and releases from the plan.
     *
     * @param list<array{task: string, after: list<string>}>                  $tasks
     * @param list<array{repo: string, version: string, after: list<string>}> $releases
     */
    public function epicCreate(string $slug, int $chatId, int $threadId, array $tasks, array $releases): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO epics (slug, chat_id, thread_id) VALUES (:slug, :chat, :thread)')
                ->execute(['slug' => $slug, 'chat' => $chatId, 'thread' => $threadId]);
            foreach ($tasks as $task) {
                $this->epicInsertTask($slug, $task['task'], 'plan', $task['after']);
            }
            $stmt = $this->pdo->prepare('INSERT INTO epic_releases (slug, repo, version, after) VALUES (:slug, :repo, :version, :after)');
            foreach ($releases as $release) {
                $stmt->execute([
                    'slug' => $slug,
                    'repo' => $release['repo'],
                    'version' => $release['version'],
                    'after' => json_encode($release['after'], JSON_THROW_ON_ERROR),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param list<string> $after
     */
    private function epicInsertTask(string $slug, string $task, string $source, array $after): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO epic_tasks (slug, task, source, after) VALUES (:slug, :task, :source, :after)'
        );
        $stmt->execute([
            'slug' => $slug,
            'task' => $task,
            'source' => $source,
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{slug:string, chat_id:int, thread_id:int, status:string, fingerprint:?string, attention:?string, blocked_reason:?string, done:bool, last_tick_at:int, stale_ticks:int, card_message_id:int, last_poll_at:int}|null
     */
    public function epicGet(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epics WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : self::epicRow($row);
    }

    /**
     * The topic's epic (the most recently created).
     *
     * @return array{slug:string, chat_id:int, thread_id:int, status:string, fingerprint:?string, attention:?string, blocked_reason:?string, done:bool, last_tick_at:int, stale_ticks:int, card_message_id:int, last_poll_at:int}|null
     */
    public function epicByThread(int $chatId, int $threadId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epics WHERE chat_id = :chat AND thread_id = :thread ORDER BY rowid DESC LIMIT 1');
        $stmt->execute(['chat' => $chatId, 'thread' => $threadId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::epicRow($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{slug:string, chat_id:int, thread_id:int, status:string, fingerprint:?string, attention:?string, blocked_reason:?string, done:bool, last_tick_at:int, stale_ticks:int, card_message_id:int, last_poll_at:int}
     */
    private static function epicRow(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'chat_id' => (int) $row['chat_id'],
            'thread_id' => (int) $row['thread_id'],
            'status' => (string) $row['status'],
            'fingerprint' => $row['fingerprint'] === null ? null : (string) $row['fingerprint'],
            'attention' => ($row['attention'] ?? null) === null ? null : (string) $row['attention'],
            'blocked_reason' => $row['blocked_reason'] === null ? null : (string) $row['blocked_reason'],
            'done' => (int) $row['done'] === 1,
            'last_tick_at' => (int) $row['last_tick_at'],
            'stale_ticks' => (int) $row['stale_ticks'],
            'card_message_id' => (int) ($row['card_message_id'] ?? 0),
            'last_poll_at' => (int) ($row['last_poll_at'] ?? 0),
        ];
    }

    /**
     * `running` epics — polled by the orchestrator.
     *
     * @return list<array{slug:string, chat_id:int, thread_id:int, status:string, fingerprint:?string, attention:?string, blocked_reason:?string, done:bool, last_tick_at:int, stale_ticks:int, card_message_id:int, last_poll_at:int}>
     */
    public function epicsRunning(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM epics WHERE status = 'running' ORDER BY rowid");

        return $stmt === false ? [] : array_values(array_map(self::epicRow(...), $stmt->fetchAll()));
    }

    /** Whether at least one epic is running (a cheap check for the main loop). */
    public function epicsRunningExist(): bool
    {
        $stmt = $this->pdo->query("SELECT 1 FROM epics WHERE status = 'running' LIMIT 1");

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    /** GitHub fingerprint remembered after the epic's last run; attention is the attention fingerprint (null — leave untouched). */
    public function epicSetFingerprint(string $slug, string $fingerprint, ?string $attention = null): void
    {
        $this->pdo->prepare('UPDATE epics SET fingerprint = :fp, attention = COALESCE(:att, attention) WHERE slug = :slug')
            ->execute(['fp' => $fingerprint, 'att' => $attention, 'slug' => $slug]);
    }

    /** Progress without judgment: fingerprints remembered, stall count reset, activity moment updated. */
    public function epicProgress(string $slug, string $fingerprint, string $attention, int $at): void
    {
        $this->epicSetFingerprint($slug, $fingerprint, $attention);
        $this->pdo->prepare('UPDATE epics SET stale_ticks = 0, last_tick_at = :at WHERE slug = :slug')->execute(['at' => $at, 'slug' => $slug]);
    }

    /** Tick queued: the moment and the number of consecutive timer ticks without changes. */
    public function epicTickQueued(string $slug, int $at, int $staleTicks): void
    {
        $this->pdo->prepare('UPDATE epics SET last_tick_at = :at, stale_ticks = :stale, ticks_total = ticks_total + 1 WHERE slug = :slug')
            ->execute(['at' => $at, 'stale' => $staleTicks, 'slug' => $slug]);
    }

    /** How many epic ticks have been queued overall (grows with each {@see epicTickQueued()}). */
    public function epicTicksTotal(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT ticks_total FROM epics WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Restart mark for the repository's `master` CI: on which SHA and which runs were restarted.
     * One per repository and SHA — no repeated restart on the same SHA.
     *
     * @return array{sha: string, runs: list<array{id: int, url: string, workflow: string}>}|null
     */
    public function masterRerun(string $repo): ?array
    {
        $raw = $this->kvGet('master_rerun:'.$repo);
        $data = $raw === null ? null : json_decode($raw, true);

        if (!is_array($data) || !isset($data['sha'])) {
            return null;
        }
        /** @var list<array{id: int, url: string, workflow: string}> $runs */
        $runs = is_array($data['runs'] ?? null) ? array_values($data['runs']) : [];

        return ['sha' => (string) $data['sha'], 'runs' => $runs];
    }

    /**
     * @param list<array{id: int, url: string, workflow: string}> $runs
     */
    public function masterRerunSet(string $repo, string $sha, array $runs): void
    {
        $this->kvSet('master_rerun:'.$repo, json_encode(['sha' => $sha, 'runs' => $runs], JSON_THROW_ON_ERROR));
    }

    public function epicMaster(string $slug, string $repo): ?MasterRecord
    {
        $raw = $this->kvGet("epic_master:{$slug}:{$repo}");
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? MasterRecord::fromArray($data) : null;
    }

    public function epicMasterSet(string $slug, MasterRecord $record): void
    {
        $this->kvSet("epic_master:{$slug}:{$record->repo}", json_encode($record->toArray(), JSON_THROW_ON_ERROR));
    }

    public function epicMasterDelete(string $slug, string $repo): void
    {
        $this->kvDelete("epic_master:{$slug}:{$repo}");
    }

    /**
     * @return array<string, MasterRecord> repository => record
     */
    public function epicMasters(string $slug): array
    {
        $prefix = "epic_master:{$slug}:";
        $stmt = $this->pdo->prepare('SELECT v FROM kv WHERE substr(k, 1, :len) = :prefix ORDER BY k');
        $stmt->execute(['len' => strlen($prefix), 'prefix' => $prefix]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $data = json_decode((string) $row['v'], true);
            if (is_array($data)) {
                $record = MasterRecord::fromArray($data);
                $out[$record->repo] = $record;
            }
        }

        return $out;
    }

    /** This epic's run is already queued or in progress. */
    public function epicRunPending(string $slug): bool
    {
        foreach ([...$this->queueWaiting(), ...$this->queueRunning()] as $item) {
            if ($item['kind'] === 'epic' && ($item['parts'][0]['slug'] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * PRs (repo#N) of this epic's review runs, queued or in progress. Tick and final-check runs carry no PR.
     *
     * @return list<string>
     */
    public function epicReviewPrs(string $slug): array
    {
        $prs = [];
        foreach ([...$this->queueWaiting(), ...$this->queueRunning()] as $item) {
            $part = $item['parts'][0] ?? [];
            if ($item['kind'] === 'epic' && ($part['slug'] ?? null) === $slug && ($part['run'] ?? null) === Run::REVIEW && isset($part['pr'])) {
                $prs[] = (string) $part['pr'];
            }
        }

        return $prs;
    }

    /** A task counter in `counters` (JSON). */
    public function epicSetTaskCounter(string $slug, string $task, string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('SELECT counters FROM epic_tasks WHERE slug = :slug AND task = :task');
        $stmt->execute(['slug' => $slug, 'task' => $task]);
        $counters = json_decode((string) $stmt->fetchColumn(), true);
        $counters = is_array($counters) ? $counters : [];
        $counters[$key] = $value;
        $this->pdo->prepare('UPDATE epic_tasks SET counters = :c WHERE slug = :slug AND task = :task')
            ->execute(['c' => json_encode($counters, JSON_THROW_ON_ERROR), 'slug' => $slug, 'task' => $task]);
    }

    /** message_id of the epic's status card in the topic (0 — no card). */
    public function epicSetCard(string $slug, int $messageId): void
    {
        $this->pdo->prepare('UPDATE epics SET card_message_id = :id WHERE slug = :slug')->execute(['id' => $messageId, 'slug' => $slug]);
    }

    /** Time of the last successful GitHub poll for the epic. */
    public function epicPolled(string $slug, int $at): void
    {
        $this->pdo->prepare('UPDATE epics SET last_poll_at = :at WHERE slug = :slug')->execute(['at' => $at, 'slug' => $slug]);
    }

    public function epicSetStatus(string $slug, string $status): void
    {
        $this->pdo->prepare('UPDATE epics SET status = :status WHERE slug = :slug')->execute(['status' => $status, 'slug' => $slug]);
    }

    /** Single record of an epic's stop: status `blocked` and the reason. */
    public function epicBlock(string $slug, string $reason): void
    {
        $this->pdo->prepare("UPDATE epics SET status = 'blocked', blocked_reason = :reason WHERE slug = :slug")
            ->execute(['reason' => $reason, 'slug' => $slug]);
    }

    /**
     * `/epic resume`: the epic is running again, the blocked reason and blocked status of tasks are cleared.
     * For the tasks being cleared, the REQUEST_CHANGES cap count (the `epic_changes_since` mark)
     * and the post-merge gate's `conflict_head` are reset. The stagnation counter restarts, and
     * the merge failure counter of every task is reset.
     *
     * @return list<string> tasks whose blocked status was cleared
     */
    public function epicResume(string $slug): array
    {
        $this->pdo->beginTransaction();
        try {
            $unblocked = [];
            $now = time();
            foreach ($this->epicTasks($slug) as $task) {
                $counters = $task['counters'];
                if (($counters['merge_failures'] ?? '') !== '' && $counters['merge_failures'] !== '0') {
                    $counters['merge_failures'] = '0';
                    $this->pdo->prepare('UPDATE epic_tasks SET counters = :c WHERE slug = :slug AND task = :task')
                        ->execute(['c' => json_encode($counters, JSON_THROW_ON_ERROR), 'slug' => $slug, 'task' => $task['task']]);
                }
                if ($task['status'] !== 'blocked') {
                    continue;
                }
                $unblocked[] = $task['task'];
                $counters['epic_changes'] = '0';
                $counters['epic_changes_since'] = (string) $now;
                $counters['conflict_head'] = '';
                if (($counters['cold_attempts'] ?? '') !== '') {
                    // Blocking by findings coverage: a new attempt from scratch, the review of the same head is queued anew.
                    $counters['cold_attempts'] = '';
                    $counters['reviewed_head'] = '';
                }
                $this->pdo->prepare('UPDATE epic_tasks SET counters = :c WHERE slug = :slug AND task = :task')
                    ->execute(['c' => json_encode($counters, JSON_THROW_ON_ERROR), 'slug' => $slug, 'task' => $task['task']]);
            }
            $this->pdo->prepare("UPDATE epics SET status = 'running', blocked_reason = NULL, final_retries = 0, stale_ticks = 0, last_tick_at = :now WHERE slug = :slug")
                ->execute(['now' => $now, 'slug' => $slug]);
            $this->pdo->prepare("UPDATE epic_tasks SET status = 'open' WHERE slug = :slug AND status = 'blocked'")->execute(['slug' => $slug]);
            $this->pdo->commit();

            return $unblocked;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** Status of an epic task (`open`, `blocked`, …): `blocked` is set by the orchestrator gates, cleared by /epic resume. */
    public function epicSetTaskStatus(string $slug, string $task, string $status): void
    {
        $this->pdo->prepare('UPDATE epic_tasks SET status = :status WHERE slug = :slug AND task = :task')
            ->execute(['status' => $status, 'slug' => $slug, 'task' => $task]);
    }

    /**
     * @return list<array{task:string, source:string, after:list<string>, hold:bool, status:string, counters:array<string, mixed>}>
     */
    public function epicTasks(string $slug): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epic_tasks WHERE slug = :slug ORDER BY rowid');
        $stmt->execute(['slug' => $slug]);
        $tasks = [];
        foreach ($stmt->fetchAll() as $row) {
            /** @var list<string> $after */
            $after = json_decode((string) $row['after'], true) ?: [];
            /** @var array<string, mixed> $counters */
            $counters = json_decode((string) $row['counters'], true) ?: [];
            $tasks[] = [
                'task' => (string) $row['task'],
                'source' => (string) $row['source'],
                'after' => $after,
                'hold' => (int) $row['hold'] === 1,
                'status' => (string) $row['status'],
                'counters' => $counters,
            ];
        }

        return $tasks;
    }

    /**
     * @return list<array{repo:string, version:string, after:list<string>}>
     */
    public function epicReleases(string $slug): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epic_releases WHERE slug = :slug ORDER BY rowid');
        $stmt->execute(['slug' => $slug]);
        $releases = [];
        foreach ($stmt->fetchAll() as $row) {
            /** @var list<string> $after */
            $after = json_decode((string) $row['after'], true) ?: [];
            $releases[] = ['repo' => (string) $row['repo'], 'version' => (string) $row['version'], 'after' => $after];
        }

        return $releases;
    }

    /** Release status: `open` | `parked` (the plan's version and CHANGELOG.md diverged, a question was put to the author) | `done` (the tag was set or already existed). */
    public function epicReleaseStatus(string $slug, string $repo, string $version): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM epic_releases WHERE slug = :slug AND repo = :repo AND version = :version');
        $stmt->execute(['slug' => $slug, 'repo' => $repo, 'version' => $version]);

        return (string) ($stmt->fetchColumn() ?: 'open');
    }

    public function epicSetReleaseStatus(string $slug, string $repo, string $version, string $status): void
    {
        $this->pdo->prepare('UPDATE epic_releases SET status = :status WHERE slug = :slug AND repo = :repo AND version = :version')
            ->execute(['status' => $status, 'slug' => $slug, 'repo' => $repo, 'version' => $version]);
    }

    /**
     * How many final epic checks were queued and how many tail rounds were started.
     *
     * @return array{final_runs:int, tails_rounds:int}
     */
    public function epicFinalCounters(string $slug): array
    {
        $stmt = $this->pdo->prepare('SELECT final_runs, tails_rounds FROM epics WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return ['final_runs' => (int) ($row['final_runs'] ?? 0), 'tails_rounds' => (int) ($row['tails_rounds'] ?? 0)];
    }

    /** Retries of a lost final check in the current round. */
    public function epicFinalRetries(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT final_retries FROM epics WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** The lost final check was queued again: `final_runs` is not touched. */
    public function epicFinalRetried(string $slug): void
    {
        $this->pdo->prepare('UPDATE epics SET final_retries = final_retries + 1 WHERE slug = :slug')->execute(['slug' => $slug]);
    }

    /** This epic's final check is queued or in progress. */
    public function epicFinalPending(string $slug): bool
    {
        foreach ([...$this->queueWaiting(), ...$this->queueRunning()] as $item) {
            if ($item['kind'] === 'epic' && ($item['parts'][0]['slug'] ?? null) === $slug && ($item['parts'][0]['run'] ?? null) === Run::FINAL) {
                return true;
            }
        }

        return false;
    }

    public function epicFinalStarted(string $slug): void
    {
        $this->pdo->prepare('UPDATE epics SET final_runs = final_runs + 1 WHERE slug = :slug')->execute(['slug' => $slug]);
    }

    /**
     * Save the signal's result in one transaction: extra tasks (already existing
     * ones are not touched — a re-run is idempotent), hold (null — unchanged),
     * the blocked reason and the done flag (only set; cleared by /epic resume).
     *
     * @param list<array{task: string, after: list<string>}> $added
     * @param list<string>|null                              $hold
     *
     * Extra tasks after the final check — a tail round; a third round does not start
     * (tasks are discarded, true is returned), the round counter is in SQLite
     *
     * @return bool true — extra tasks were discarded by the tail-round cap
     */
    public function epicApplySignal(string $slug, array $added, ?array $hold, bool $done): bool
    {
        $refused = false;
        $this->pdo->beginTransaction();
        try {
            if ($added !== []) {
                ['final_runs' => $finals, 'tails_rounds' => $tails] = $this->epicFinalCounters($slug);
                $known = array_column($this->epicTasks($slug), 'task');
                $fresh = array_filter($added, static fn (array $t): bool => !in_array($t['task'], $known, true));
                // A tail round is opened by the final check; signals inside an open round
                // (tails_rounds >= final_runs) add tasks without counting and without refusal.
                if ($finals > 0 && $fresh !== [] && $tails < $finals) {
                    if ($tails >= self::MAX_TAILS_ROUNDS) {
                        $added = [];
                        $refused = true;
                    } else {
                        $this->pdo->prepare('UPDATE epics SET tails_rounds = tails_rounds + 1, final_retries = 0 WHERE slug = :slug')->execute(['slug' => $slug]);
                    }
                }
            }
            foreach ($added as $task) {
                $this->epicInsertTask($slug, $task['task'], 'added', $task['after']);
            }
            if ($hold !== null) {
                $this->pdo->prepare('UPDATE epic_tasks SET hold = 0 WHERE slug = :slug')->execute(['slug' => $slug]);
                $stmt = $this->pdo->prepare('UPDATE epic_tasks SET hold = 1 WHERE slug = :slug AND task = :task');
                foreach ($hold as $task) {
                    $stmt->execute(['slug' => $slug, 'task' => $task]);
                }
            }
            if ($done) {
                $this->pdo->prepare("UPDATE epics SET done = 1, status = 'done' WHERE slug = :slug")->execute(['slug' => $slug]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $refused;
    }

    // ---- Bot state next to the queue (kv) ---------------------------------

    /** Chat language chosen by a Telegram user with /lang, or null if none was chosen. */
    public function getUserLocale(int $userId): ?string
    {
        $code = $this->kvGet('locale:user:'.$userId);

        return $code === null || $code === '' ? null : $code;
    }

    public function setUserLocale(int $userId, string $code): void
    {
        $this->kvSet('locale:user:'.$userId, $code);
    }

    private function kvGet(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT v FROM kv WHERE k = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function kvSet(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO kv (k, v) VALUES (:k, :v)')->execute(['k' => $key, 'v' => $value]);
    }

    private function kvDelete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM kv WHERE k = :k')->execute(['k' => $key]);
    }

    /**
     * @return array<string, int>|null
     */
    private function kvInts(string $key): ?array
    {
        $raw = $this->kvGet($key);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('intval', $decoded) : null;
    }

    /** Topic of the last job that touched the tree (owner of the commit/discard decision, AUTO_COMMIT=1). */
    public function setDirtyOwner(int $chatId, int $threadId): void
    {
        $this->kvSet('dirty_owner', json_encode(['chat_id' => $chatId, 'thread_id' => $threadId], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{chat_id:int, thread_id:int}|null null — owner unknown ("external" dirt)
     */
    public function getDirtyOwner(): ?array
    {
        $v = $this->kvInts('dirty_owner');

        return $v === null ? null : ['chat_id' => $v['chat_id'] ?? 0, 'thread_id' => $v['thread_id'] ?? 0];
    }

    public function clearDirtyOwner(): void
    {
        $this->kvDelete('dirty_owner');
    }

    /** Awaiting a commit/discard decision (AUTO_COMMIT=1 only): the topic where the buttons went and the message_id of the message with them. */
    public function setDecision(int $chatId, int $threadId, int $messageId): void
    {
        $this->kvSet('decision', json_encode(['chat_id' => $chatId, 'thread_id' => $threadId, 'message_id' => $messageId], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{chat_id:int, thread_id:int, message_id:int}|null
     */
    public function getDecision(): ?array
    {
        $v = $this->kvInts('decision');

        return $v === null ? null : ['chat_id' => $v['chat_id'] ?? 0, 'thread_id' => $v['thread_id'] ?? 0, 'message_id' => $v['message_id'] ?? 0];
    }

    public function clearDecision(): void
    {
        $this->kvDelete('decision');
    }

    /** Queue pause due to the Claude limit: unix time of the end. */
    public function setLimitPauseUntil(int $timestamp): void
    {
        $this->kvSet('limit_pause_until', (string) $timestamp);
    }

    public function getLimitPauseUntil(): int
    {
        return (int) ($this->kvGet('limit_pause_until') ?? 0);
    }

    /** Retry of the head after a transient start failure: the item and the unix time of the retry. */
    public function setRetry(int $itemId, int $at, bool $notified): void
    {
        $this->kvSet('retry', json_encode(['item' => $itemId, 'at' => $at, 'notified' => $notified ? 1 : 0], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{item:int, at:int, notified:bool}|null
     */
    public function getRetry(): ?array
    {
        $v = $this->kvInts('retry');

        return $v === null ? null : ['item' => $v['item'] ?? 0, 'at' => $v['at'] ?? 0, 'notified' => ($v['notified'] ?? 0) === 1];
    }

    public function clearRetry(): void
    {
        $this->kvDelete('retry');
    }
}
