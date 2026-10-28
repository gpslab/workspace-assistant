<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\GitHub;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\TaskState;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\MarkdownConverter;
use GpsLab\WorkspaceAssistant\PmRunbook;
use GpsLab\WorkspaceAssistant\Queue\QueueScheduler;
use GpsLab\WorkspaceAssistant\RunState;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end epic tests: the /epic commands, an isolated run and the signal — on a
 * real git and a fake `claude` (the script writes argv/env to a log, drops the prepared
 * signal and outbox file, prints the prepared result).
 */
#[CoversClass(Bot::class)]
#[CoversClass(Run::class)]
final class FlowTest extends TestCase
{
    private const int CHAT = 100;
    private const int THREAD = 7;
    private const string PLAN = '{"tasks":[{"task":"a#1"},{"task":"a#2","after":["a#1"]}],"releases":[{"repo":"a","version":"v1.0.0","after":["a#2"]}]}';

    private string $root;
    private string $work;
    private Database $db;
    private FakeTelegram $tg;
    private Bot $bot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tga_'.uniqid('', true);
        mkdir($this->root);
        $origin = $this->root.'/origin.git';
        $this->work = $this->root.'/work';
        $this->sh('git init -q --bare '.escapeshellarg($origin));
        $this->sh('git clone -q '.escapeshellarg($origin).' '.escapeshellarg($this->work).' 2>/dev/null');
        foreach (['config user.email t@t.t', 'config user.name test', 'checkout -q -b main'] as $cmd) {
            $this->sh('git -C '.escapeshellarg($this->work).' '.$cmd);
        }
        file_put_contents($this->work.'/a.txt', "a\n");
        $this->commitAll();

        foreach (['state', 'outbox', 'inbox'] as $dir) {
            mkdir($this->root.'/'.$dir);
        }
        $r = $this->root;
        file_put_contents($r.'/claude', <<<SH
            #!/bin/sh
            echo "ARGV: \$@" >> {$r}/args.log
            echo "\$WORKSPACE_ASSISTANT_EPIC_SIGNAL" > {$r}/env.log
            [ -f {$r}/signal.src ] && mkdir -p "\$(dirname "\$WORKSPACE_ASSISTANT_EPIC_SIGNAL")" && cp {$r}/signal.src "\$WORKSPACE_ASSISTANT_EPIC_SIGNAL"
            [ -f {$r}/hook.sh ] && sh {$r}/hook.sh
            [ -f {$r}/report.src ] && cp {$r}/report.src {$r}/outbox/report.md
            cat {$r}/out.json
            SH."\n");
        chmod($r.'/claude', 0755);
        $this->claudeOutput('{"type":"result","is_error":false,"result":"service chatter","session_id":"epic-sess","usage":{}}');

