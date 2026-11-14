<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Exception\SecretFoundException;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Queue\QueueScheduler;
use GpsLab\WorkspaceAssistant\RunState;
use GpsLab\WorkspaceAssistant\SecretScanner;
use GpsLab\WorkspaceAssistant\SecretSources;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The commit gate of `GitManager::commitAndPush()` and the "blocked by secret" state of the bot, on a
 * real git with a bare origin and a fake `claude`. Fake tokens are built by concatenation.
 */
#[CoversClass(GitManager::class)]
#[CoversClass(SecretFoundException::class)]
final class SecretGateTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/tgsg_'.uniqid('', true);
        mkdir($this->root);
        $this->origin = $this->root.'/origin.git';
        $this->work = $this->root.'/work';
        $this->sh('git init -q --bare '.escapeshellarg($this->origin));
        $this->sh('git clone -q '.escapeshellarg($this->origin).' '.escapeshellarg($this->work).' 2>/dev/null');
        foreach (['config user.email t@t.t', 'config user.name test', 'checkout -q -b main'] as $cmd) {
            $this->git($cmd);
        }
        file_put_contents($this->work.'/a.txt', "a\n");
        $this->git('add -A');
        $this->git('commit -q -m init');
        $this->git('push -q -u origin main');

        mkdir($this->root.'/state');
        mkdir($this->root.'/outbox');
        mkdir($this->root.'/inbox');
        file_put_contents($this->root.'/claude', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"$(dirname \"\$0\")/args.log\"\n[ -t 0 ] || cat >> \"$(dirname \"\$0\")/args.log\"\necho x >> \"$(dirname \"\$0\")/calls.log\"\n[ -f \"$(dirname \"\$0\")/hook.sh\" ] && sh \"$(dirname \"\$0\")/hook.sh\"\ncat \"$(dirname \"\$0\")/out.json\"\n");
        chmod($this->root.'/claude', 0755);
        file_put_contents($this->root.'/out.json', '{"type":"result","is_error":false,"result":"done","session_id":"s-1","usage":{}}'."\n");

        $this->db = new Database($this->root.'/state');
        $this->tg = new FakeTelegram();
        $this->bot = $this->makeBot();
        SecretScanner::setShared(null);
    }

    protected function tearDown(): void
    {
        SecretScanner::setShared(null);
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

    /** A GitHub-shaped token built by concatenation, so no literal token is in the source. */
    private static function shapeToken(): string
    {
        return 'ghp_'.str_repeat('Ab3', 13);
    }

    /** A value that only the known-values source can recognize. */
    private static function knownValue(): string
    {
        return 'zq-corp-internal-value-19283746';
    }

    private function knowValues(): void
    {
        SecretScanner::setShared(new SecretScanner(new SecretSources($this->root.'/.env', [], ['SERVICE_TOKEN' => self::knownValue()])));
    }

    /**
     * @param list<string> $exclude
     */
    private function gate(array $exclude = []): GitManager
    {
        return new GitManager($this->work, 30, secretScanExclude: $exclude);
    }

    private function head(): string
    {
        return trim($this->git('rev-parse HEAD'));
    }

    private function originHead(): string
    {
        return trim($this->git('rev-parse main', $this->origin));
    }

    private function makeBot(): Bot
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [self::USER],
            repoPath: $this->work,
            forgeRepo: '',
            claudeBin: $this->root.'/claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->root.'/state',
            outboxDir: $this->root.'/outbox',
            inboxDir: $this->root.'/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            forgeOwners: new ForgeOwners(['gpslab']),
            botLocale: 'en',
            autoCommit: true,
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

    private function runState(): RunState
    {
        return (new \ReflectionProperty(Bot::class, 'runState'))->getValue($this->bot);
    }

    private function queueScheduler(): QueueScheduler
    {
        return (new \ReflectionProperty(Bot::class, 'queueScheduler'))->getValue($this->bot);
    }

    private function finishJob(): void
    {
        for ($i = 0; $i < 200 && $this->runState()->activeJob !== null; ++$i) {
            $this->call('pollJob');
            usleep(50000);
        }
        self::assertNull($this->runState()->activeJob, 'the job did not finish');
    }

    private function hook(string $shell): void
    {
        file_put_contents($this->root.'/hook.sh', $shell."\n");
    }

    private function allText(): string
    {
        return implode("\n", array_column($this->tg->sent, 'text'));
    }

    private function launches(): int
    {
        $log = $this->root.'/calls.log';

        return is_file($log) ? count(file($log) ?: []) : 0;
    }

    private function blockedCommit(): SecretFoundException
    {
        file_put_contents($this->work.'/conf.txt', "ok\nkey = ".self::shapeToken()."\n");
        try {
            $this->gate()->commitAndPush('msg');
        } catch (SecretFoundException $e) {
            return $e;
        }

        self::fail('the gate did not block');
    }

    // ---- GitManager ----

    public function testShapePatternInStagedDiffBlocksAndResetsTheIndex(): void
    {
        $head = $this->head();
        $origin = $this->originHead();
        file_put_contents($this->work.'/a.txt', "a\nb\n");

        $e = $this->blockedCommit();

        self::assertSame('conf.txt:2', $e->findings[0]->location());
        self::assertSame('github_token', $e->findings[0]->type);
        self::assertStringNotContainsString(self::shapeToken(), $e->getMessage());
        self::assertSame($head, $this->head());
        self::assertSame($origin, $this->originHead());
        self::assertSame('', trim($this->git('diff --cached --name-only')), 'the index is reset');
        self::assertSame("a\nb\n", file_get_contents($this->work.'/a.txt'), 'the working tree is unchanged');
        self::assertStringContainsString(self::shapeToken(), (string) file_get_contents($this->work.'/conf.txt'));
    }

    public function testKnownValueInStagedDiffBlocks(): void
    {
        $this->knowValues();
        file_put_contents($this->work.'/c.txt', "x\nurl=".self::knownValue()."\n");

        try {
            $this->gate()->commitAndPush('msg');
            self::fail('the gate did not block');
        } catch (SecretFoundException $e) {
            self::assertSame('c.txt:2', $e->findings[0]->location());
            self::assertSame('known:SERVICE_TOKEN', $e->findings[0]->type);
            self::assertStringNotContainsString(self::knownValue(), $e->getMessage());
        }
        self::assertSame($this->originHead(), $this->head());
    }

    public function testSecretInUnpushedCommitBlocksTheNextCommit(): void
    {
        file_put_contents($this->work.'/old.txt', "1\n2\ntoken: ".self::shapeToken()."\n");
        $this->git('add -A');
        $this->git('commit -q -m local');
        $local = $this->head();
        file_put_contents($this->work.'/clean.txt', "fine\n");

        try {
            $this->gate()->commitAndPush('msg');
            self::fail('the gate did not block');
        } catch (SecretFoundException $e) {
            self::assertCount(1, $e->findings);
            self::assertSame(substr($local, 0, 7).':old.txt:3', $e->findings[0]->location());
        }
        self::assertSame($local, $this->head());
        self::assertSame('', trim($this->git('diff --cached --name-only')));
        self::assertNotSame($local, $this->originHead());
        self::assertFileExists($this->work.'/clean.txt');
    }

    public function testPushedHistoryIsNotScanned(): void
    {
        file_put_contents($this->work.'/old.txt', self::shapeToken()."\n");
        $this->git('add -A');
        $this->git('commit -q -m local');
        $this->git('push -q');
        file_put_contents($this->work.'/clean.txt', "fine\n");

        $hash = $this->gate()->commitAndPush('msg');

        self::assertSame($hash, $this->originHead());
    }

    public function testCleanChangesAreCommittedAndPushed(): void
    {
        file_put_contents($this->work.'/clean.txt', "fine\n");

        $hash = $this->gate()->commitAndPush('msg');

        self::assertSame($hash, $this->originHead());
        self::assertSame('msg', trim($this->git('log -1 --format=%s')));
    }

    public function testRerunOnCleanTreePushesUnpushedCommitsOnceTheyAreClean(): void
    {
        file_put_contents($this->work.'/old.txt', "fine\n");
        $this->git('add -A');
        $this->git('commit -q -m local');

        $hash = $this->gate()->commitAndPush('msg');

        self::assertSame($hash, $this->originHead());
        self::assertSame('local', trim($this->git('log -1 --format=%s')), 'no empty commit is made');
    }

    public function testExcludedPathSkipsShapePatternsButNotKnownValues(): void
    {
        mkdir($this->work.'/tests/fixtures', 0777, true);
        file_put_contents($this->work.'/tests/fixtures/t.txt', self::shapeToken()."\n");
        $this->knowValues();

        $hash = $this->gate(['tests/fixtures/*'])->commitAndPush('msg');
        self::assertSame($hash, $this->originHead());

        file_put_contents($this->work.'/tests/fixtures/k.txt', self::knownValue()."\n");
        try {
            $this->gate(['tests/fixtures/*'])->commitAndPush('msg');
            self::fail('a known value must not be excludable');
        } catch (SecretFoundException $e) {
            self::assertSame('tests/fixtures/k.txt:1', $e->findings[0]->location());
        }
    }

    public function testDeletedLineAndBinaryFileAreNotScanned(): void
    {
        file_put_contents($this->work.'/old.txt', self::shapeToken()."\n");
        $this->git('add -A');
        $this->git('commit -q -m local');
        $this->git('push -q');
        unlink($this->work.'/old.txt');
        file_put_contents($this->work.'/bin.dat', "\0\1\2".self::shapeToken()."\0");

        $hash = $this->gate()->commitAndPush('msg');

        self::assertSame($hash, $this->originHead());
    }

    // ---- the bot ----

    public function testBlockedRunMessageListsPlacesWithoutValuesAndKeepsDiscard(): void
    {
        $this->hook('printf "x\nkey='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();

        $text = $this->allText();
        self::assertStringContainsString('conf.txt:2 (github_token)', $text);
        self::assertStringContainsString('Reply with what to do, or discard the changes.', $text);
        self::assertStringNotContainsString('Ab3Ab3', $text);
        self::assertStringNotContainsString('ghp_', $text);
        $buttons = array_values(array_filter($this->tg->sent, static fn (array $m): bool => $m['markup'] !== null));
        self::assertCount(1, $buttons);
        self::assertSame('discard', $buttons[0]['markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertNotNull($this->db->getSecretBlock());
        self::assertNotNull($this->db->getDecision());
        self::assertFileExists($this->work.'/conf.txt');
        self::assertSame($this->originHead(), $this->head());
    }

    public function testBlockedStateDoesNotPauseTheQueueAndTheNextRunFixesIt(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        self::assertNotNull($this->db->getSecretBlock());
        self::assertSame(1, $this->launches());

        $this->hook('printf "key=from-env\n" > '.$this->work.'/conf.txt');
        $this->say('please fix', 11);
        self::assertNull($this->queueScheduler()->pauseReason());
        $this->call('startQueueHead');
        self::assertNotNull($this->runState()->activeJob, 'a dirty tree must not pause the queue');
        $this->finishJob();

        $args = (string) file_get_contents($this->root.'/args.log');
        self::assertStringContainsString('conf.txt:1 (github_token)', $args);
        self::assertStringContainsString('rewrite that commit', $args);
        self::assertStringContainsString('please fix', $args);
        self::assertStringNotContainsString(self::shapeToken(), $args);
        self::assertNull($this->db->getSecretBlock(), 'a successful commit clears the state');
        self::assertSame($this->head(), $this->originHead());
        self::assertSame("key=from-env\n", $this->git('show HEAD:conf.txt'));
    }

    public function testGateRunsAgainAtTheEndOfTheRunAndStaysBlocked(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $this->say('try again', 11);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame(2, $this->launches());
        self::assertNotNull($this->db->getSecretBlock());
        self::assertSame(2, substr_count($this->allText(), 'Reply with what to do'));
        self::assertSame($this->originHead(), $this->head());
    }

    public function testRewrittenUnpushedCommitOnCleanTreeIsPushedAndClearsTheState(): void
    {
        $dir = escapeshellarg($this->work);
        $this->hook('cd '.$dir.' && printf "key='.self::shapeToken().'\n" > old.txt && git add -A && git commit -q -m local && echo fine > extra.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        self::assertNotNull($this->db->getSecretBlock());
        self::assertNotSame($this->head(), $this->originHead());

        $this->hook('cd '.$dir.' && printf "key=clean\n" > old.txt && git add -A && git commit -q --amend -m local');
        $this->say('fix it', 11);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertNull($this->db->getSecretBlock());
        self::assertSame($this->head(), $this->originHead());
        self::assertStringContainsString('local', trim($this->git('log -1 --format=%s')));
    }

    public function testDiscardClearsTheBlockedState(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $decision = $this->db->getDecision();
        self::assertNotNull($decision);

        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'discard', 'message' => ['chat' => ['id' => 100], 'message_id' => $decision['message_id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertNull($this->db->getSecretBlock());
        self::assertFileDoesNotExist($this->work.'/conf.txt');
        self::assertStringContainsString('The changes were discarded.', $this->allText());
        self::assertStringNotContainsString('unpushed', $this->allText());
    }

    public function testBlockedTrackedFileDoesNotStopTheSyncOfTheNextRun(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/a.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        self::assertNotNull($this->db->getSecretBlock());

        $this->hook('printf "key=from-env\n" > '.$this->work.'/a.txt');
        $this->say('please fix', 11);
        $this->call('startQueueHead');
        self::assertNotNull($this->runState()->activeJob, 'the pull must not fail over the unstaged change');
        $this->finishJob();

        self::assertNull($this->db->getSecretBlock());
        self::assertSame($this->head(), $this->originHead());
        self::assertSame("key=from-env\n", $this->git('show HEAD:a.txt'));
    }

    public function testDiscardDropsAnUnpushedCommitWithTheSecret(): void
    {
        $dir = escapeshellarg($this->work);
        $this->hook('cd '.$dir.' && printf "key='.self::shapeToken().'\n" > old.txt && git add -A && git commit -q -m local && echo fine > extra.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $decision = $this->db->getDecision();
        self::assertNotNull($decision);
        self::assertNotSame($this->head(), $this->originHead());

        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'discard', 'message' => ['chat' => ['id' => 100], 'message_id' => $decision['message_id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertNull($this->db->getSecretBlock());
        self::assertSame($this->originHead(), $this->head());
        self::assertFileDoesNotExist($this->work.'/old.txt');
        self::assertStringContainsString('Unpushed commits were dropped together with the changes.', $this->allText());
    }

    public function testDiscardWithoutUpstreamKeepsTheStateAndSaysSo(): void
    {
        $dir = escapeshellarg($this->work);
        $this->hook('cd '.$dir.' && printf "key='.self::shapeToken().'\n" > old.txt && git add -A && git commit -q -m local && echo fine > extra.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $decision = $this->db->getDecision();
        self::assertNotNull($decision);
        $this->git('branch --unset-upstream');

        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'discard', 'message' => ['chat' => ['id' => 100], 'message_id' => $decision['message_id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertNotNull($this->db->getSecretBlock());
        self::assertStringContainsString('the secret is still in an unpushed commit', $this->allText());
        self::assertStringNotContainsString('The changes were discarded.', $this->allText());
    }

    public function testCommitButtonWithCleanContentClearsTheState(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $decision = $this->db->getDecision();
        self::assertNotNull($decision);
        self::assertNotNull($this->db->getSecretBlock());

        file_put_contents($this->work.'/conf.txt', "key=from-env\n");
        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'commit', 'message' => ['chat' => ['id' => 100], 'message_id' => $decision['message_id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertNull($this->db->getSecretBlock());
        self::assertSame($this->head(), $this->originHead());
        self::assertSame("key=from-env\n", $this->git('show HEAD:conf.txt'));
    }

    public function testEpicAtTheHeadDoesNotHoldBackTheAuthorsReplyWhileBlocked(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'gpslab/ws#4', 'after' => []]], []);
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $block = $this->db->getSecretBlock();
        self::assertNotNull($block);
        $launches = $this->launches();

        // Only the epic waits: it does not start, the block stays, and /queue says why.
        self::assertTrue($this->queueScheduler()->enqueueEpicRun('demo', Run::TICK));
        $this->call('startQueueHead');
        self::assertNull($this->runState()->activeJob);
        self::assertSame($launches, $this->launches());
        self::assertEquals($block, $this->db->getSecretBlock());
        self::assertNotNull($this->queueScheduler()->pauseReason());

        // The author's reply is behind the epic in the queue and starts anyway.
        $this->hook('printf "key=from-env\n" > '.$this->work.'/conf.txt');
        $this->say('please fix', 11);
        $this->call('startQueueHead');
        self::assertNotNull($this->runState()->activeJob, 'the epic at the head must not stop the reply');
        $this->finishJob();

        self::assertSame($launches + 1, $this->launches());
        self::assertNull($this->db->getSecretBlock());
        self::assertCount(1, $this->db->queueWaiting(), 'the epic item is still waiting');
    }

    public function testCleanRunInBlockedStateDoesNotReportAnOldCommit(): void
    {
        $this->hook('printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');
        $this->say('go');
        $this->call('startQueueHead');
        $this->finishJob();
        $before = count($this->tg->sent);

        $this->hook('rm -f '.$this->work.'/conf.txt');
        $this->say('revert it', 11);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertNull($this->db->getSecretBlock());
        $texts = implode("\n", array_column(array_slice($this->tg->sent, $before), 'text'));
        self::assertStringNotContainsString('— committed:', $texts);
    }

    public function testCommitButtonHandlesTheBlockLikeARun(): void
    {
        file_put_contents($this->work.'/conf.txt', 'key='.self::shapeToken()."\n");
        $this->say('wait');
        $this->call('startQueueHead');
        $decision = $this->db->getDecision();
        self::assertNotNull($decision, 'a dirty tree outside the blocked state pauses with the buttons');

        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'commit', 'message' => ['chat' => ['id' => 100], 'message_id' => $decision['message_id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertNotNull($this->db->getSecretBlock());
        self::assertStringContainsString('conf.txt:1 (github_token)', $this->allText());
        self::assertStringNotContainsString('ghp_', $this->allText());
        self::assertSame($this->originHead(), $this->head());
    }

    public function testEpicRunSendsTheFindingToTheEpic(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'gpslab/ws#4', 'after' => []]], []);
        mkdir($this->root.'/state/epics/demo', 0777, true);
        $this->hook('printf "{}" > '.$this->root.'/state/epics/demo/signal.json; printf "key='.self::shapeToken().'\n" > '.$this->work.'/conf.txt');

        self::assertTrue($this->queueScheduler()->enqueueEpicRun('demo', Run::TICK));
        $this->call('startQueueHead');
        $this->finishJob();

        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('blocked', $epic['status']);
        self::assertNotNull($this->db->getSecretBlock());
        self::assertStringContainsString('conf.txt:1 (github_token)', $this->allText());
        self::assertStringNotContainsString('ghp_', $this->allText());
        self::assertSame($this->originHead(), $this->head());
    }
}
