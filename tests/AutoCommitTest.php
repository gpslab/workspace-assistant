<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The chat run path with AUTO_COMMIT=0 (and, as a control, with the default 1) on a real git and a fake `claude`.
 */
#[CoversClass(Bot::class)]
#[CoversClass(GitManager::class)]
final class AutoCommitTest extends TestCase
{
    private const int USER = 555;

    private string $root;
    private string $work;
    private string $origin;
    private Database $db;
    private FakeTelegram $tg;
    private Bot $bot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tgac_'.uniqid('', true);
        mkdir($this->root);
        $this->origin = $this->root.'/origin.git';
        $this->work = $this->root.'/work';
        $this->sh('git init -q --bare '.escapeshellarg($this->origin));
        $this->sh('git clone -q '.escapeshellarg($this->origin).' '.escapeshellarg($this->work).' 2>/dev/null');
        foreach (['config user.email t@t.t', 'config user.name test', 'checkout -q -b main'] as $cmd) {
            $this->git($cmd);
        }
        mkdir($this->work.'/prompts');
        file_put_contents($this->work.'/a.txt', "a\n");
        file_put_contents($this->work.'/prompts/pm.md', "p\n");
        $this->git('add -A');
        $this->git('commit -q -m init');
        $this->git('push -q -u origin main');

        mkdir($this->root.'/state');
        mkdir($this->root.'/outbox');
        mkdir($this->root.'/inbox');
        file_put_contents($this->root.'/claude', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"$(dirname \"\$0\")/args.log\"\n[ -t 0 ] || cat >> \"$(dirname \"\$0\")/args.log\"\necho x >> \"$(dirname \"\$0\")/calls.log\"\n[ -f \"$(dirname \"\$0\")/hook.sh\" ] && sh \"$(dirname \"\$0\")/hook.sh\"\ncat \"$(dirname \"\$0\")/out.json\"\n");
        chmod($this->root.'/claude', 0755);
        $this->claudeOutput('{"type":"result","is_error":false,"result":"done","session_id":"s-1","usage":{}}');