        $this->db = new Database($r.'/state');
        $this->tg = new FakeTelegram();
        $this->buildBot(0);
    }

    private function buildBot(int $claudeTimeout, string $promptsDir = '', ?GithubOwners $owners = null, ?GitHub $github = null, string $locale = 'en'): void
    {
        $r = $this->root;
        $config = new Config(
            botToken: 't',
            allowedUserIds: [555],
            repoPath: $this->work,
            githubRepo: '',
            claudeBin: $r.'/claude',
            claudeTimeout: $claudeTimeout,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $r.'/state',
            outboxDir: $r.'/outbox',
            inboxDir: $r.'/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            epicsDir: 'context/epics',
            promptsDir: $promptsDir,
            githubOwners: $owners ?? new GithubOwners(['anime-db', 'gpslab']),
            botLocale: $locale,
        );
        $this->bot = new Bot($config, $this->db, $this->tg, new GitManager($this->work, 30), new SessionFiles($r), $github);
    }

    protected function tearDown(): void
    {
        $this->sh('rm -rf '.escapeshellarg($this->root));
    }

    private function assertShOk(string $cmd): void
    {
        exec($cmd.' 2>&1', $out, $code);
        self::assertSame(0, $code, $cmd."\n".implode("\n", $out));
    }

    private function sh(string $cmd): void
    {
        exec($cmd.' 2>&1');
    }

    private function commitAll(): void
    {
        $w = escapeshellarg($this->work);
        $this->sh("git -C {$w} add -A && git -C {$w} commit -q -m c && git -C {$w} push -q -u origin main");
    }

    private function claudeOutput(string $json): void
    {
        file_put_contents($this->root.'/out.json', $json."\n");
    }

    private function writePlan(string $slug, string $json = self::PLAN): void
    {
        @mkdir($this->work.'/context/epics', 0775, true);
        file_put_contents($this->work.'/context/epics/'.$slug.'.md', "# Plan\n\n```json\n{$json}\n```\n");
        $this->commitAll();
    }

    private function signal(string $json): void
    {
        file_put_contents($this->root.'/signal.src', $json);
    }

    private function args(): string
    {
        $log = $this->root.'/args.log';

        return is_file($log) ? (string) file_get_contents($log) : '';
    }

    private function command(string $text, int $thread = self::THREAD): void
    {
        (new \ReflectionMethod(Bot::class, 'handleCommand'))->invoke($this->bot, self::CHAT, $thread, $text, 0);
    }

    private function call(string $method): mixed
    {
        return (new \ReflectionMethod(Bot::class, $method))->invoke($this->bot);
    }

    private function runState(): RunState
    {
        return (new \ReflectionProperty(Bot::class, 'runState'))->getValue($this->bot);
    }

    private function queueScheduler(): QueueScheduler
    {
        return (new \ReflectionProperty(Bot::class, 'queueScheduler'))->getValue($this->bot);
    }

    private function activeJob(): bool
    {
        return $this->runState()->activeJob !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jobContext(): ?array
    {
        return $this->runState()->jobContext;
    }

    private function finishJob(): void
    {
        for ($i = 0; $i < 200 && $this->activeJob(); ++$i) {
            $this->call('pollJob');
            usleep(50000);
        }
        self::assertFalse($this->activeJob(), 'the job did not finish');
    }

    public function testStartAndStatusLinkPlanAndJournalToGithub(): void
    {
        $this->writePlan('demo');
        $this->sh('git -C '.escapeshellarg($this->work).' remote set-url origin git@github.com:anime-db/ws.git');
        $this->command('/epic start demo');
        $this->command('/epic status');

        $base = 'https://github.com/anime-db/ws/blob/main/context/epics/';
        self::assertStringContainsString('[context/epics/demo.md]('.$base.'demo.md)', $this->allText());
        // The journal does not exist yet — no link, so as not to lead to a 404.
        self::assertStringContainsString('Journal: context/epics/demo.journal.md (not created yet)', $this->allText());

        file_put_contents($this->work.'/context/epics/demo.journal.md', "# Journal\n");
        $this->command('/epic status');
        self::assertStringContainsString('[context/epics/demo.journal.md]('.$base.'demo.journal.md)', $this->allText());
    }

    public function testStartRepliesEvenWhenCardSendFails(): void
    {
        $this->writePlan('demo');
        $this->tg->plainFails = true;
        $this->command('/epic start demo');

        self::assertStringContainsString('🚀 Epic demo started', $this->allText());
    }

    public function testLinkWithUnderscoreSlugSurvivesHtmlConversion(): void
    {
        $this->writePlan('my__epic');
        $this->sh('git -C '.escapeshellarg($this->work).' remote set-url origin git@github.com:anime-db/ws.git');
        $this->command('/epic start my__epic');

        $replies = array_filter($this->tg->sent, static fn (array $m): bool => !$m['silent']);
        $html = (new MarkdownConverter())->convert(implode("\n", array_column($replies, 'text')));
        self::assertStringContainsString(
            '<a href="https://github.com/anime-db/ws/blob/main/context/epics/my__epic.md">context/epics/my__epic.md</a>',
            $html,
        );
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testStartWithoutGithubRemoteShowsPlainPath(): void
    {
        $this->startEpic();

        self::assertStringContainsString('Plan: context/epics/demo.md', $this->allText());
    }

    private function startEpic(string $slug = 'demo'): void
    {
        $this->writePlan($slug);
        $this->command('/epic start '.$slug);
        self::assertNotNull($this->db->epicGet($slug));
    }

    /** Queues a run, starts it and waits for completion. */
    private function runEpic(string $run = Run::TICK, ?string $pr = null): void
    {
        self::assertTrue($this->queueScheduler()->enqueueEpicRun('demo', $run, $pr));
        $this->call('startQueueHead');
        self::assertTrue($this->activeJob());
        $this->finishJob();
    }

    private function allText(): string
    {
        return implode("\n", $this->tg->textsTo(self::CHAT, self::THREAD));
    }

    public function testStartSendsSilentStatusCardAndStoresItsId(): void
    {
        $this->startEpic();

        $cards = array_values(array_filter($this->tg->sent, static fn (array $m): bool => str_contains($m['text'], 'Epic demo: running') && str_contains($m['text'], 'Tasks:')));
        self::assertCount(1, $cards);
        self::assertTrue($cards[0]['silent']);
        self::assertSame(self::THREAD, $cards[0]['thread']);
        self::assertSame($cards[0]['id'], $this->db->epicGet('demo')['card_message_id'] ?? null);
        self::assertSame([$cards[0]['id']], $this->tg->pinned);
    }

    public function testStatusShowsLastPollTime(): void
    {
        $this->startEpic();
        $this->command('/epic status');
        self::assertStringContainsString('Last GitHub poll: not yet', $this->allText());
    }

    public function testStartCreatesRunningEpicBoundToTopic(): void
    {
        $this->startEpic();

        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('running', $epic['status']);
        self::assertSame([self::CHAT, self::THREAD], [$epic['chat_id'], $epic['thread_id']]);
        self::assertSame(['a#1', 'a#2'], array_column($this->db->epicTasks('demo'), 'task'));
        self::assertSame([['repo' => 'a', 'version' => 'v1.0.0', 'after' => ['a#2']]], $this->db->epicReleases('demo'));
        self::assertSame('demo', $this->db->epicByThread(self::CHAT, self::THREAD)['slug'] ?? null);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function invalidPlans(): iterable
    {
        yield 'no file' => [null, 'not found'];
        yield 'no block' => ["# just text\n", 'has no ```json block'];
        yield 'invalid json' => ["```json\n{oops\n```\n", 'cannot be parsed'];
        yield 'task is not repo#N' => ["```json\n{\"tasks\":[{\"task\":\"x\"}]}\n```\n", 'repo#N'];
        yield 'after not from the plan' => ["```json\n{\"tasks\":[{\"task\":\"a#1\",\"after\":[\"b#2\"]}]}\n```\n", 'not in the plan'];
        yield 'release without repo' => ["```json\n{\"tasks\":[{\"task\":\"a#1\"}],\"releases\":[{\"version\":\"1\",\"after\":[\"a#1\"]}]}\n```\n", 'repo'];
        yield 'cycle' => ["```json\n{\"tasks\":[{\"task\":\"a#1\",\"after\":[\"a#2\"]},{\"task\":\"a#2\",\"after\":[\"a#1\"]}]}\n```\n", 'cycle in after'];
    }

    #[DataProvider('invalidPlans')]
    public function testStartWithInvalidPlanIsRefusedAndNothingCreated(?string $content, string $reason): void
    {
        if ($content !== null) {
            @mkdir($this->work.'/context/epics', 0775, true);
            file_put_contents($this->work.'/context/epics/bad.md', $content);
        }

        $this->command('/epic start bad');

        self::assertNull($this->db->epicGet('bad'));
        self::assertStringContainsString('The epic was not created', $this->allText());
        self::assertStringContainsString($reason, $this->allText());
    }

    public function testStartRejectsPathLikeSlug(): void
    {
        $this->command('/epic start ../etc');

        self::assertNull($this->db->epicByThread(self::CHAT, self::THREAD));
        self::assertStringContainsString('slug', $this->allText());
    }

    public function testPauseResumeAndStatus(): void
    {
        $this->startEpic();

        $this->command('/epic pause');
        self::assertSame('paused', $this->db->epicGet('demo')['status'] ?? null);

        $this->command('/epic status');
        self::assertStringContainsString('demo: paused', $this->allText());

        $this->command('/epic resume');
        self::assertSame('running', $this->db->epicGet('demo')['status'] ?? null);
    }

    public function testResumeFromBlockedClearsBlockedTasks(): void
    {
        $this->startEpic();
        $this->db->epicSetStatus('demo', 'blocked');
        $this->db->epicSetTaskStatus('demo', 'a#1', 'blocked');

        $this->command('/epic resume');

        self::assertSame('running', $this->db->epicGet('demo')['status'] ?? null);
        self::assertSame(['open', 'open'], array_column($this->db->epicTasks('demo'), 'status'));
    }

    public function testResumeOnRunningEpicUnblocksTasks(): void
    {
        $this->startEpic();
        $this->db->epicSetTaskStatus('demo', 'a#1', 'blocked');

        $this->command('/epic resume');

        self::assertSame('running', $this->db->epicGet('demo')['status'] ?? null);
        self::assertSame(['open', 'open'], array_column($this->db->epicTasks('demo'), 'status'));
        self::assertStringContainsString('blocked removed from tasks: a#1', $this->allText());
    }

    public function testResumeOnDoneEpicIsRefused(): void
    {
        $this->startEpic();
        $this->db->epicSetStatus('demo', 'done');
        $this->db->epicSetTaskStatus('demo', 'a#1', 'blocked');

        $this->command('/epic resume');

        self::assertSame('done', $this->db->epicGet('demo')['status'] ?? null);
        self::assertSame('blocked', $this->db->epicTasks('demo')[0]['status']);
        self::assertStringContainsString('is already finished', $this->allText());
    }

    public function testResumeOnRunningEpicWithoutBlockedTasksIsRefused(): void
    {
        $this->startEpic();
        $this->db->epicSetTaskCounter('demo', 'a#1', 'conflict_head', 'h1');

        $this->command('/epic resume');

        self::assertStringContainsString('is neither paused nor blocked', $this->allText());
        self::assertSame('h1', $this->db->epicTasks('demo')[0]['counters']['conflict_head']);
    }

    public function testMissingPersonaFilesAreReportedToChatAfterEpicRun(): void
    {
        $this->buildBot(0, 'no-such-prompts');
        $this->startEpic();
        $this->signal('{}');

        $this->runEpic(Run::TICK);

        self::assertStringContainsString('⚠️ Prompt file no-such-prompts/', $this->allText());
    }

    public function testEpicRunChangingPromptFileWarnsBeforeAutoCommit(): void
    {
        $this->buildBot(0, 'prompts');
        $this->startEpic();
        $this->signal('{}');
        file_put_contents($this->root.'/hook.sh', "mkdir -p {$this->work}/prompts && echo injected > {$this->work}/prompts/review.md\n");

        $this->runEpic(Run::TICK);

        self::assertStringContainsString('changed prompt files', $this->allText());
        self::assertStringContainsString('prompts/review.md', $this->allText());
    }

    public function testMissingPersonaFilesAreReportedToChatAfterClaudeError(): void
    {
        $this->buildBot(0, 'no-such-prompts');
        $this->startEpic();
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"epic-sess","usage":{}}');

        $this->runEpic(Run::TICK);

        self::assertStringContainsString('⚠️ Prompt file no-such-prompts/', $this->allText());
    }

    public function testRunPromptCarriesRunbookLineWithInstanceAddition(): void
    {
        mkdir($this->work.'/prompts-x');
        file_put_contents($this->work.'/prompts-x/pm_runbook.md', 'addition');
        file_put_contents($this->work.'/.git/info/exclude', "prompts-x/\n", FILE_APPEND);
        $this->buildBot(0, 'prompts-x');
        $this->startEpic();
        $this->signal('{}');

        $this->runEpic(Run::TICK);

        self::assertStringContainsString(PmRunbook::line($this->work.'/prompts-x'), $this->args());
        self::assertStringContainsString('(and the instance addition '.$this->work.'/prompts-x/pm_runbook.md)', $this->args());
    }

    public function testRunPromptCarriesContractAndSignalPathGoesThroughEnvironment(): void
    {
        $this->startEpic();
        $this->signal('{}');

        $this->runEpic(Run::TICK);

        $args = $this->args();
        self::assertStringContainsString('demo', $args);
        self::assertStringContainsString($this->work.'/context/epics/demo.md', $args);
        self::assertStringContainsString('context/epics/demo.journal.md', $args);
        self::assertStringContainsString(Run::runbookPath(), $args);
        self::assertStringContainsString('Outbox directory: '.$this->root.'/outbox', $args);
        self::assertStringContainsString('Run kind: tick', $args);
        self::assertStringContainsString('<!-- epic:demo -->', $args);
        self::assertStringContainsString('every issue or PR comment', $args);
        self::assertStringContainsString('openronin clarification', $args);
        self::assertSame($this->root.'/state/epics/demo/signal.json', trim((string) file_get_contents($this->root.'/env.log')));
    }

    public function testEpicAdditionReachesEpicRunAfterRunbookButNotChatRun(): void
    {
        $marker = 'EPIC-ADDITION-'.bin2hex(random_bytes(6));
        mkdir($this->work.'/prompts');
        file_put_contents($this->work.'/prompts/epic.md', $marker."\n");
        $this->buildBot(0, 'prompts');
        $this->startEpic();
        $this->signal('{}');

        $this->runEpic(Run::TICK);

        $args = $this->args();
        self::assertStringContainsString($marker, $args);
        self::assertGreaterThan(strpos($args, Run::runbookPath()), strpos($args, $marker));

        file_put_contents($this->root.'/args.log', '');
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 10, [['message_id' => 10, 'text' => 'hello', 'files' => []]]);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringContainsString('hello', $this->args());
        self::assertStringNotContainsString($marker, $this->args());
    }

    public function testTickAndFinalPromptsCarryFailuresLogPath(): void
    {
        $this->startEpic();
        foreach ([Run::TICK, Run::FINAL] as $run) {
            $this->signal('{}');
            $this->runEpic($run);

            $args = $this->args();
            self::assertStringContainsString($this->root.'/state/epics/demo/failures.log', $args);
            self::assertStringContainsString('carry every entry not yet in the journal', $args);
        }
    }

    public function testRunKindReachesPmGateHookAndReviewKeepsStopHook(): void
    {
        $this->startEpic();
        $expected = [Run::TICK => 'tick', Run::FINAL => 'final', Run::REVIEW => 'epic_review'];
        foreach ($expected as $run => $kind) {
            $this->signal('{}');
            file_put_contents($this->root.'/args.log', '');
            $this->runEpic($run, $run === Run::REVIEW ? 'a#1' : null);

            $args = $this->args();
            self::assertMatchesRegularExpression("~pm-gate-hook\\.php' '[^']+' '{$kind}' '\\d+-[0-9a-f]+'~", $args, $run);
            self::assertSame($run === Run::REVIEW, str_contains($args, 'review-stop-hook.php'), $run);
        }
        self::assertSame([], glob($this->root.'/state/pm-gate/*') ?: [], 'the gate files are removed after the run');
    }

    private const string APPLY_SKIP = 'echo "typo only" > "${WORKSPACE_ASSISTANT_PM_SKIP%.skip}.applied"';

    public function testPmGateAppliedSkipIsShownInEpicRun(): void
    {
        $this->startEpic();
        $this->signal('{}');
        file_put_contents($this->root.'/hook.sh', self::APPLY_SKIP."\n");

        $this->runEpic(Run::TICK);

        self::assertStringContainsString('typo only', $this->allText());
    }

    public function testPmGateUnusedSkipFileIsNotReported(): void
    {
        $this->startEpic();
        $this->signal('{}');
        file_put_contents($this->root.'/hook.sh', 'echo "typo only" > "$WORKSPACE_ASSISTANT_PM_SKIP"'."\n");

        $this->runEpic(Run::TICK);

        self::assertStringNotContainsString('typo only', $this->allText());
    }

    public function testPmGateAppliedSkipIsShownInPmChatReply(): void
    {
        $this->db->setMode(self::CHAT, self::THREAD, 'pm');
        file_put_contents($this->root.'/hook.sh', self::APPLY_SKIP."\n");
        $this->claudeOutput('{"type":"result","is_error":false,"result":"designed","session_id":"chat-sess","usage":{}}');

        $this->chatMessage();

        self::assertStringContainsString('typo only', $this->allText());
    }

    public function testPmGateAppliedSkipIsShownWhenChatRunFails(): void
    {
        $this->db->setMode(self::CHAT, self::THREAD, 'pm');
        file_put_contents($this->root.'/hook.sh', self::APPLY_SKIP."\n");
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"chat-sess","usage":{}}');

        $this->chatMessage();

        self::assertStringContainsString('typo only', $this->allText());
        self::assertFalse($this->activeJob());
    }

    public function testPmGateAppliedSkipIsShownWhenChatRunTimesOut(): void
    {
        $this->db->setMode(self::CHAT, self::THREAD, 'pm');
        $this->buildBot(1);
        file_put_contents($this->root.'/claude', "#!/bin/sh\n".self::APPLY_SKIP."\nsleep 10\n");

        $this->chatMessage();

        self::assertStringContainsString('typo only', $this->allText());
        self::assertFalse($this->activeJob());
    }

    private function chatMessage(): void
    {
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 501, [['text' => 'design it', 'message_id' => 501]], 0);
        $this->call('startQueueHead');
        $this->finishJob();
    }

    public function testReviewPromptRequiresMarkerInReviewBodyAndModes(): void
    {
        $this->startEpic();
        $this->signal('{}');

        self::assertTrue($this->queueScheduler()->enqueueEpicRun('demo', Run::REVIEW, 'a#1'));
        self::assertSame('review', $this->db->queueHead()['mode'] ?? null);
        $this->call('startQueueHead');
        self::assertSame('review', $this->jobContext()['mode'] ?? null);
        $this->finishJob();

        self::assertStringContainsString('body of the review', $this->args());
        self::assertStringContainsString('<!-- epic:demo -->', $this->args());
        self::assertStringContainsString('Run kind: review', $this->args());
        self::assertStringContainsString('Pull request: a#1', $this->args());

        foreach ([Run::TICK, Run::FINAL] as $run) {
            self::assertTrue($this->queueScheduler()->enqueueEpicRun('demo', $run));
            self::assertSame('pm', $this->db->queueHead()['mode'] ?? null);
            $this->call('startQueueHead');
            self::assertSame('pm', $this->jobContext()['mode'] ?? null);
            $this->finishJob();
        }
    }

    /**
     * @param list<array{id: string, source: string, path: ?string, line: ?int, body: string}> $findings
     */
    private function storeCold(array $findings, string $head = 'h1'): void
    {
        ColdFindings::save(ColdFindings::path($this->root.'/state', 'demo', 'a#5'), [
            'task' => 'a#1', 'pr' => 'a#5', 'head' => $head, 'findings' => $findings,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function counters(): array
    {
        return array_column($this->db->epicTasks('demo'), null, 'task')['a#1']['counters'];
    }

    private const array COLD = [
        ['id' => 'C1', 'source' => 'inline', 'path' => 'src/A.php', 'line' => 12, 'body' => 'null deref'],
        ['id' => 'C2', 'source' => 'comment', 'path' => null, 'line' => null, 'body' => 'rename it'],
    ];

    public function testReviewPromptCarriesColdFindingsFromSavedFile(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD);
        $this->signal('{"verdict":"request_changes","cold_findings":[{"id":"C1","verdict":"open"},{"id":"C2","verdict":"open"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertStringContainsString('[C1] inline (src/A.php:12):', $this->args());
        self::assertStringContainsString('rename it', $this->args());
        self::assertStringContainsString('"cold_findings"', $this->args());
    }

    public function testReviewPromptWithoutFindingsSaysSo(): void
    {
        $this->startEpic();
        $this->storeCold([]);
        $this->signal('{"verdict":"approve","cold_findings":[]}');
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertStringContainsString('findings: none. Report "cold_findings": [] in the signal.', $this->args());
    }

    public function testFullCoverageWritesCoverageHead(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'abc123');
        $this->signal('{"verdict":"approve","cold_findings":[{"id":"C1","verdict":"fixed","where":"src/A.php:14"},{"id":"C2","verdict":"refuted","where":"verifier"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertSame('abc123', $this->counters()['coverage_head'] ?? null);
        self::assertStringNotContainsString('Epic run demo failed', $this->allText());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function coverageFailures(): iterable
    {
        yield 'no verdict by id' => ['{"verdict":"request_changes","cold_findings":[{"id":"C1","verdict":"open"}]}'];
        yield 'approve with open' => ['{"verdict":"approve","cold_findings":[{"id":"C1","verdict":"open"},{"id":"C2","verdict":"fixed","where":"x:1"}]}'];
        yield 'no verdict' => ['{"cold_findings":[{"id":"C1","verdict":"fixed","where":"x:1"},{"id":"C2","verdict":"fixed","where":"x:2"}]}'];
    }

    #[DataProvider('coverageFailures')]
    public function testCoverageFailureFailsRunAndQueuesReviewOfSameHeadAgain(string $signal): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->db->epicSetTaskCounter('demo', 'a#1', 'reviewed_head', 'h1');
        $this->signal($signal);
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertStringContainsString('Epic run demo failed', $this->allText());
        $counters = $this->counters();
        self::assertArrayNotHasKey('coverage_head', $counters);
        self::assertSame('', $counters['reviewed_head']);
        self::assertSame('h1', $counters['cold_retry']);
        self::assertSame('h1:1', $counters['cold_attempts']);
        self::assertStringContainsString('findings coverage a#5', (string) file_get_contents($this->root.'/state/epics/demo/failures.log'));
        $statuses = array_column($this->db->epicTasks('demo'), 'status', 'task');
        self::assertNotSame('blocked', $statuses['a#1']);
    }

    public function testCoverageFailureReasonIsEnglishByDefault(): void
    {
        $this->buildBot(0, '', null, null, 'en');
        $this->runCoverageFailureWithoutVerdict();

        $text = $this->allText();
        self::assertStringContainsString('coverage of findings of a#5 is incomplete — the signal has no verdict', $text);
        self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $text);
    }

    public function testCoverageFailureReasonKeepsRussianInRuLocale(): void
    {
        // Deliberately Cyrillic: the subject is that a non-default locale reaches the reason; en is the built-in default
        $this->buildBot(0, '', null, null, 'ru');
        $this->runCoverageFailureWithoutVerdict();

        self::assertStringContainsString('покрытие находок a#5 неполное — в сигнале нет verdict', $this->allText());
    }

    private function runCoverageFailureWithoutVerdict(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->db->epicSetTaskCounter('demo', 'a#1', 'reviewed_head', 'h1');
        $this->signal('{"cold_findings":[{"id":"C1","verdict":"fixed","where":"x:1"},{"id":"C2","verdict":"fixed","where":"x:2"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');
    }

    public function testSecondCoverageFailureOnSameHeadBlocksTaskAndListsIds(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->signal('{"verdict":"request_changes","cold_findings":[{"id":"C1","verdict":"open"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');
        self::assertSame('h1:1', $this->counters()['cold_attempts']);

        $this->runEpic(Run::REVIEW, 'a#5');

        $statuses = array_column($this->db->epicTasks('demo'), 'status', 'task');
        self::assertSame('blocked', $statuses['a#1']);
        self::assertSame('h1:2', $this->counters()['cold_attempts']);
        self::assertStringContainsString('not covered: C2, C1', $this->allText());
        self::assertStringContainsString('(blocked)', $this->allText());
    }

    public function testRequestChangesWithOpenFindingsDoesNotWriteCoverageHead(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->signal('{"verdict":"request_changes","cold_findings":[{"id":"C1","verdict":"open"},{"id":"C2","verdict":"fixed","where":"x:1"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertArrayNotHasKey('coverage_head', $this->counters());
        self::assertStringNotContainsString('Epic run demo failed', $this->allText());
    }

    public function testResumeAfterCoverageBlockAllowsReviewOfSameHeadAgain(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->db->epicSetTaskCounter('demo', 'a#1', 'reviewed_head', 'h1');
        $this->signal('{"verdict":"request_changes","cold_findings":[{"id":"C1","verdict":"open"}]}');
        $this->runEpic(Run::REVIEW, 'a#5');
        $this->runEpic(Run::REVIEW, 'a#5');
        $statuses = array_column($this->db->epicTasks('demo'), 'status', 'task');
        self::assertSame('blocked', $statuses['a#1']);

        $this->db->epicResume('demo');

        $counters = $this->counters();
        self::assertSame('', $counters['cold_attempts']);
        self::assertSame('', $counters['reviewed_head']);
        self::assertSame('h1', $counters['cold_retry']);
    }

    public function testCoverageAttemptsStartOverOnNewHead(): void
    {
        $this->startEpic();
        $this->storeCold(self::COLD, 'h1');
        $this->signal('{"verdict":"request_changes","cold_findings":[]}');
        $this->runEpic(Run::REVIEW, 'a#5');
        $this->storeCold(self::COLD, 'h2');
        $this->runEpic(Run::REVIEW, 'a#5');

        self::assertSame('h2:1', $this->counters()['cold_attempts']);
        $statuses = array_column($this->db->epicTasks('demo'), 'status', 'task');
        self::assertNotSame('blocked', $statuses['a#1']);
    }

    public function testEpicRunIsIsolatedFromTopicSession(): void
    {
        $this->startEpic();
        $this->signal('{}');
        $this->db->setSessionId(self::CHAT, self::THREAD, 'author-session');

        $this->runEpic();

        self::assertStringNotContainsString('--resume', $this->args());
        self::assertSame('author-session', $this->db->getSessionId(self::CHAT, self::THREAD));
    }

    public function testChatRunStillRecordsSession(): void
    {
        $this->claudeOutput('{"type":"result","is_error":false,"result":"ok","session_id":"chat-sess","usage":{}}');
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 10, [['message_id' => 10, 'text' => 'hello', 'files' => []]]);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame('chat-sess', $this->db->getSessionId(self::CHAT, self::THREAD));
    }

    public function testEpicRunWithoutResultEventAppliesSignalWithoutFailure(): void
    {
        $this->startEpic();
        $this->signal('{"hold":["a#2"],"message":"signal applied"}');
        $this->claudeOutput('');

        $this->runEpic();

        $tasks = array_column($this->db->epicTasks('demo'), null, 'task');
        self::assertTrue($tasks['a#2']['hold']);
        self::assertStringContainsString('signal applied', $this->allText());
        self::assertStringNotContainsString('Epic run demo failed', $this->allText());
        self::assertStringNotContainsString('exited with code 0', $this->allText());
        self::assertFileDoesNotExist($this->root.'/state/epics/demo/failures.log');
        self::assertCount(1, glob($this->root.'/state/claude-no-result-*.log') ?: []);
    }

    public function testEpicRunWithoutResultEventAndSignalFails(): void
    {
        $this->startEpic();
        $this->claudeOutput('');

        $this->runEpic();

        self::assertStringContainsString('Epic run demo failed', $this->allText());
        self::assertStringContainsString('invalid signal', (string) file_get_contents($this->root.'/state/epics/demo/failures.log'));
    }

    public function testChatRunWithoutResultEventDeliversLastAssistantText(): void
    {
        $this->claudeOutput('{"type":"assistant","message":{"content":[{"type":"text","text":"recovered reply"}]}}');
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 10, [['message_id' => 10, 'text' => 'hello', 'files' => []]]);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringContainsString('recovered reply', $this->allText());
        self::assertStringContainsString('may be incomplete', $this->allText());
        self::assertCount(1, glob($this->root.'/state/claude-no-result-*.log') ?: []);
    }

    public function testChatRunWithoutResultEventAndAssistantIsError(): void
    {
        $this->claudeOutput('{"type":"system","subtype":"init","session_id":"s"}');
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 10, [['message_id' => 10, 'text' => 'hello', 'files' => []]]);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertStringContainsString('exited with code 0', $this->allText());
    }

    public function testSignalAddedHoldMessageBlockedAndDoneAreProcessed(): void
    {
        $this->startEpic();
        $this->signal('{"added":[{"task":"b#9","after":["a#2"]}],"hold":["a#2","b#9"],"message":"Fork: A or B?","blocked":"waiting for the author","done":true}');

        $this->runEpic();

        $tasks = array_column($this->db->epicTasks('demo'), null, 'task');
        self::assertArrayHasKey('b#9', $tasks);
        self::assertSame('added', $tasks['b#9']['source']);
        self::assertSame(['a#2'], $tasks['b#9']['after']);
        self::assertTrue($tasks['a#2']['hold']);
        self::assertTrue($tasks['b#9']['hold']);
        self::assertFalse($tasks['a#1']['hold']);
        self::assertStringContainsString('Fork: A or B?', $this->allText());
        self::assertStringContainsString('waiting for the author', $this->allText());
        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('waiting for the author', $epic['blocked_reason']);
        self::assertFalse($epic['done'], 'blocked wins over done');
        self::assertSame('blocked', $epic['status']);

        // Repeating the same signal is idempotent (the epic is in blocked — resume first).
        $this->db->epicResume('demo');
        $this->runEpic();
        self::assertCount(3, $this->db->epicTasks('demo'));
    }

    public function testServiceMessageIsSilentButSignalMessageIsNot(): void
    {
        $this->startEpic();
        $this->signal('{"message":"Fork: A or B?"}');

        $this->runEpic();

        $service = array_values(array_filter($this->tg->sent, static fn (array $m): bool => str_contains($m['text'], 'Epic run')));
        $message = array_values(array_filter($this->tg->sent, static fn (array $m): bool => str_contains($m['text'], 'Fork')));
        self::assertCount(1, $service);
        self::assertTrue($service[0]['silent']);
        self::assertCount(1, $message);
        self::assertFalse($message[0]['silent']);
    }

    public function testEmptyHoldClearsHold(): void
    {
        $this->startEpic();
        $this->signal('{"hold":["a#1"]}');
        $this->runEpic();
        $this->signal('{"hold":[]}');
        $this->runEpic();

        self::assertSame([false, false], array_column($this->db->epicTasks('demo'), 'hold'));
    }

    public function testStartWithoutGithubOwnersIsRefused(): void
    {
        $this->buildBot(0, '', new GithubOwners());
        $this->writePlan('demo');

        $this->command('/epic start demo');

        self::assertNull($this->db->epicGet('demo'));
        self::assertStringContainsString(GithubOwners::NOT_SET, $this->allText());
    }

    public function testSignalWithDefaultOwnerFormHoldsExistingTaskWithoutDuplicates(): void
    {
        $this->startEpic();
        $this->signal('{"added":[{"task":"anime-db/a#1"}],"hold":["anime-db/a#1"]}');

        $this->runEpic();

        $tasks = array_column($this->db->epicTasks('demo'), null, 'task');
        self::assertSame(['a#1', 'a#2'], array_keys($tasks));
        self::assertTrue($tasks['a#1']['hold']);
        self::assertFalse($tasks['a#2']['hold']);
    }

    public function testSignalWithTaskOfSecondOwnerIsStoredWithOwner(): void
    {
        $this->startEpic();
        $this->signal('{"added":[{"task":"gpslab/r#4"}]}');

        $this->runEpic();

        self::assertSame(['a#1', 'a#2', 'gpslab/r#4'], array_column($this->db->epicTasks('demo'), 'task'));
    }

    public function testSignalWithForeignOwnerFailsRunAndChangesNothing(): void
    {
        $this->startEpic();
        $this->signal('{"added":[{"task":"foreign/r#1"}],"hold":["a#1"]}');

        $this->runEpic();

        self::assertStringContainsString('Epic run demo failed', $this->allText());
        $tasks = array_column($this->db->epicTasks('demo'), null, 'task');
        self::assertSame(['a#1', 'a#2'], array_keys($tasks));
        self::assertFalse($tasks['a#1']['hold']);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function badSignals(): iterable
    {
        yield 'not JSON' => ['not json at all'];
        yield 'wrong type' => ['{"added":"x"}'];
        yield 'unknown task in hold' => ['{"hold":["zzz#1"]}'];
        yield 'no file' => [null];
        yield 'valid plus unknown task' => ['{"added":[{"task":"b#1"}],"hold":["a#1","zzz#1"],"message":"MUST_NOT_LEAK","blocked":"x","done":true}'];
    }

    #[DataProvider('badSignals')]
    public function testInvalidSignalFailsRunAndAppliesNothing(?string $signal): void
    {
        $this->startEpic();
        if ($signal !== null) {
            $this->signal($signal);
        }

        $this->runEpic();

        self::assertStringContainsString('Epic run demo failed', $this->allText());
        $tasks = array_column($this->db->epicTasks('demo'), null, 'task');
        self::assertCount(2, $tasks);
        self::assertArrayNotHasKey('b#1', $tasks);
        self::assertFalse($tasks['a#1']['hold']);
        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertNull($epic['blocked_reason']);
        self::assertFalse($epic['done']);
        self::assertStringNotContainsString('MUST_NOT_LEAK', $this->allText());
        self::assertSame([], $this->db->queueRunning());
    }

    public function testBlockedWithoutMessageIsDeliveredToAuthor(): void
    {
        $this->startEpic();
        $this->signal('{"blocked":"no access to repo X"}');

        $this->runEpic();

        self::assertStringContainsString('no access to repo X', $this->allText());
    }

    public function testBlockedSignalBlocksEpicWithExactlyOneStopMessage(): void
    {
        $this->startEpic();
        $this->signal('{"blocked":"no access to repo X"}');

        $this->runEpic();

        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('blocked', $epic['status']);
        $stops = array_filter($this->tg->textsTo(self::CHAT, self::THREAD), static fn (string $t): bool => str_contains($t, 'no access to repo X'));
        self::assertCount(1, $stops);
        self::assertStringContainsString('the epic is stopped, waiting: no access to repo X', implode("\n", $stops));
    }

    public function testDoneSignalDeliversReportImmediatelyAndFinishesEpic(): void
    {
        $this->startEpic();
        $this->signal('{"done":true}');
        file_put_contents($this->root.'/report.src', "<html>report</html>\n");

        $this->runEpic(Run::FINAL);

        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('done', $epic['status']);
        self::assertTrue($epic['done']);
        self::assertSame([['chat' => self::CHAT, 'thread' => self::THREAD, 'name' => 'report.md']], $this->tg->files);
    }

    public function testFailedEpicRunSavesDirtyTreeAsPatchAndRevertsWithoutButtons(): void
    {
        $this->startEpic();
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"s","usage":{}}');
        // The "run" dirties the tree: an edit to a tracked file, a new file and a binary file.
        file_put_contents($this->root.'/claude', "#!/bin/sh\n"
            ."cd {$this->work}\n"
            ."echo changed >> a.txt\n"
            ."echo fresh > new.txt\n"
            ."printf '\\000\\001\\002BIN' > bin.dat\n"
            ."cat {$this->root}/out.json\n");

        $this->runEpic();

        $patches = glob($this->root.'/state/epics/demo/dirty-*.patch');
        self::assertIsArray($patches);
        self::assertCount(1, $patches);
        $patch = (string) file_get_contents($patches[0]);
        self::assertStringContainsString('a.txt', $patch);
        self::assertStringContainsString('new.txt', $patch);
        self::assertStringContainsString('bin.dat', $patch);
        self::assertStringContainsString('GIT binary patch', $patch);
        $w = escapeshellarg($this->work);
        self::assertSame('', trim((string) shell_exec("git -C {$w} status --porcelain")));
        // The patch applies and restores all three files.
        $this->assertShOk("git -C {$w} apply --check ".escapeshellarg($patches[0]));
        $this->assertShOk("git -C {$w} apply ".escapeshellarg($patches[0]));
        self::assertSame("a\nchanged\n", file_get_contents($this->work.'/a.txt'));
        self::assertSame("fresh\n", file_get_contents($this->work.'/new.txt'));
        self::assertSame("\000\001\002BIN", file_get_contents($this->work.'/bin.dat'));
        self::assertNull($this->db->getDecision());
        self::assertSame(0, $this->db->getLimitPauseUntil());
        foreach ($this->tg->sent as $m) {
            self::assertNull($m['markup']);
        }
        self::assertSame([], $this->db->queueRunning());
    }

    #[DataProvider('badSignals')]
    public function testFailedEpicRunIsLoggedAndNotCommitted(?string $signal): void
    {
        $this->startEpic();
        if ($signal !== null) {
            $this->signal($signal);
        }
        $head = trim((string) shell_exec('git -C '.escapeshellarg($this->work).' rev-parse HEAD'));
        $this->dirtyingClaude();

        $this->runEpic();

        $log = (string) file_get_contents($this->root.'/state/epics/demo/failures.log');
        self::assertStringContainsString('invalid signal', $log);
        self::assertMatchesRegularExpression('/patch=dirty-[\d-]+\.patch/', $log);
        self::assertCount(1, glob($this->root.'/state/epics/demo/dirty-*.patch') ?: []);
        self::assertSame('', trim((string) shell_exec('git -C '.escapeshellarg($this->work).' status --porcelain')));
        self::assertSame($head, trim((string) shell_exec('git -C '.escapeshellarg($this->work).' rev-parse HEAD')));
        $epic = $this->db->epicGet('demo');
        self::assertNotNull($epic);
        self::assertSame('running', $epic['status']);
    }

    /** Replaces `claude`: dirties the tree, leaves the prepared signal and result. */
    private function dirtyingClaude(): void
    {
        file_put_contents($this->root.'/claude', "#!/bin/sh\n"
            ."cd {$this->work}\n"
            ."echo changed >> a.txt\n"
            ."[ -f {$this->root}/signal.src ] && mkdir -p \"\$(dirname \"\$WORKSPACE_ASSISTANT_EPIC_SIGNAL\")\" && cp {$this->root}/signal.src \"\$WORKSPACE_ASSISTANT_EPIC_SIGNAL\"\n"
            ."cat {$this->root}/out.json\n");
    }

    public function testFailedEpicRunKeepsTreeWhenPatchCannotBeBuilt(): void
    {
        $this->startEpic();
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"s","usage":{}}');
        // index.lock during the run: `git add -A` will fail.
        file_put_contents($this->root.'/claude', "#!/bin/sh\n"
            ."cd {$this->work}\n"
            ."echo changed >> a.txt\n"
            ."touch .git/index.lock\n"
            ."cat {$this->root}/out.json\n");

        $this->runEpic();

        self::assertSame("a\nchanged\n", file_get_contents($this->work.'/a.txt'));
        self::assertSame([], glob($this->root.'/state/epics/demo/dirty-*.patch') ?: []);
        $log = (string) file_get_contents($this->root.'/state/epics/demo/failures.log');
        self::assertStringContainsString('tree not rolled back', $log);
        self::assertStringContainsString('boom', $log);
    }

    public function testEpicRunKilledByTimeoutIsLoggedAndTreeReverted(): void
    {
        $this->startEpic();
        $this->dirtyingClaude();
        $this->buildBot(1);
        file_put_contents($this->root.'/claude', "#!/bin/sh\ncd {$this->work}\necho changed >> a.txt\nsleep 10\n");
        $this->runEpic();

        $log = (string) file_get_contents($this->root.'/state/epics/demo/failures.log');
        self::assertStringContainsString('process killed', $log);
        self::assertSame('', trim((string) shell_exec('git -C '.escapeshellarg($this->work).' status --porcelain')));
        self::assertNull($this->db->getDecision());
    }

    public function testRunnerTimeoutReasonReachesUserInBotLocale(): void
    {
        // Deliberately Cyrillic: the subject is that a non-default locale reaches the runner timeout reason
        $this->startEpic();
        $this->buildBot(1, locale: 'ru');
        file_put_contents($this->root.'/claude', "#!/bin/sh\nsleep 10\n");
        $this->runEpic();

        self::assertStringContainsString('Процесс убит: общий таймаут 1с.', $this->allText());
    }

    /** Runs everything the orchestrator has queued, so that the epic has no pending run. */
    private function drainQueue(): void
    {
        for ($i = 0; $i < 10 && $this->db->epicRunPending('demo'); ++$i) {
            $this->call('startQueueHead');
            $this->finishJob();
        }
        self::assertFalse($this->db->epicRunPending('demo'));
    }

    public function testOrchestratorBlockReasonReachesUserInBotLocale(): void
    {
        require_once __DIR__.'/OrchestratorTest.php';
        $this->startEpic();
        $gh = new FakeGitHub();
        // Deliberately Cyrillic: the subject is that a non-default locale reaches the orchestrator block reason
        $this->buildBot(0, '', null, $gh, 'ru');
        $orchestrator = (new \ReflectionProperty(Bot::class, 'orchestrator'))->getValue($this->bot);
        self::assertInstanceOf(\GpsLab\WorkspaceAssistant\Epic\Orchestrator::class, $orchestrator);

        $gh->states['a#1'] = new TaskState('a#1', ['openronin:in-progress']);
        $orchestrator->poll();
        $this->drainQueue();
        $orchestrator->rememberAfterRun('demo');
        $orchestrator->poll();
        $this->drainQueue();
        // The label has hung without activity for far longer than the stall budget.
        $epic = $this->db->epicGet('demo') ?? self::fail('no epic');
        $this->db->epicProgress('demo', (string) $epic['fingerprint'], (string) $epic['attention'], time() - 1000000);
        $orchestrator->poll();

        self::assertStringContainsString('⛔ эпик стоит, ждёт: openronin:in-progress висит без активности на GitHub дольше', $this->allText());
    }

    public function testEpicRunHittingClaudeLimitRequeuesAndRevertsTree(): void
    {
        $this->startEpic();
        $this->dirtyingClaude();
        $this->claudeOutput('{"type":"result","is_error":true,"result":"You\'ve hit your usage limit","session_id":"s","usage":{}}');

        $this->runEpic();

        self::assertGreaterThan(0, $this->db->getLimitPauseUntil());
        self::assertNotSame([], $this->db->queueWaiting());
        self::assertSame('', trim((string) shell_exec('git -C '.escapeshellarg($this->work).' status --porcelain')));
        self::assertNull($this->db->getDecision());
        self::assertStringContainsString('Claude limit', (string) file_get_contents($this->root.'/state/epics/demo/failures.log'));
    }

    public function testFailedPlainRunWithDirtyTreeStillOffersButtons(): void
    {
        $this->claudeOutput('{"type":"result","is_error":true,"result":"boom","session_id":"s","usage":{}}');
        file_put_contents($this->root.'/claude', "#!/bin/sh\necho x > {$this->work}/new.txt\ncat {$this->root}/out.json\n");
        $this->command('/mode pm');
        $this->tg->sent = [];
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 1, [['type' => 'text', 'text' => 'hi']]);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertNotNull($this->db->getDecision());
        self::assertFileExists($this->work.'/new.txt');
        $markups = array_filter($this->tg->sent, static fn (array $m): bool => $m['markup'] !== null);
        self::assertNotEmpty($markups);
    }

    public function testSuccessfulEpicRunWithDirtyTreeIsAutoCommitted(): void
    {
        $this->startEpic();
        $this->signal('{}');
        file_put_contents($this->root.'/claude', "#!/bin/sh\n"
            ."mkdir -p \"\$(dirname \"\$WORKSPACE_ASSISTANT_EPIC_SIGNAL\")\" && cp {$this->root}/signal.src \"\$WORKSPACE_ASSISTANT_EPIC_SIGNAL\"\n"
            ."echo journal > {$this->work}/j.txt\n"
            ."cat {$this->root}/out.json\n");
        $head = trim((string) shell_exec('git -C '.escapeshellarg($this->work).' rev-parse HEAD'));

        $this->runEpic();

        self::assertNotSame($head, trim((string) shell_exec('git -C '.escapeshellarg($this->work).' rev-parse HEAD')));
        self::assertSame('', trim((string) shell_exec('git -C '.escapeshellarg($this->work).' status --porcelain')));
        self::assertNull($this->db->getDecision());
    }

    public function testStaleSignalIsNotReappliedByRunWithoutSignal(): void
    {
        $this->startEpic();
        $this->signal('{"message":"STALE_SIGNAL_MESSAGE"}');
        $this->runEpic();
        unlink($this->root.'/signal.src');

        $this->runEpic();

        self::assertStringContainsString('Epic run demo failed', $this->allText());
        self::assertSame(1, substr_count($this->allText(), 'STALE_SIGNAL_MESSAGE'));
    }

    public function testReplyTextIsNotSentButOutboxIs(): void
    {
        $this->startEpic();
        $this->signal('{"done":true}');
        file_put_contents($this->root.'/report.src', "# Report\n");

        $this->runEpic(Run::FINAL);

        self::assertStringNotContainsString('service chatter', $this->allText());
        self::assertSame([['chat' => self::CHAT, 'thread' => self::THREAD, 'name' => 'report.md']], $this->tg->files);
    }

    public function testEpicItemIsNotMergedWithAuthorMessages(): void
    {
        $this->startEpic();
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, self::THREAD, 11, [['message_id' => 11, 'text' => 'question', 'files' => []]]);

        $kinds = array_column($this->db->queueWaiting(), 'kind');
        self::assertSame(['epic', 'chat'], $kinds);
    }

    public function testRunOfPausedEpicIsDroppedWithoutLaunch(): void
    {
        $this->startEpic();
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        $this->db->epicSetStatus('demo', 'paused');

        $this->call('startQueueHead');

        self::assertFalse($this->activeJob());
        self::assertSame([], $this->db->queueWaiting());
        self::assertSame('', $this->args());
    }

    public function testRestartRequeuesKilledEpicRunButDropsKilledChatRequest(): void
    {
        $this->startEpic();
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        (new \ReflectionMethod(Bot::class, 'enqueueMessage'))->invoke($this->bot, self::CHAT, 8, 12, [['message_id' => 12, 'text' => 'ordinary', 'files' => []]]);
        foreach ($this->db->queueWaiting() as $item) {
            $this->db->queueMarkRunning($item['id']);
        }

        $this->call('recoverQueueOnStart');

        $waiting = $this->db->queueWaiting();
        self::assertSame(['epic'], array_column($waiting, 'kind'));
        self::assertSame([], $this->db->queueRunning());
        self::assertStringContainsString('repeat the request', implode("\n", $this->tg->textsTo(self::CHAT, 8)));
        self::assertStringNotContainsString('restarted', $this->allText());
    }

    public function testReviewPrsListsOnlyReviewRuns(): void
    {
        $this->startEpic();
        self::assertSame([], $this->db->epicReviewPrs('demo'));
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        self::assertSame([], $this->db->epicReviewPrs('demo'));
        $this->queueScheduler()->enqueueEpicRun('demo', Run::REVIEW, 'a#1');
        self::assertSame(['a#1'], $this->db->epicReviewPrs('demo'));
        self::assertSame([], $this->db->epicReviewPrs('other'));
        // the review is already executing (the queue executor marks it running)
        foreach ($this->db->queueWaiting() as $item) {
            $this->db->queueMarkRunning($item['id']);
        }
        self::assertSame([], $this->db->queueWaiting());
        self::assertNotSame([], $this->db->queueRunning());
        self::assertSame(['a#1'], $this->db->epicReviewPrs('demo'));
    }

    public function testCancelRemovesEpicItemsAndPausesEpic(): void
    {
        $this->startEpic();
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        $this->queueScheduler()->enqueueEpicRun('demo', Run::REVIEW, 'a#1');

        $this->command('/cancel');

        self::assertSame([], $this->db->queueWaiting());
        self::assertSame('paused', $this->db->epicGet('demo')['status'] ?? null);
        self::assertStringContainsString('is paused', $this->allText());
    }

    public function testCancelKillsRunningEpicJobAndPausesEpic(): void
    {
        $this->startEpic();
        $this->queueScheduler()->enqueueEpicRun('demo', Run::TICK);
        $this->call('startQueueHead');
        self::assertTrue($this->activeJob());

        $this->command('/cancel');

        self::assertFalse($this->activeJob());
        self::assertSame([], $this->db->queueRunning());
        self::assertSame('paused', $this->db->epicGet('demo')['status'] ?? null);
    }

    private function closedEpicOrchestrator(): \GpsLab\WorkspaceAssistant\Epic\Orchestrator
    {
        require_once __DIR__.'/OrchestratorTest.php';
        $this->startEpic();
        $gh = new FakeGitHub();
        $gh->states['a#1'] = new TaskState('a#1', prState: TaskState::MERGED);
        $gh->states['a#2'] = new TaskState('a#2', prState: TaskState::MERGED);
        $gh->tags = ['a@1.0.0'];
        $this->buildBot(0, '', null, $gh);
        $orchestrator = (new \ReflectionProperty(Bot::class, 'orchestrator'))->getValue($this->bot);
        self::assertInstanceOf(\GpsLab\WorkspaceAssistant\Epic\Orchestrator::class, $orchestrator);

        return $orchestrator;
    }

    /** Polls until the epic's final check is queued and runs the queue until the epic has no pending run. */
    private function runQueuedFinal(\GpsLab\WorkspaceAssistant\Epic\Orchestrator $orchestrator): void
    {
        $orchestrator->poll();
        self::assertTrue($this->db->epicFinalPending('demo'), 'the final check was not queued');
        $this->drainQueue();
        $orchestrator->rememberAfterRun('demo');
    }

    /** `/epic resume` queues a tick: it runs (and does not finish the epic) before the next poll. */
    private function resumeTickRuns(\GpsLab\WorkspaceAssistant\Epic\Orchestrator $orchestrator): void
    {
        $this->signal('{}');
        $this->drainQueue();
        $orchestrator->rememberAfterRun('demo');
    }

    /** The poll after a lost final check queues it again once: `final_runs` stays, `final_retries` becomes 1, the topic is told. */
    private function assertFinalRetried(\GpsLab\WorkspaceAssistant\Epic\Orchestrator $orchestrator): void
    {
        $finals = $this->db->epicFinalCounters('demo')['final_runs'];
        self::assertFalse($this->db->epicFinalPending('demo'));

        $orchestrator->poll();

        self::assertTrue($this->db->epicFinalPending('demo'), 'the lost final check was not queued again');
        self::assertSame($finals, $this->db->epicFinalCounters('demo')['final_runs']);
        self::assertSame(1, $this->db->epicFinalRetries('demo'));
        self::assertSame(1, substr_count($this->allText(), 'The final check did not finish the epic (no done)'));
    }

    /** The retried final check is lost too: the next poll blocks the epic. */
    private function assertSecondLossBlocks(\GpsLab\WorkspaceAssistant\Epic\Orchestrator $orchestrator): void
    {
        $this->drainQueue();
        $orchestrator->rememberAfterRun('demo');

        $orchestrator->poll();

        $epic = $this->db->epicGet('demo') ?? self::fail('no epic');
        self::assertSame('blocked', $epic['status']);
        self::assertStringContainsString('the final check failed to finish the epic twice (no done)', (string) $epic['blocked_reason']);
        self::assertFalse($this->db->epicFinalPending('demo'));
    }

    public function testFinalWithoutDoneIsRetriedOnceThenBlocksEpic(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $this->signal('{}');
        $this->runQueuedFinal($orchestrator);
        self::assertSame(0, $this->db->epicFinalRetries('demo'));

        $this->assertFinalRetried($orchestrator);
        $this->assertSecondLossBlocks($orchestrator);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function failedFinalSignals(): iterable
    {
        yield 'no signal file' => [null];
        yield 'invalid json' => ['not json'];
    }

    #[DataProvider('failedFinalSignals')]
    public function testFailedFinalRunIsRetried(?string $signal): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        if ($signal !== null) {
            $this->signal($signal);
        }
        $this->runQueuedFinal($orchestrator);
        self::assertStringContainsString('Epic run demo failed', $this->allText());

        $this->assertFinalRetried($orchestrator);
    }

    public function testFinalDroppedByEpicPauseIsQueuedAfterResume(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $orchestrator->poll();
        self::assertTrue($this->db->epicFinalPending('demo'));

        $this->command('/epic pause');
        $this->call('startQueueHead');
        self::assertFalse($this->activeJob());
        self::assertFalse($this->db->epicFinalPending('demo'));
        self::assertSame(1, $this->db->epicFinalCounters('demo')['final_runs']);

        $this->command('/epic resume');
        $this->resumeTickRuns($orchestrator);

        $this->assertFinalRetried($orchestrator);
    }

    public function testFinalRemovedByCancelIsQueuedAfterResume(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $orchestrator->poll();
        self::assertTrue($this->db->epicFinalPending('demo'));

        $this->command('/cancel');
        self::assertFalse($this->db->epicFinalPending('demo'));
        self::assertSame('paused', $this->db->epicGet('demo')['status'] ?? null);

        $this->command('/epic resume');
        $this->resumeTickRuns($orchestrator);

        $this->assertFinalRetried($orchestrator);
    }

    public function testFinalWithAddedRefusedByTailsCeilingIsRetriedThenBlocks(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $pdo = (new \ReflectionProperty(Database::class, 'pdo'))->getValue($this->db);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec('UPDATE epics SET final_runs = 2, tails_rounds = '.Database::MAX_TAILS_ROUNDS." WHERE slug = 'demo'");
        $this->signal('{"added":[{"task":"b#9"}]}');
        $this->runQueuedFinal($orchestrator);
        self::assertStringContainsString('does not start', $this->allText());
        self::assertCount(2, $this->db->epicTasks('demo'));

        $this->assertFinalRetried($orchestrator);
        $this->assertSecondLossBlocks($orchestrator);
    }

    public function testFinalWithAddedOfKnownTasksOnlyIsRetried(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $this->signal('{"added":[{"task":"a#1"},{"task":"a#2","after":["a#1"]}]}');
        $this->runQueuedFinal($orchestrator);
        self::assertSame(0, $this->db->epicFinalCounters('demo')['tails_rounds']);
        self::assertCount(2, $this->db->epicTasks('demo'));

        $this->assertFinalRetried($orchestrator);
    }

    public function testFinalRequeuedByRestartIsNotQueuedAgain(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $orchestrator->poll();
        foreach ($this->db->queueWaiting() as $item) {
            $this->db->queueMarkRunning($item['id']);
        }
        $this->call('recoverQueueOnStart');
        self::assertTrue($this->db->epicFinalPending('demo'));
        $queued = count($this->db->queueWaiting());

        $orchestrator->poll();

        self::assertCount($queued, $this->db->queueWaiting());
        self::assertSame(1, $this->db->epicFinalCounters('demo')['final_runs']);
        self::assertSame(0, $this->db->epicFinalRetries('demo'));
    }

    public function testFinalRequeuedByClaudeLimitIsNotQueuedAgain(): void
    {
        $orchestrator = $this->closedEpicOrchestrator();
        $this->claudeOutput('{"type":"result","is_error":true,"result":"You\'ve hit your usage limit","session_id":"s","usage":{}}');
        $orchestrator->poll();
        $this->call('startQueueHead');
        $this->finishJob();
        self::assertGreaterThan(0, $this->db->getLimitPauseUntil());
        self::assertTrue($this->db->epicFinalPending('demo'));
        $queued = count($this->db->queueWaiting());

        $orchestrator->poll();

        self::assertCount($queued, $this->db->queueWaiting());
        self::assertSame(1, $this->db->epicFinalCounters('demo')['final_runs']);
        self::assertSame(0, $this->db->epicFinalRetries('demo'));
    }
}
