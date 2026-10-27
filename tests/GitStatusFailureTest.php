<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Exception\GitStatusException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A failed `git status` is a third state, unknown: neither clean nor dirty. Every test breaks the index of a real
 * repository (`fatal: .git/index: index file smaller than expected`) and checks that nothing acted on a guess.
 */
#[CoversClass(Bot::class)]
#[CoversClass(GitManager::class)]
#[CoversClass(SubRepoSync::class)]
final class GitStatusFailureTest extends TestCase
{
    private const int USER = 555;
    private const string INDEX_ERROR = 'index file smaller than expected';
    private const string FAILED = 'git status failed: ';

    private string $root;
    private string $work;
    private string $origin;
    private Database $db;
    private FakeTelegram $tg;
    private Bot $bot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tggsf_'.uniqid('', true);
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
        file_put_contents($this->root.'/claude', "#!/bin/sh\necho x >> \"$(dirname \"\$0\")/calls.log\"\n[ -f \"$(dirname \"\$0\")/hook.sh\" ] && sh \"$(dirname \"\$0\")/hook.sh\"\ncat \"$(dirname \"\$0\")/out.json\"\n");
        chmod($this->root.'/claude', 0755);
        file_put_contents($this->root.'/out.json', '{"type":"result","is_error":false,"result":"done","session_id":"s-1","usage":{}}'."\n");

        $this->db = new Database($this->root.'/state');
        $this->tg = new FakeTelegram();
        $this->bot = $this->makeBot();
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

    private function hook(string $shell): void
    {
        file_put_contents($this->root.'/hook.sh', $shell."\n");
    }

    private function corruptIndex(?string $repo = null): void
    {
        file_put_contents(($repo ?? $this->work).'/.git/index', 'garbage');
    }

    private function config(string $subreposDir = ''): Config
    {
        return new Config(
            botToken: 't',
            allowedUserIds: [self::USER],
            repoPath: $this->work,
            githubRepo: '',
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
            promptsDir: 'prompts',
            githubOwners: new GithubOwners(['gpslab']),
            subreposDir: $subreposDir,
            botLocale: 'en',
            autoCommit: true,
        );
    }

