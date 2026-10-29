<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\ClaudeErrorText;
use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\JobRunner;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\ModeAndLang;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use GpsLab\WorkspaceAssistant\TelegramClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Bot` needs heavy dependencies (Config/Database/TelegramClient/GitManager/
 * SessionFiles) for its constructor. Some of the parsing (Keyboards::mode,
 * ModeAndLang::parseModeCallback) is pure static methods, tested directly
 * without instantiating the bot. The handleCallback/
 * handleModeCallback wiring (routing before/after $pending, thread_id source)
 * is tested on a real Bot instance: Config/Database/GitManager/
 * SessionFiles are cheap local objects (temp dir, no network), and
 * TelegramClient is replaced with createMock() (see the TelegramClient class
 * docblock — the class is non-final precisely for this).
 */
#[CoversClass(Bot::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(SubRepoSync::class)]
final class BotTest extends TestCase
{
    private const int ALLOWED_USER_ID = 555;

    private string $dbDir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbDir = sys_get_temp_dir().'/tgbot_'.uniqid('', true);
        mkdir($this->dbDir);
        $this->db = new Database($this->dbDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbDir.'/state.sqlite');
        @rmdir($this->dbDir);
    }

    private function makeConfig(string $repoPath = '', string $subreposDir = ''): Config
    {
        return new Config(
            botToken: 'test-token',
            allowedUserIds: [self::ALLOWED_USER_ID],
            repoPath: $repoPath !== '' ? $repoPath : sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: sys_get_temp_dir(),
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 120,
            forgeOwners: new ForgeOwners(['anime-db', 'gpslab']),
            subreposDir: $subreposDir,
            botLocale: 'en',
        );
    }

    private function makeSubRepoSync(string $repoPath, string $subreposDir = ''): SubRepoSync
    {
        return new SubRepoSync($this->makeConfig($repoPath, $subreposDir), new Locale('en'));
    }

    private function makeBot(?TelegramClient $tg = null, string $repoPath = '', string $subreposDir = ''): Bot
    {
        $config = $this->makeConfig($repoPath, $subreposDir);

        return new Bot(
            $config,
            $this->db,
            $tg ?? new FakeTelegram(),
            new GitManager(sys_get_temp_dir()),
            new SessionFiles(sys_get_temp_dir()),
        );
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function invokeHandleCallback(Bot $bot, array $callback): void
    {
        $method = new \ReflectionMethod(Bot::class, 'handleCallback');
        $method->invoke($bot, $callback);
    }

    /**
     * The /mode gate looks at "this topic has a running job" (activeJob + jobContext) —
     * a constructed but not started ClaudeRunner is enough.
     */
    private function markJobActive(Bot $bot, int $chatId = 100, int $threadId = 0): void
    {
        $state = (new \ReflectionProperty(Bot::class, 'runState'))->getValue($bot);
        $state->activeJob = new ClaudeRunner('claude', sys_get_temp_dir(), 0, []);
        $state->jobContext = [
            'kind' => 'chat',
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'thinking_id' => 0,
            'prompt' => '',
            'last_progress_at' => 0,
            'shown_count' => 0,
            'compact_old_session_id' => null,
            'session_saved' => false,
            'inbox_files' => [],
            'mode' => 'pm',
            'locale' => 'en',
            'queue_id' => 0,
            'epic_slug' => null,
            'epic_run' => null,
            'pm_gate_run_id' => null,
            'prompt_hash' => null,
        ];
    }

    private function fakeTelegram(): FakeTelegram
    {
        return new FakeTelegram();
    }

    private function invokeHandleCommand(Bot $bot, int $chatId, int $threadId, string $text): void
    {
        $method = new \ReflectionMethod(Bot::class, 'handleCommand');
        $method->invoke($bot, $chatId, $threadId, $text);
    }

    public function testAlbumWithOversizedFileIsRejectedWholeBeforeAnyDownload(): void
    {
        $tg = $this->createMock(TelegramClient::class);
        $tg->expects(self::never())->method('getFilePath');
        $tg->expects(self::once())->method('sendText')->with(
            100,
            self::stringContains('big.zip'),
            self::anything(),
            0,
        );
        $bot = $this->makeBot($tg);

        $method = new \ReflectionMethod(Bot::class, 'enqueueFiles');
        $method->invoke($bot, 100, 0, 5, null, [
            ['file_id' => 'a', 'extension' => 'jpg', 'name' => 'small.jpg', 'file_size' => 100],
            ['file_id' => 'b', 'extension' => 'zip', 'name' => 'big.zip', 'file_size' => 21 * 1024 * 1024],
        ], [5, 6]);
        self::assertSame([], $this->db->queueWaiting());
    }

    public function testModeCallbackReadsThreadIdFromCallbackDataNotTruncatedMessage(): void
    {
        $bot = $this->makeBot();

        // A truncated message, as Telegram sends it for a sufficiently
        // old callback — without is_topic_message/message_thread_id.
        // threadIdOf() on such a message would silently return 0.
        $this->invokeHandleCallback($bot, [
            'id' => 'cb1',
            'from' => ['id' => self::ALLOWED_USER_ID],
            'data' => 'mode:review:77',
            'message' => ['chat' => ['id' => 100], 'message_id' => 777],
        ]);

        self::assertSame('review', $this->db->getMode(100, 77));
        self::assertSame('pm', $this->db->getMode(100, 0));
    }

    public function testModeCallbackDoesNotClearPendingDecision(): void
    {
        $bot = $this->makeBot();

        $this->db->setDecision(100, 0, 55);

        $this->invokeHandleCallback($bot, [
            'id' => 'cb1',
            'from' => ['id' => self::ALLOWED_USER_ID],
            'data' => 'mode:arch:0',
            'message' => ['chat' => ['id' => 100], 'message_id' => 777],
        ]);

        self::assertSame(['chat_id' => 100, 'thread_id' => 0, 'message_id' => 55], $this->db->getDecision());
    }

    public function testModeCallbackDoesNotChangeModeWhileJobActive(): void
    {
        $bot = $this->makeBot($this->fakeTelegram());
        $this->markJobActive($bot);

        $this->invokeHandleCallback($bot, [
            'id' => 'cb1',
            'from' => ['id' => self::ALLOWED_USER_ID],
            'data' => 'mode:review:0',
            'message' => ['chat' => ['id' => 100], 'message_id' => 777],
        ]);

        self::assertSame('pm', $this->db->getMode(100, 0));
    }

    public function testModeCallbackChangesModeWhileJobOfAnotherTopicIsActive(): void
    {
        $bot = $this->makeBot($this->fakeTelegram());
        $this->markJobActive($bot, 100, 5);

        $this->invokeHandleCallback($bot, [
            'id' => 'cb1',
            'from' => ['id' => self::ALLOWED_USER_ID],
            'data' => 'mode:review:0',
            'message' => ['chat' => ['id' => 100], 'message_id' => 777],
        ]);

        self::assertSame('review', $this->db->getMode(100, 0));
    }

    public function testModeTextCommandDoesNotChangeModeWhileJobActive(): void
    {
        $bot = $this->makeBot($this->fakeTelegram());
        $this->markJobActive($bot);

        $this->invokeHandleCommand($bot, 100, 0, '/mode review');

        self::assertSame('pm', $this->db->getMode(100, 0));
    }

    public function testModeCallbackAddsSessionWarningToEditedMessageWhenSessionIsNotEmpty(): void
    {
        $this->db->setSessionId(100, 0, 'session-1');

        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg);

        $this->invokeHandleCallback($bot, [
            'id' => 'cb1',
            'from' => ['id' => self::ALLOWED_USER_ID],
            'data' => 'mode:review:0',
            'message' => ['chat' => ['id' => 100], 'message_id' => 777],
        ]);

        self::assertSame('review', $this->db->getMode(100, 0));
        self::assertCount(1, $tg->edits);
        self::assertSame(777, $tg->edits[0]['message_id']);
        self::assertStringContainsString((new Locale('en'))->t('mode.session_warning'), $tg->edits[0]['text']);
    }

    private function formatClaudeError(string $text): string
    {
        return (new ClaudeErrorText(new Locale('en')))->format($text);
    }

    public function testPromptWarningsGoToChatOutsideSendResult(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg);
        $runner = (new \ReflectionProperty(Bot::class, 'jobRunner'))->getValue($bot);
        $method = new \ReflectionMethod(JobRunner::class, 'reportPromptWarnings');

        $method->invoke($runner, 100, 0, ['Prompt file p/arch.md is missing, empty or unreadable.'], true);
        $method->invoke($runner, 100, 0, ['quiet'], false);

        self::assertCount(1, $tg->sent);
        self::assertStringContainsString('⚠️ Prompt file p/arch.md', $tg->sent[0]['text']);
    }

    public function testRecognizesSessionExpiredError(): void
    {
        $message = $this->formatClaudeError('No conversation found with session ID: abc-123');

        self::assertStringContainsString('/new', $message);
        self::assertStringNotContainsString('No conversation found with session ID', $message);
    }

    public function testGenericErrorWithEmptyResultShowsFallback(): void
    {
        self::assertSame('Claude error: no text', $this->formatClaudeError(''));
    }

    public function testGenericErrorShowsRawText(): void
    {
        self::assertSame('Claude error: Failed to authenticate', $this->formatClaudeError('Failed to authenticate'));
    }

    public function testWeeklyLimitStillRecognized(): void
    {
        $message = $this->formatClaudeError("You've hit your weekly limit · resets Jun 30, 10pm (Europe/Moscow)");

        self::assertSame('⏳ The weekly Claude limit is exhausted — resets Jun 30 at 22:00', $message);
    }

    public function testSessionLimitStillRecognized(): void
    {
        $message = $this->formatClaudeError("You've hit your session limit · resets 3pm (Europe/Moscow)");

        self::assertSame('⏳ The Claude limit is exhausted — resets at 15:00', $message);
    }

    /**
     * @return array{mode: string, thread_id: int}|null
     */
    private function parseModeCallback(string $data): ?array
    {
        return ModeAndLang::parseModeCallback($data);
    }

    public function testParseModeCallbackAcceptsKnownModeAndThread(): void
    {
        self::assertSame(
            ['mode' => 'review', 'thread_id' => 4323],
            $this->parseModeCallback('mode:review:4323'),
        );
    }

    public function testParseModeCallbackAcceptsZeroThread(): void
    {
        self::assertSame(
            ['mode' => 'arch', 'thread_id' => 0],
            $this->parseModeCallback('mode:arch:0'),
        );
    }

    public function testParseModeCallbackRejectsUnknownMode(): void
    {
        self::assertNull($this->parseModeCallback('mode:bogus:4323'));
    }

    public function testParseModeCallbackRejectsNonNumericThread(): void
    {
        self::assertNull($this->parseModeCallback('mode:review:abc'));
    }

    public function testParseModeCallbackRejectsMissingParts(): void
    {
        self::assertNull($this->parseModeCallback('mode:review'));
    }

    public function testParseModeCallbackRejectsForeignPrefix(): void
    {
        self::assertNull($this->parseModeCallback('commit:review:4323'));
    }

    public function testParseModeCallbackRejectsGarbage(): void
    {
        self::assertNull($this->parseModeCallback('garbage'));
    }

    /**
     * @return string repo path containing <subdir>/sub, a git clone with an upstream-less HEAD
     */
    private function makeRepoWithSubrepo(string $subdir): string
    {
        $repo = sys_get_temp_dir().'/tgsub_'.uniqid('', true);
        $sub = $repo.'/'.$subdir.'/sub';
        mkdir($sub, 0777, true);
        $cmd = 'cd '.escapeshellarg($sub).' && git init -q && git -c user.email=a@b -c user.name=t commit -q --allow-empty -m init';
        exec($cmd.' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        return $repo;
    }

    private function removeDir(string $dir): void
    {
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function testSubreposIgnoredWhenDirNotConfigured(): void
    {
        $repo = $this->makeRepoWithSubrepo('docs');
        // A clone directly under REPO_PATH must be ignored too: without the guards the scan root is the repo itself.
        exec('cp -r '.escapeshellarg($repo.'/docs/sub').' '.escapeshellarg($repo.'/sub'));
        try {
            $sync = $this->makeSubRepoSync($repo);

            self::assertSame([], $sync->snapshotSubRepos());
            self::assertSame([], $sync->pullSubRepos());
        } finally {
            $this->removeDir($repo);
        }
    }

    public function testSubreposSnapshotUsesConfiguredDir(): void
    {
        $repo = $this->makeRepoWithSubrepo('components');
        try {
            self::assertSame([], $this->makeSubRepoSync($repo, 'docs')->snapshotSubRepos());
            self::assertSame(
                [$repo.'/components/sub'],
                array_keys($this->makeSubRepoSync($repo, 'components')->snapshotSubRepos()),
            );
        } finally {
            $this->removeDir($repo);
        }
    }

    public function testSubreposPullSkipsDirtyCloneInConfiguredDir(): void
    {
        $repo = $this->makeRepoWithSubrepo('components');
        file_put_contents($repo.'/components/sub/new.txt', 'x');
        try {
            $notes = $this->makeSubRepoSync($repo, 'components')->pullSubRepos();

            self::assertCount(1, $notes);
            self::assertStringContainsString('sub: uncommitted changes', $notes[0]);
        } finally {
            $this->removeDir($repo);
        }
    }

    public function testSubreposPullCleanCloneIsNotReportedDirty(): void
    {
        $repo = $this->makeRepoWithSubrepo('components');
        try {
            $notes = $this->makeSubRepoSync($repo, 'components')->pullSubRepos();

            foreach ($notes as $note) {
                self::assertStringNotContainsString('uncommitted changes', $note);
            }
        } finally {
            $this->removeDir($repo);
        }
    }
}
