<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\UsageTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tgdb_'.uniqid('', true);
        mkdir($this->dir);
        $this->db = new Database($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/state.sqlite');
        @rmdir($this->dir);
    }

    public function testNewChatHasNoSession(): void
    {
        self::assertNull($this->db->getSession(42));
        self::assertNull($this->db->getSessionId(42));
    }

    public function testRecordRunStoresSessionTokensAndClearsPid(): void
    {
        $this->db->setPid(42, 0, 9999);
        $this->db->recordRun(42, 0, 'sess-x', new UsageTotals(100, 7, 8, 25, 0.5));

        $row = $this->db->getSession(42);

        self::assertNotNull($row);
        self::assertSame('sess-x', $row['session_id']);
        self::assertSame(1, $row['message_count']);
        self::assertSame(100, $row['input_tokens']);
        self::assertSame(25, $row['output_tokens']);
        self::assertSame(7, $row['cache_creation_tokens']);
        self::assertSame(8, $row['cache_read_tokens']);
        self::assertSame(0.5, $row['cost_usd']);
        self::assertNull($row['pid']);
    }

    public function testSetSessionIdStoresIdWithoutTouchingCounters(): void
    {
        // simulate accumulated stats from the previous run
        $this->db->recordRun(42, 0, 'old-sess', new UsageTotals(100, 0, 0, 25));
        $this->db->setPid(42, 0, 9999);

        // early capture of the forked session_id during the new run
        $this->db->setSessionId(42, 0, 'new-sess');

        $row = $this->db->getSession(42);
        self::assertNotNull($row);
        self::assertSame('new-sess', $row['session_id']);
        // counters and pid are untouched
        self::assertSame(1, $row['message_count']);
        self::assertSame(100, $row['input_tokens']);
        self::assertSame(25, $row['output_tokens']);
        self::assertSame(9999, $row['pid']);
    }

    public function testSetSessionIdCreatesRowForNewChat(): void
    {
        $this->db->setSessionId(7, 3, 'fresh');

        self::assertSame('fresh', $this->db->getSessionId(7, 3));
    }

    public function testRecordRunOverwritesSnapshotAndReturnsTheDifference(): void
    {
        $first = $this->db->recordRun(1, 0, 's', new UsageTotals(10, 100, 200, 5, 0.01));
        $second = $this->db->recordRun(1, 0, 's', new UsageTotals(30, 150, 500, 12, 0.03));

        $row = $this->db->getSession(1);

        self::assertNotNull($row);
        self::assertNotNull($first);
        self::assertSame(10, $first->inputTokens);
        self::assertNotNull($second);
        self::assertSame(20, $second->inputTokens);
        self::assertSame(50, $second->cacheCreationTokens);
        self::assertSame(300, $second->cacheReadTokens);
        self::assertSame(7, $second->outputTokens);
        self::assertEqualsWithDelta(0.02, $second->costUsd, 1e-9);
        self::assertSame(2, $row['message_count']);
        self::assertSame(30, $row['input_tokens']);
        self::assertSame(150, $row['cache_creation_tokens']);
        self::assertSame(500, $row['cache_read_tokens']);
        self::assertSame(12, $row['output_tokens']);
        self::assertSame(0.03, $row['cost_usd']);
    }

    public function testLowerCostThanStoredCountsPreviousSnapshotAsZero(): void
    {
        $this->db->recordRun(1, 0, 's', new UsageTotals(30, 150, 500, 12, 0.03));
        $run = $this->db->recordRun(1, 0, 's', new UsageTotals(5, 10, 20, 3, 0.01));

        self::assertNotNull($run);
        self::assertSame(5, $run->inputTokens);
        self::assertSame(20, $run->cacheReadTokens);
        self::assertSame(0.01, $run->costUsd);
        self::assertSame(5, $this->db->getSession(1)['input_tokens'] ?? null);
    }

    public function testLowerCostAloneCountsPreviousSnapshotAsZero(): void
    {
        $this->db->recordRun(1, 0, 's', new UsageTotals(30, 150, 500, 12, 0.03));
        $run = $this->db->recordRun(1, 0, 's', new UsageTotals(40, 200, 600, 20, 0.01));

        self::assertNotNull($run);
        self::assertSame(40, $run->inputTokens);
        self::assertSame(600, $run->cacheReadTokens);
        self::assertSame(0.01, $run->costUsd);
    }

    public function testFirstRunAfterResetIsTheFullSnapshot(): void
    {
        $this->db->recordRun(1, 0, 's', new UsageTotals(30, 150, 500, 12, 0.03));
        $this->db->resetSession(1);

        $row = $this->db->getSession(1);
        self::assertNotNull($row);
        self::assertSame(0, $row['cache_read_tokens']);
        self::assertNull($row['cost_usd']);

        $run = $this->db->recordRun(1, 0, 's2', new UsageTotals(40, 1, 2, 3, 0.04));
        self::assertNotNull($run);
        self::assertSame(40, $run->inputTokens);
        self::assertSame(0.04, $run->costUsd);
    }

    public function testRecordRunWithoutTotalsKeepsTheSnapshot(): void
    {
        $this->db->recordRun(1, 0, 's', new UsageTotals(30, 150, 500, 12, 0.03));
        $run = $this->db->recordRun(1, 0, 's', null);

        $row = $this->db->getSession(1);

        self::assertNull($run);
        self::assertNotNull($row);
        self::assertSame(2, $row['message_count']);
        self::assertSame(30, $row['input_tokens']);
        self::assertSame(150, $row['cache_creation_tokens']);
        self::assertSame(500, $row['cache_read_tokens']);
        self::assertSame(12, $row['output_tokens']);
        self::assertSame(0.03, $row['cost_usd']);
    }

    public function testUnknownCostStaysNullAndIsNotZero(): void
    {
        $run = $this->db->recordRun(1, 0, 's', new UsageTotals(1, 2, 3, 4, null));

        $row = $this->db->getSession(1);

        self::assertNotNull($run);
        self::assertNull($run->costUsd);
        self::assertNotNull($row);
        self::assertNull($row['cost_usd']);
    }

    public function testSessionsWithoutSnapshotColumnsGetThemAndKeepValues(): void
    {
        $dir = sys_get_temp_dir().'/tgdb_presnapshot_'.uniqid('', true);
        mkdir($dir);
        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE sessions (
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
            SQL
        );
        $pdo->exec("INSERT INTO sessions (chat_id, thread_id, session_id, message_count, input_tokens, output_tokens) VALUES (9, 0, 'old', 4, 500, 60)");
        $pdo = null;

        $db = new Database($dir);
        $row = $db->getSession(9, 0);

        self::assertNotNull($row);
        self::assertSame(500, $row['input_tokens']);
        self::assertSame(60, $row['output_tokens']);
        self::assertSame(4, $row['message_count']);
        self::assertSame(0, $row['cache_creation_tokens']);
        self::assertSame(0, $row['cache_read_tokens']);
        self::assertNull($row['cost_usd']);

        // The first run with totals overwrites the old values with Claude's totals.
        $run = $db->recordRun(9, 0, 'old', new UsageTotals(20, 1, 2, 3, 0.5));
        self::assertNotNull($run);
        self::assertSame(20, $db->getSession(9, 0)['input_tokens'] ?? null);
        self::assertSame(20, $run->inputTokens);

        @unlink($dir.'/state.sqlite');
        @rmdir($dir);
    }

    public function testResetSessionClearsEverything(): void
    {
        $this->db->recordRun(1, 0, 's', new UsageTotals(10, 1, 1, 5, 0.1));
        $this->db->resetSession(1);

        $row = $this->db->getSession(1);

        self::assertNotNull($row);
        self::assertNull($row['session_id']);
        self::assertSame(0, $row['message_count']);
        self::assertSame(0, $row['input_tokens']);
        self::assertNull($row['cost_usd']);
    }

    public function testToggleVerbose(): void
    {
        self::assertFalse($this->db->isVerbose(1));
        self::assertTrue($this->db->toggleVerbose(1));
        self::assertTrue($this->db->isVerbose(1));
        self::assertFalse($this->db->toggleVerbose(1));
    }

    public function testClearStalePids(): void
    {
        $this->db->setPid(1, 0, 123);
        $this->db->clearStalePids();

        $row = $this->db->getSession(1);
        self::assertNotNull($row);
        self::assertNull($row['pid']);
    }

    public function testSessionsIsolatedByThread(): void
    {
        // The same chat_id pair, but different topics — independent sessions.
        $this->db->recordRun(1, 0, 'sess-root', new UsageTotals(10, 0, 0, 5));
        $this->db->recordRun(1, 777, 'sess-topic', new UsageTotals(20, 0, 0, 8));

        self::assertSame('sess-root', $this->db->getSessionId(1, 0));
        self::assertSame('sess-topic', $this->db->getSessionId(1, 777));

        // Resetting one topic does not touch the other.
        $this->db->resetSession(1, 0);
        self::assertNull($this->db->getSessionId(1, 0));
        self::assertSame('sess-topic', $this->db->getSessionId(1, 777));

        // verbose is separate as well.
        self::assertTrue($this->db->toggleVerbose(1, 777));
        self::assertFalse($this->db->isVerbose(1, 0));
        self::assertTrue($this->db->isVerbose(1, 777));
    }

    public function testDefaultThreadIsZero(): void
    {
        // Methods without an explicit thread_id work with session 0 (1:1 / General).
        $this->db->recordRun(42, 0, 'sess-x', new UsageTotals(1, 0, 0, 1));
        self::assertSame('sess-x', $this->db->getSessionId(42));
        self::assertNotNull($this->db->getSession(42));
    }

    public function testModeDefaultsToPmAndIsSettable(): void
    {
        self::assertSame('pm', $this->db->getMode(1, 0));

        $this->db->setMode(1, 0, 'review');
        self::assertSame('review', $this->db->getMode(1, 0));

        // Sticky: not touched by resetSession() (like verbose).
        $this->db->resetSession(1, 0);
        self::assertSame('review', $this->db->getMode(1, 0));
    }

    public function testUnknownModeInRowFallsBackToDefault(): void
    {
        $this->db->setMode(1, 0, 'bogus');

        self::assertSame('pm', $this->db->getMode(1, 0));
    }

    public function testNewRowInDbWithOldArchColumnDefaultGetsPm(): void
    {
        // A DB created before the default changed: the schema has DEFAULT 'arch' baked in, SQLite
        // does not change it via ALTER. A new row must get pm, and
        // an existing one must keep its arch.
        $dir = sys_get_temp_dir().'/tgdb_oldarch_'.uniqid('', true);
        mkdir($dir);

        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE sessions (
                chat_id        INTEGER NOT NULL,
                thread_id      INTEGER NOT NULL DEFAULT 0,
                session_id     TEXT,
                pid            INTEGER,
                started_at     TEXT,
                message_count  INTEGER NOT NULL DEFAULT 0,
                input_tokens   INTEGER NOT NULL DEFAULT 0,
                output_tokens  INTEGER NOT NULL DEFAULT 0,
                verbose        INTEGER NOT NULL DEFAULT 0,
                mode           TEXT NOT NULL DEFAULT 'arch',
                PRIMARY KEY (chat_id, thread_id)
            )
            SQL
        );
        $pdo->exec('INSERT INTO sessions (chat_id, thread_id) VALUES (5, 0)');
        $pdo = null;

        $db = new Database($dir);
        $db->recordRun(6, 0, 'sess-new', new UsageTotals(1, 0, 0, 1));

        self::assertSame('arch', $db->getMode(5, 0));
        self::assertSame('pm', $db->getMode(6, 0));
        // The prompt hash column is added without losing rows; legacy rows have none.
        self::assertSame('none', $db->getSession(5, 0)['prompt_hash'] ?? 'none');
        $db->recordRun(5, 0, 'sess-5', new UsageTotals(1, 0, 0, 1), 'h1');
        $db->recordRun(5, 0, 'sess-5', new UsageTotals(1, 0, 0, 1));
        $kept = $db->getSession(5, 0);
        self::assertSame('h1', $kept['prompt_hash'] ?? 'none');
        $db->resetSession(5, 0);
        self::assertSame('none', $db->getSession(5, 0)['prompt_hash'] ?? 'none');
    }

    public function testEpicsWithoutFinalRetriesColumnGetsMigrated(): void
    {
        $dir = sys_get_temp_dir().'/tgdb_finalretries_'.uniqid('', true);
        mkdir($dir);
        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE epics (slug TEXT PRIMARY KEY, chat_id INTEGER NOT NULL, thread_id INTEGER NOT NULL, status TEXT NOT NULL, final_runs INTEGER NOT NULL DEFAULT 0, tails_rounds INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO epics (slug, chat_id, thread_id, status, final_runs) VALUES ('e', 1, 2, 'running', 1)");
        unset($pdo);

        $db = new Database($dir);
        self::assertSame(0, $db->epicFinalRetries('e'));
        $db->epicFinalRetried('e');
        self::assertSame(1, $db->epicFinalRetries('e'));
        self::assertSame(1, $db->epicFinalCounters('e')['final_runs']);
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function testPreviousSchemaWithoutModeColumnGetsMigrated(): void
    {
        // Schema AFTER thread_id was introduced but BEFORE mode was added — exactly the
        // case CREATE TABLE IF NOT EXISTS does not heal (see gotchas.md):
        // on an existing table it is a no-op, an explicit ALTER under hasColumn() is needed.
        $dir = sys_get_temp_dir().'/tgdb_premode_'.uniqid('', true);
        mkdir($dir);

        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE sessions (
                chat_id        INTEGER NOT NULL,
                thread_id      INTEGER NOT NULL DEFAULT 0,
                session_id     TEXT,
                pid            INTEGER,
                started_at     TEXT,
                message_count  INTEGER NOT NULL DEFAULT 0,
                input_tokens   INTEGER NOT NULL DEFAULT 0,
                output_tokens  INTEGER NOT NULL DEFAULT 0,
                verbose        INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (chat_id, thread_id)
            )
            SQL
        );
        $pdo->exec(
            'INSERT INTO sessions (chat_id, thread_id, session_id, message_count, input_tokens, output_tokens, verbose) '
            ."VALUES (77, 0, 'existing-sess', 4, 200, 60, 1)"
        );
        $pdo = null;

        $db = new Database($dir);

        self::assertSame('existing-sess', $db->getSessionId(77, 0));
        self::assertSame('arch', $db->getMode(77, 0));

        $row = $db->getSession(77, 0);
        self::assertNotNull($row);
        self::assertArrayHasKey('mode', $row);
        self::assertSame('arch', $row['mode']);
        self::assertSame(4, $row['message_count']);
        self::assertTrue($db->isVerbose(77, 0));

        $db->setMode(77, 0, 'pm');
        self::assertSame('pm', $db->getMode(77, 0));

        @unlink($dir.'/state.sqlite');
        @rmdir($dir);
    }

    public function testLegacyTableMigratesToThreadZero(): void
    {
        $dir = sys_get_temp_dir().'/tgdb_legacy_'.uniqid('', true);
        mkdir($dir);

        // Reproduce the old schema (chat_id PRIMARY KEY, no thread_id).
        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE sessions (
                chat_id        INTEGER PRIMARY KEY,
                session_id     TEXT,
                pid            INTEGER,
                started_at     TEXT,
                message_count  INTEGER NOT NULL DEFAULT 0,
                input_tokens   INTEGER NOT NULL DEFAULT 0,
                output_tokens  INTEGER NOT NULL DEFAULT 0,
                verbose        INTEGER NOT NULL DEFAULT 0
            )
            SQL
        );
        $pdo->exec("INSERT INTO sessions (chat_id, session_id, message_count, input_tokens, output_tokens, verbose) VALUES (55, 'legacy-sess', 3, 100, 40, 1)");
        $pdo = null;

        // Opening via Database must rebuild the table transactionally.
        $db = new Database($dir);

        self::assertSame('legacy-sess', $db->getSessionId(55, 0));
        $row = $db->getSession(55, 0);
        self::assertNotNull($row);
        self::assertSame(0, $row['thread_id']);
        self::assertSame(3, $row['message_count']);
        self::assertSame(100, $row['input_tokens']);
        self::assertTrue($db->isVerbose(55, 0));
        self::assertSame('arch', $db->getMode(55, 0));
        self::assertSame('arch', $row['mode']);

        // A new topic under the same chat_id is now addressable separately.
        self::assertNull($db->getSessionId(55, 900));

        @unlink($dir.'/state.sqlite');
        @rmdir($dir);
    }

    public function testQueueIsFifoByFirstMessage(): void
    {
        $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [['message_id' => 10, 'text' => 'a']]);
        $this->db->queueAdd(100, 2, 11, 'chat', 'pm', [['message_id' => 11, 'text' => 'b']]);
        $this->db->queueAdd(100, 1, 12, 'chat', 'pm', [['message_id' => 12, 'text' => 'c']]);

        self::assertSame([10, 11, 12], array_column($this->db->queueWaiting(), 'message_id'));
        self::assertSame(10, $this->db->queueHead()['message_id'] ?? null);
    }

    public function testQueueInsertOrIgnoreOnSameChatAndMessage(): void
    {
        self::assertNotNull($this->db->queueAdd(100, 1, 10, 'chat', 'pm', []));
        self::assertNull($this->db->queueAdd(100, 1, 10, 'chat', 'pm', []));
        // The same message_id in another chat is a different item.
        self::assertNotNull($this->db->queueAdd(200, 1, 10, 'chat', 'pm', []));

        self::assertCount(2, $this->db->queueWaiting());
        self::assertTrue($this->db->queueHasMessage(100, 10));
    }

    public function testQueueAppendPartIgnoresRedeliveredMessage(): void
    {
        $id = (int) $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [['message_id' => 10, 'text' => 'a']]);

        self::assertTrue($this->db->queueAppendPart($id, ['message_id' => 11, 'text' => 'b']));
        self::assertFalse($this->db->queueAppendPart($id, ['message_id' => 11, 'text' => 'b']));

        self::assertSame(['a', 'b'], array_column($this->db->queueGet($id)['parts'] ?? [], 'text'));
    }

    public function testQueueAndStateSurviveReopeningConnection(): void
    {
        $id = (int) $this->db->queueAdd(100, 7, 10, 'compact', 'review', [['message_id' => 10, 'text' => 'a']], 555);
        $this->db->setDirtyOwner(100, 7);
        $this->db->setDecision(100, 7, 321);
        $this->db->setLimitPauseUntil(1234567890);
        $this->db->setRetry($id, 42, true);

        $reopened = new Database($this->dir);

        $item = $reopened->queueHead();
        self::assertNotNull($item);
        self::assertSame(['compact', 'review', 555, 7], [$item['kind'], $item['mode'], $item['placeholder_id'], $item['thread_id']]);
        self::assertSame(['chat_id' => 100, 'thread_id' => 7], $reopened->getDirtyOwner());
        self::assertSame(['chat_id' => 100, 'thread_id' => 7, 'message_id' => 321], $reopened->getDecision());
        self::assertSame(1234567890, $reopened->getLimitPauseUntil());
        self::assertSame(['item' => $id, 'at' => 42, 'notified' => true], $reopened->getRetry());

        $reopened->clearDecision();
        $reopened->clearDirtyOwner();
        self::assertNull($reopened->getDecision());
        self::assertNull($reopened->getDirtyOwner());
    }

    public function testQueueCancelThreadTouchesOnlyThatTopic(): void
    {
        $this->db->queueAdd(100, 1, 10, 'chat', 'pm', []);
        $this->db->queueAdd(100, 2, 11, 'chat', 'pm', []);
        $this->db->queueAdd(200, 1, 12, 'chat', 'pm', []);
        $this->db->queueAdd(100, 1, 13, 'new', 'pm', []);

        $removed = $this->db->queueCancelThread(100, 1);

        self::assertSame([10, 13], array_column($removed, 'message_id'));
        self::assertSame([11, 12], array_column($this->db->queueWaiting(), 'message_id'));
    }

    public function testQueueReleaseReturnsRunningItemAsHead(): void
    {
        $first = (int) $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [], 99);
        $this->db->queueAdd(100, 2, 11, 'chat', 'pm', []);
        $this->db->queueMarkRunning($first);
        self::assertSame(11, $this->db->queueHead()['message_id'] ?? null);

        $this->db->queueRelease($first);

        self::assertSame(10, $this->db->queueHead()['message_id'] ?? null);
        self::assertSame(0, $this->db->queueHead()['placeholder_id']);
    }

    public function testQueueCloseGroupMarksLastWaitingItemOfTopic(): void
    {
        $this->db->queueAdd(100, 1, 10, 'chat', 'pm', []);
        $this->db->queueAdd(100, 2, 11, 'chat', 'pm', []);

        $this->db->queueCloseGroup(100, 1);

        self::assertTrue($this->db->queueLastWaiting(100, 1)['closed'] ?? false);
        self::assertFalse($this->db->queueLastWaiting(100, 2)['closed'] ?? true);
    }

    public function testQueueRegistersAllMessageIdsOfAlbumAndRejectsRedeliveredFrame(): void
    {
        $id = $this->db->queueAdd(100, 1, 20, 'chat', 'pm', [['message_id' => 20, 'message_ids' => [20, 21, 22], 'text' => null, 'files' => []]]);

        self::assertNotNull($id);
        foreach ([20, 21, 22] as $frame) {
            self::assertTrue($this->db->queueHasMessage(100, $frame));
        }
        self::assertFalse($this->db->queueHasMessage(200, 21));
        // Redelivery with a different "first" frame — the same item, no duplicate is created.
        self::assertNull($this->db->queueAdd(100, 1, 21, 'chat', 'pm', [['message_id' => 21, 'message_ids' => [21], 'files' => []]]));
        self::assertCount(1, $this->db->queueWaiting());
    }

    public function testAppendedPartMessageIdsAreRegisteredAndReleasedOnRemove(): void
    {
        $id = (int) $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [['message_id' => 10, 'text' => 'a']]);

        self::assertTrue($this->db->queueAppendPart($id, ['message_id' => 11, 'message_ids' => [11, 12], 'text' => 'b']));
        self::assertTrue($this->db->queueHasMessage(100, 12));
        self::assertFalse($this->db->queueAppendPart($id, ['message_id' => 12, 'message_ids' => [12], 'text' => 'b']));
        self::assertNull($this->db->queueAdd(100, 2, 11, 'chat', 'pm', [['message_id' => 11, 'text' => 'b']]));

        $this->db->queueRemove($id);
        self::assertFalse($this->db->queueHasMessage(100, 12));
    }

    public function testQueueReleaseClosesMergeGroup(): void
    {
        $id = (int) $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [['message_id' => 10, 'text' => 'a']]);
        $this->db->queueMarkRunning($id);
        $this->db->queueRelease($id);

        self::assertTrue($this->db->queueHead()['closed'] ?? false);
    }
}