    private function makeBot(?string $selfRepoPath = null): Bot
    {
        return new Bot($this->config(), $this->db, $this->tg, new GitManager($this->work, 30), new SessionFiles($this->root), null, null, $selfRepoPath);
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod(Bot::class, $method))->invokeArgs($this->bot, $args);
    }

    private function say(string $text): void
    {
        $this->call('enqueueMessage', [100, 1, 10, [['message_id' => 10, 'text' => $text, 'files' => []]]]);
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

    private function allText(): string
    {
        return implode("\n", array_column($this->tg->sent, 'text'));
    }

    private function head(): string
    {
        return trim($this->git('rev-parse HEAD'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buttons(): array
    {
        return array_values(array_filter($this->tg->sent, static fn (array $m): bool => $m['markup'] !== null));
    }

    // ---- GitManager --------------------------------------------------------

    public function testGitManagerThrowsOnABrokenRepository(): void
    {
        $this->corruptIndex();
        $git = new GitManager($this->work, 30);

        foreach ([
            'isClean' => static fn () => $git->isClean(),
            'hasChanges' => static fn () => $git->hasChanges(),
            'changedPathsIn' => static fn () => $git->changedPathsIn('prompts'),
            'treeSnapshot' => static fn () => $git->treeSnapshot(),
            'changedSince' => static fn () => $git->changedSince([]),
            'changesSummary' => static fn () => $git->changesSummary(),
        ] as $name => $call) {
            try {
                $call();
                self::fail($name.' did not throw');
            } catch (GitStatusException $e) {
                self::assertStringContainsString(self::INDEX_ERROR, $e->getMessage(), $name);
            }
        }
    }

    public function testGitManagerThrowsOutsideARepository(): void
    {
        $git = new GitManager($this->root, 30);
        $this->expectException(GitStatusException::class);
        $git->isClean();
    }

    public function testChangesSummaryOnAnUnbornBranchStillReturnsTheStatus(): void
    {
        $repo = $this->root.'/unborn';
        mkdir($repo);
        $this->git('init -q', $repo);
        file_put_contents($repo.'/n.txt', "n\n");

        $summary = (new GitManager($repo, 30))->changesSummary();

        self::assertStringContainsString('n.txt', $summary);
    }

    public function testTimeoutExitIsOneMoreNonZeroExitAndReadsTimeout(): void
    {
        $bin = $this->root.'/fakebin';
        mkdir($bin);
        $realGit = trim((string) shell_exec('command -v git'));
        file_put_contents($bin.'/git', "#!/bin/sh\nfor a in \"\$@\"; do [ \"\$a\" = status ] && exit 124; done\nexec $realGit \"\$@\"\n");
        chmod($bin.'/git', 0755);

        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);
        try {
            (new GitManager($this->work, 30))->isClean();
            self::fail('isClean did not throw');
        } catch (GitStatusException $e) {
            self::assertSame('timeout', $e->getMessage());
            self::assertSame(124, $e->getCode());
        } finally {
            putenv('PATH='.$path);
        }
    }

    // ---- Bot call sites ----------------------------------------------------

    public function testQueueHeadIsNotStartedAndRetriesWithTheStatusError(): void
    {
        $this->corruptIndex();
        $this->say('hello');
        $this->call('startQueueHead');

        self::assertFalse($this->activeJob());
        self::assertFileDoesNotExist($this->root.'/calls.log');
        self::assertNotNull($this->db->getRetry());
        self::assertStringContainsString(self::FAILED, $this->allText());
        self::assertStringContainsString(self::INDEX_ERROR, $this->allText());
        self::assertNull($this->db->getDecision());
        self::assertSame([], $this->buttons());
    }

    public function testQueuePauseIsNotTheDirtyTreePauseAndShowsItsOwnLine(): void
    {
        $this->say('hello');
        $this->corruptIndex();

        $error = null;
        self::assertFalse($this->call('isPausedByDirtyTree', [&$error]));
        $reason = $this->call('pauseReason');

        self::assertIsString($reason);
        self::assertStringStartsWith(self::FAILED, $reason);
        self::assertStringContainsString(self::INDEX_ERROR, $reason);
        self::assertStringNotContainsString('dirty', $reason);
    }

    public function testResultReplyNamesTheStatusFailureAndNothingIsCommitted(): void
    {
        $this->hook('echo new > "$PWD/created.txt"; echo garbage > "$PWD/.git/index"');
        $head = $this->head();

        $this->say('hello');
        $this->call('startQueueHead');
        $this->finishJob();

        $text = $this->allText();
        self::assertStringContainsString('could not read the working tree (git status failed: ', $text);
        self::assertStringContainsString('); nothing was committed', $text);
        self::assertStringNotContainsString('committed:', $text);
        self::assertSame($head, $this->head());
        // The owner of the unknown tree is kept.
        self::assertNotNull($this->db->getDirtyOwner());
    }

    public function testEpicRunLeftoversAreNotCommittedWhenStatusFails(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'a#1', 'after' => []]], []);
        $this->hook('echo j > "$PWD/journal.txt"; echo \'{}\' > "$WORKSPACE_ASSISTANT_EPIC_SIGNAL"; echo garbage > "$PWD/.git/index"');
        $head = $this->head();

        self::assertTrue($this->bot->enqueueEpicRun('demo', 'tick'));
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringContainsString('there are changes, but git failed: ', $this->allText());
        self::assertStringContainsString(self::INDEX_ERROR, $this->allText());
        self::assertSame($head, $this->head());
        self::assertFileExists($this->work.'/journal.txt');
        self::assertNotNull($this->db->getDirtyOwner());
    }

    public function testFailedEpicRunDoesNotRollBackOrClearTheOwnerWhenStatusFails(): void
    {
        file_put_contents($this->work.'/untracked.txt', "u\n");
        $this->db->setDirtyOwner(100, 1);
        $this->corruptIndex();

        $this->call('afterFailedRun', [100, 1, 'demo', 'process killed']);

        self::assertFileExists($this->work.'/untracked.txt');
        self::assertGlobEmpty($this->root.'/state/epics/demo/*.patch');
        self::assertNotNull($this->db->getDirtyOwner());
        self::assertStringContainsString(self::FAILED.'fatal', $this->allText());
        self::assertStringContainsString(self::INDEX_ERROR, $this->allText());
        $log = (string) file_get_contents($this->root.'/state/epics/demo/failures.log');
        self::assertStringContainsString('process killed', $log);
        self::assertStringContainsString(self::FAILED, $log);
    }

    public function testStatusFailureInPollJobFinallyDoesNotReplaceTheExceptionInFlight(): void
    {
        $this->hook('echo new > "$PWD/created.txt"; echo garbage > "$PWD/.git/index"');
        $this->say('hello');
        $this->call('startQueueHead');
        self::assertTrue($this->activeJob());
        // The final reply cannot be delivered: a transport failure after the request was sent is rethrown.
        $this->tg->sendFailures = [
            new TelegramTransportException('timeout', 28, true),
            new TelegramTransportException('timeout', 28, true),
        ];

        $thrown = null;
        for ($i = 0; $i < 200 && $thrown === null; ++$i) {
            try {
                $this->call('pollJob');
            } catch (\Throwable $e) {
                $thrown = $e;
            }
            usleep(50000);
        }

        self::assertInstanceOf(TelegramTransportException::class, $thrown);
        self::assertNotNull($this->db->getDirtyOwner());
    }

    public function testStaleDirtyStateIsKeptWhenStatusFails(): void
    {
        $this->db->setDecision(100, 1, 5);
        $this->db->setDirtyOwner(100, 1);
        $this->corruptIndex();

        $this->call('releaseStaleDirtyState');

        self::assertNotNull($this->db->getDecision());
        self::assertNotNull($this->db->getDirtyOwner());
    }

    public function testDecisionCallbackShowsTheStatusErrorAndKeepsTheDecision(): void
    {
        file_put_contents($this->work.'/a.txt', "dirty\n");
        $this->say('hello');
        $this->call('startQueueHead');
        $buttons = $this->buttons();
        self::assertCount(1, $buttons);
        $head = $this->head();
        $this->corruptIndex();

        $this->call('handleCallback', [['id' => 'cb', 'from' => ['id' => self::USER], 'data' => 'commit', 'message' => ['chat' => ['id' => 100], 'message_id' => $buttons[0]['id'], 'is_topic_message' => true, 'message_thread_id' => 1]]]);

        self::assertStringStartsWith(self::FAILED, (string) end($this->tg->answers));
        self::assertStringContainsString(self::INDEX_ERROR, (string) end($this->tg->answers));
        self::assertNotNull($this->db->getDecision());
        self::assertSame($head, $this->head());
    }

    public function testNoButtonsAreOfferedWhenStatusFails(): void
    {
        $this->corruptIndex();

        $this->call('offerDecisionIfDirty', [100, 1]);
        self::assertSame([], $this->buttons());
        self::assertStringContainsString(self::FAILED, $this->allText());
        self::assertNull($this->db->getDecision());

        $this->tg->sent = [];
        $this->call('ensureDecision', [100, 1]);
        self::assertSame([], $this->buttons());
        self::assertStringContainsString(self::FAILED, $this->allText());
        self::assertStringContainsString(self::INDEX_ERROR, $this->allText());
        self::assertNull($this->db->getDecision());
    }

    public function testRestartDoesNotPullTheOwnRepositoryWhenStatusFails(): void
    {
        $self = $this->root.'/self';
        $this->sh('git clone -q '.escapeshellarg($this->origin).' '.escapeshellarg($self).' 2>/dev/null');
        $head = trim($this->git('rev-parse HEAD', $self));
        $this->corruptIndex($self);
        $this->bot = $this->makeBot($self);

        $this->call('cmdRestart', [100, 1]);

        self::assertStringContainsString(self::FAILED, $this->allText());
        self::assertStringContainsString(self::INDEX_ERROR, $this->allText());
        self::assertStringContainsString('git pull skipped', $this->allText());
        self::assertSame($head, trim($this->git('rev-parse HEAD', $self)));
    }

    public function testPromptFilesCheckFailureIsAWarningNotNothingChanged(): void
    {
        $this->corruptIndex();

        self::assertSame([], $this->call('changedPromptFiles'));
        $note = $this->call('promptFilesNote');

        self::assertIsString($note);
        self::assertStringContainsString('could not check PROMPTS_DIR / LOCALES_DIR: ', $note);
        self::assertStringContainsString(self::INDEX_ERROR, $note);
    }

    public function testSubRepositoryWithUnknownStatusIsSkippedWithANote(): void
    {
        $subOrigin = $this->root.'/sub.git';
        $this->sh('git init -q -b main --bare '.escapeshellarg($subOrigin));
        mkdir($this->work.'/subs');
        $this->sh('git clone -q '.escapeshellarg($subOrigin).' '.escapeshellarg($this->work.'/subs/x').' 2>/dev/null');
        $this->corruptIndex($this->work.'/subs/x');

        $notes = (new SubRepoSync($this->config('subs'), new Locale('en')))->pullSubRepos();

        self::assertCount(1, $notes);
        self::assertStringContainsString('x', $notes[0]);
        self::assertStringContainsString(self::FAILED, $notes[0]);
        self::assertStringContainsString(self::INDEX_ERROR, $notes[0]);
        self::assertStringContainsString('pull skipped', $notes[0]);
    }

    private static function assertGlobEmpty(string $pattern): void
    {
        self::assertSame([], glob($pattern) ?: []);
    }
}