        $this->db = new Database($this->root.'/state');
        $this->tg = new FakeTelegram();
        $this->bot = $this->makeBot(false);
    }

    protected function tearDown(): void
    {
        $this->sh('rm -rf '.escapeshellarg($this->root));
    }

    private function sh(string $cmd): void
    {
        exec($cmd.' 2>&1');
    }

    private function git(string $args, ?string $dir = null): string
    {
        return (string) shell_exec('git -C '.escapeshellarg($dir ?? $this->work).' '.$args.' 2>&1');
    }

    private function claudeOutput(string $json): void
    {
        file_put_contents($this->root.'/out.json', $json."\n");
    }

    private function hook(string $shell): void
    {
        file_put_contents($this->root.'/hook.sh', $shell."\n");
    }

    private function makeBot(bool $autoCommit, string $subreposDir = '', int $timeout = 0): Bot
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [self::USER],
            repoPath: $this->work,
            githubRepo: '',
            claudeBin: $this->root.'/claude',
            claudeTimeout: $timeout,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->root.'/state',
            outboxDir: $this->root.'/outbox',
            inboxDir: $this->root.'/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            promptsDir: 'prompts',
            githubOwners: new GithubOwners(['gpslab']),
            subreposDir: $subreposDir,
            botLocale: 'en',
            autoCommit: $autoCommit,
        );

        return new Bot($config, $this->db, $this->tg, new GitManager($this->work, 30), new SessionFiles($this->root));
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod(Bot::class, $method))->invokeArgs($this->bot, $args);
    }

    private function say(string $text, int $messageId = 10): void
    {
        $this->call('enqueueMessage', [100, 1, $messageId, [['message_id' => $messageId, 'text' => $text, 'files' => []]]]);
    }

    private function activeJob(): bool
    {
        return (new \ReflectionProperty(Bot::class, 'activeJob'))->getValue($this->bot) !== null;
    }

    private function finishJob(): void
    {
        for ($i = 0; $i < 200 && $this->activeJob(); ++$i) {
            $this->call('pollJob');
            usleep(50000);
        }
        self::assertFalse($this->activeJob(), 'the job did not finish');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buttons(): array
    {
        return array_values(array_filter($this->tg->sent, static fn (array $m): bool => $m['markup'] !== null));
    }

    private function allText(): string
    {
        return implode("\n", array_column($this->tg->sent, 'text'));
    }

    private function head(): string
    {
        return trim($this->git('rev-parse HEAD'));
    }

    private function originHead(): string
    {
        return trim($this->git('rev-parse main', $this->origin));
    }

    private function dirtyOperatorTree(): void
    {
        file_put_contents($this->work.'/a.txt', "operator edit\n");
        file_put_contents($this->work.'/notes.txt', "untracked\n");
    }

    public function testRunStartsOnDirtyTreeWithoutPauseButtonsOrPull(): void
    {
        $this->dirtyOperatorTree();
        // A commit on origin that `git pull --rebase` would fetch (and fail on, with the dirty tree).
        $other = $this->root.'/other';
        $this->sh('git clone -q '.escapeshellarg($this->origin).' '.escapeshellarg($other));
        file_put_contents($other.'/b.txt', "b\n");
        $this->git('add -A', $other);
        $this->git('-c user.email=t@t.t -c user.name=t commit -q -m remote', $other);
        $this->git('push -q origin HEAD:main', $other);
        $head = $this->head();

        $this->say('hello');
        $this->call('startQueueHead');

        self::assertTrue($this->activeJob());
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDecision());
        self::assertSame($head, $this->head());
        self::assertFileDoesNotExist($this->work.'/b.txt');
        $this->finishJob();
    }

    public function testNothingCommittedAndReplyCountsOnlyFilesOfThisRun(): void
    {
        $this->dirtyOperatorTree();
        $this->hook('echo new > "$PWD/created.txt"; echo again >> "$PWD/a.txt"; echo m > "$PWD/notes2.txt"');
        $head = $this->head();
        $origin = $this->originHead();

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame($head, $this->head());
        self::assertSame($origin, $this->originHead());
        self::assertStringContainsString('— this run changed files: 3', $this->allText());
        self::assertStringNotContainsString('committed', $this->allText());
        self::assertFileExists($this->work.'/notes.txt');
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDirtyOwner());
    }

    public function testRunThatChangedNothingSaysNothingAboutChanges(): void
    {
        $this->dirtyOperatorTree();

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringContainsString('done', $this->allText());
        self::assertStringNotContainsString('changed files', $this->allText());
        self::assertNull($this->db->getDirtyOwner());
    }

    public function testSecondEditOfDirtyPromptFileGivesPromptFilesWarning(): void
    {
        file_put_contents($this->work.'/prompts/pm.md', "operator\n");
        $this->hook('echo claude >> "$PWD/prompts/pm.md"');

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        $text = $this->allText();
        self::assertStringContainsString('prompts/pm.md', $text);
        self::assertStringContainsString('shape future runs', $text);
        self::assertStringNotContainsString('committed without review', $text);
    }

    public function testUntouchedDirtyPromptFileGivesNoWarning(): void
    {
        file_put_contents($this->work.'/prompts/pm.md', "operator\n");

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringNotContainsString('prompts/pm.md', $this->allText());
    }

    public function testCancelLeavesChangesWithoutButtons(): void
    {
        $this->hook('echo new > "$PWD/created.txt"; sleep 5');

        $this->say('hello');
        $this->call('startQueueHead');
        usleep(300000);
        $this->call('handleCommand', [100, 1, '/cancel', 0]);

        self::assertFileExists($this->work.'/created.txt');
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDecision());
    }

    public function testTimeoutLeavesChangesWithoutButtons(): void
    {
        $this->bot = $this->makeBot(false, '', 1);
        $this->hook('echo new > "$PWD/created.txt"; sleep 10');

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertFileExists($this->work.'/created.txt');
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDecision());
    }

    public function testFailedRunLeavesChangesWithoutButtons(): void
    {
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"s-1","usage":{}}');
        $this->hook('echo new > "$PWD/created.txt"');

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertFileExists($this->work.'/created.txt');
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDecision());
    }

    public function testFailedEpicRunLeavesWorkingTreeAndIndexUntouched(): void
    {
        file_put_contents($this->work.'/a.txt', "unstaged\n");
        file_put_contents($this->work.'/staged.txt', "staged\n");
        $this->git('add staged.txt');
        file_put_contents($this->work.'/untracked.txt', "u\n");
        $status = $this->git('status --porcelain');

        $this->call('afterFailedRun', [100, 1, 'demo', 'process killed']);

        self::assertSame($status, $this->git('status --porcelain'));
        self::assertSame("unstaged\n", file_get_contents($this->work.'/a.txt'));
        self::assertFileExists($this->work.'/untracked.txt');
        self::assertStringContainsString('process killed', (string) file_get_contents($this->root.'/state/epics/demo/failures.log'));
        self::assertGlobEmpty($this->root.'/state/epics/demo/*.patch');
    }

    public function testFailedEpicRunStillRollsBackWithAutoCommit(): void
    {
        $this->bot = $this->makeBot(true);
        file_put_contents($this->work.'/untracked.txt', "u\n");

        $this->call('afterFailedRun', [100, 1, 'demo', 'process killed']);

        self::assertFileDoesNotExist($this->work.'/untracked.txt');
        self::assertNotEmpty(glob($this->root.'/state/epics/demo/*.patch'));
    }

    public function testSubRepositoriesArePulledEvenWithoutAutoCommit(): void
    {
        $subOrigin = $this->root.'/sub.git';
        $this->sh('git init -q -b main --bare '.escapeshellarg($subOrigin));
        $seed = $this->root.'/seed';
        $this->sh('git clone -q '.escapeshellarg($subOrigin).' '.escapeshellarg($seed).' 2>/dev/null');
        $this->git('checkout -q -b main', $seed);
        file_put_contents($seed.'/s.txt', "1\n");
        $this->git('add -A', $seed);
        $this->git('-c user.email=t@t.t -c user.name=t commit -q -m one', $seed);
        $this->git('push -q -u origin main', $seed);
        mkdir($this->work.'/subs');
        $this->sh('git clone -q '.escapeshellarg($subOrigin).' '.escapeshellarg($this->work.'/subs/x').' 2>/dev/null');
        file_put_contents($seed.'/s.txt', "2\n");
        $this->git('-c user.email=t@t.t -c user.name=t commit -q -am two', $seed);
        $this->git('push -q origin main', $seed);

        $this->bot = $this->makeBot(false, 'subs');
        $this->dirtyOperatorTree();
        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame("2\n", file_get_contents($this->work.'/subs/x/s.txt'));
    }

    public function testCommitButtonIssuedBeforeTheSwitchIsRefusedAndTreeUntouched(): void
    {
        // Buttons issued while the bot ran with AUTO_COMMIT=1.
        $this->bot = $this->makeBot(true);
        $this->dirtyOperatorTree();
        $this->say('waiting');
        $this->call('startQueueHead');
        $buttons = $this->buttons();
        self::assertCount(1, $buttons);
        $head = $this->head();
        $messageId = $buttons[0]['id'];

        $this->bot = $this->makeBot(false);
        foreach (['commit', 'discard'] as $data) {
            $this->call('handleCallback', [['id' => 'cb-'.$data, 'from' => ['id' => self::USER], 'data' => $data, 'message' => ['chat' => ['id' => 100], 'message_id' => $messageId, 'is_topic_message' => true, 'message_thread_id' => 1]]]);
            self::assertStringContainsString('AUTO_COMMIT=0', (string) end($this->tg->answers));
        }

        self::assertSame($head, $this->head());
        self::assertSame("operator edit\n", file_get_contents($this->work.'/a.txt'));
        self::assertFileExists($this->work.'/notes.txt');
    }

    public function testStartWithZeroClearsLeftoverDecisionAndOwner(): void
    {
        $this->db->setDecision(100, 1, 5);
        $this->db->setDirtyOwner(100, 1);

        // run() clears the state right at the start; the loop itself is not entered with a stopped flag.
        (new \ReflectionProperty(Bot::class, 'running'))->setValue($this->bot, false);
        try {
            $this->bot->run();
        } catch (\Throwable) {
        }

        self::assertNull($this->db->getDecision());
        self::assertNull($this->db->getDirtyOwner());
    }

    public function testAutoCommitOnKeepsCommitPushAndLink(): void
    {
        $this->bot = $this->makeBot(true);
        $this->hook('echo new > "$PWD/created.txt"');
        $head = $this->head();

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertNotSame($head, $this->head());
        self::assertSame($this->head(), $this->originHead());
        self::assertStringContainsString('— committed:', $this->allText());
        self::assertStringNotContainsString('changed files', $this->allText());
    }

    public function testAutoCommitOnPausesOnDirtyTree(): void
    {
        $this->bot = $this->makeBot(true);
        $this->dirtyOperatorTree();

        $this->say('hello');
        $this->call('startQueueHead');

        self::assertFalse($this->activeJob());
        self::assertCount(1, $this->buttons());
    }

    public function testEpicRunStartsOnDirtyTreeWithOverrideInPrompt(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);
        $this->dirtyOperatorTree();

        self::assertTrue($this->bot->enqueueEpicRun('demo', 'tick'));
        $this->call('startQueueHead');
        $this->finishJob();

        $args = (string) file_get_contents($this->root.'/args.log');
        self::assertStringContainsString('write it to disk right after each external action; do not commit or push it', $args);
        self::assertStringContainsString('nothing is committed or pushed', $args);
        self::assertStringContainsString('A crashed run loses everything uncommitted', $args);
        self::assertStringNotContainsString('commit and push it right after', $args);
    }

    public function testEpicRunWithAutoCommitOnKeepsCommitInstruction(): void
    {
        $this->bot = $this->makeBot(true);
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);

        self::assertTrue($this->bot->enqueueEpicRun('demo', 'tick'));
        $this->call('startQueueHead');
        $this->finishJob();

        $args = (string) file_get_contents($this->root.'/args.log');
        self::assertStringContainsString('commit and push it right after each external action', $args);
        self::assertStringNotContainsString('nothing is committed or pushed', $args);
    }

    public function testEpicRunLeftoversAreNeitherCommittedNorPushed(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);
        $this->dirtyOperatorTree();
        $this->hook('echo j > "$PWD/journal.txt"; echo \'{}\' > "$WORKSPACE_ASSISTANT_EPIC_SIGNAL"');
        $head = $this->head();
        $origin = $this->originHead();

        self::assertTrue($this->bot->enqueueEpicRun('demo', 'tick'));
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame($head, $this->head());
        self::assertSame($origin, $this->originHead());
        self::assertFileExists($this->work.'/journal.txt');
        self::assertSame([], $this->buttons());
        // The run took the success path of finishEpicRun, not the failure one.
        self::assertFileDoesNotExist($this->root.'/state/epics/demo/failures.log');
        self::assertStringNotContainsString('signal', $this->allText());
        self::assertStringContainsString('operator edit', (string) file_get_contents($this->work.'/a.txt'));
    }

    public function testEpicStatusLinksJournalOnlyWhenItIsPushed(): void
    {
        $this->git('remote set-url origin https://github.com/gpslab/demo.git');
        $this->git('remote set-url --push origin '.escapeshellarg($this->origin));
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);
        $journal = '.workspace-assistant/epics/demo.journal.md';
        mkdir(dirname($this->work.'/'.$journal), 0777, true);
        file_put_contents($this->work.'/'.$journal, "journal\n");

        $this->bot = $this->makeBot(true);
        $this->call('handleCommand', [100, 1, '/epic status', 30, self::USER]);
        self::assertStringContainsString('(https://github.com/gpslab/demo/blob/', $this->allText());

        $this->tg->sent = [];
        $this->bot = $this->makeBot(false);
        $this->call('handleCommand', [100, 1, '/epic status', 30, self::USER]);
        self::assertStringContainsString('Journal: '.$journal, $this->allText());
        self::assertStringNotContainsString('demo.journal.md](', $this->allText());
    }

    public function testEpicPromptFilesWarningCountsOnlyFilesTheRunChanged(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);
        file_put_contents($this->work.'/prompts/pm.md', "operator\n");
        file_put_contents($this->work.'/prompts/other.md', "operator\n");
        $this->hook('echo claude >> "$PWD/prompts/other.md"; echo \'{}\' > "$WORKSPACE_ASSISTANT_EPIC_SIGNAL"');

        self::assertTrue($this->bot->enqueueEpicRun('demo', 'tick'));
        $this->call('startQueueHead');
        $this->finishJob();

        $text = $this->allText();
        self::assertStringContainsString('prompts/other.md', $text);
        self::assertStringNotContainsString('prompts/pm.md', $text);
    }

    public function testCompactEditOfAlreadyDirtyFileDuringSummarizeStopsIt(): void
    {
        $this->dirtyOperatorTree();
        $this->hook('echo claude >> "$PWD/a.txt"');
        $this->db->recordRun(100, 1, 's-old', 0, 0);
        $this->call('handleCommand', [100, 1, '/compact', 30, self::USER]);
        $this->claudeOutput('{"type":"result","is_error":false,"result":"summary","session_id":"s-new","usage":{}}');

        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame(1, substr_count((string) file_get_contents($this->root.'/calls.log'), 'x'));
        self::assertStringContainsString('a.txt', $this->allText());
        self::assertSame([], $this->buttons());
        self::assertNull($this->db->getDecision());
    }

    public function testCompactOnDirtyOperatorTreeReseedsWhenSummarizeChangedNothing(): void
    {
        $this->dirtyOperatorTree();
        $this->db->recordRun(100, 1, 's-old', 0, 0);
        $this->call('handleCommand', [100, 1, '/compact', 30, self::USER]);
        $this->claudeOutput('{"type":"result","is_error":false,"result":"summary","session_id":"s-new","usage":{}}');

        $this->call('startQueueHead');
        $this->finishJob(); // summarize
        $this->finishJob(); // reseed, launched by the summarize completion

        self::assertStringNotContainsString('Compaction stopped', $this->allText());
        self::assertSame(2, substr_count((string) file_get_contents($this->root.'/calls.log'), 'x'));
        self::assertSame([], $this->buttons());
    }

    public function testSnapshotDiffSeesNewGoneAndRewrittenPaths(): void
    {
        $git = new GitManager($this->work, 30);
        file_put_contents($this->work.'/a.txt', "dirty\n");
        file_put_contents($this->work.'/gone.txt', "g\n");
        $before = $git->treeSnapshot();

        file_put_contents($this->work.'/a.txt', "dirty again\n");
        unlink($this->work.'/gone.txt');
        file_put_contents($this->work.'/new.txt', "n\n");
        $this->git('add new.txt');

        $changed = $git->changedSince($before);
        sort($changed);
        self::assertSame(['a.txt', 'gone.txt', 'new.txt'], $changed);
        self::assertSame([], $git->changedSince($before, 'prompts'));
    }

    private static function assertGlobEmpty(string $pattern): void
    {
        self::assertSame([], glob($pattern) ?: []);
    }
}
