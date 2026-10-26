<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\GitHub;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** How Bot wires EPIC_POLL_SECONDS: the long-poll cap and the poll-pass timestamp. */
#[CoversClass(Bot::class)]
final class PollWiringTest extends TestCase
{
    private const int POLL_SECONDS = 13;
    private const int POLL_TIMEOUT = 30;

    private string $root;
    private Database $db;
    private Bot $bot;

    private FakeTelegram $tg;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tga_'.uniqid('', true);
        mkdir($this->root);
        mkdir($this->root.'/state');
        $this->db = new Database($this->root.'/state');

        $this->tg = new FakeTelegram();

        $config = new Config(
            botToken: 't',
            allowedUserIds: [555],
            repoPath: $this->root,
            githubRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->root.'/state',
            outboxDir: $this->root.'/outbox',
            inboxDir: $this->root.'/inbox',
            pollTimeout: self::POLL_TIMEOUT,
            gitTimeout: 30,
            epicPollSeconds: self::POLL_SECONDS,
        );
        $this->bot = new Bot($config, $this->db, $this->tg, new GitManager($this->root, 30), new SessionFiles($this->root), $this->createStub(GitHub::class));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function call(string $method): mixed
    {
        return (new \ReflectionMethod(Bot::class, $method))->invoke($this->bot);
    }

    private function lastPollAt(): int
    {
        return (new \ReflectionProperty(Bot::class, 'lastEpicPollAt'))->getValue($this->bot);
    }

    public function testLongPollIsCappedByEpicPollSecondsWhileEpicRuns(): void
    {
        $this->db->epicCreate('demo', 100, 7, [['task' => 'a#1', 'after' => []]], []);

        $this->call('tick');
        $this->call('tick');

        // The first tick is overdue (cap 1); after the pass the next one is due in EPIC_POLL_SECONDS.
        self::assertSame(1, $this->tg->pollTimeouts[0]);
        self::assertGreaterThanOrEqual(self::POLL_SECONDS - 1, $this->tg->pollTimeouts[1]);
        self::assertLessThanOrEqual(self::POLL_SECONDS, $this->tg->pollTimeouts[1]);
        self::assertGreaterThan(0, $this->lastPollAt());
    }

    public function testLongPollIsNotCappedWithoutRunningEpic(): void
    {
        $this->call('tick');
        $this->call('tick');

        self::assertSame([self::POLL_TIMEOUT, self::POLL_TIMEOUT], $this->tg->pollTimeouts);
    }

    public function testFailedPollPassStillSetsTimestamp(): void
    {
        $this->db->epicCreate('demo', 100, 7, [['task' => 'a#1', 'after' => []]], []);
        $pdo = new \PDO('sqlite:'.$this->root.'/state/state.sqlite');
        $pdo->exec('ALTER TABLE epics RENAME TO epics_gone');

        try {
            $this->call('pollEpics');
            self::fail('poll() was expected to throw');
        } catch (\PDOException) {
        }
        self::assertGreaterThan(0, $this->lastPollAt());

        // Not due again: poll() is not called (it would throw once more).
        $this->call('pollEpics');
    }
}
