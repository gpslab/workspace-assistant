<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\ModelChoice;
use GpsLab\WorkspaceAssistant\ModelSwitcher;
use GpsLab\WorkspaceAssistant\Queue\QueueScheduler;
use GpsLab\WorkspaceAssistant\RunState;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `/model` feature end to end: a real git checkout, a fake `claude` that records its argv and
 * prints a system/init event, and {@see FakeTelegram}.
 */
#[CoversClass(ModelChoice::class)]
#[CoversClass(ModelSwitcher::class)]
final class ModelTest extends TestCase
{
    private const int USER = 555;

    private string $root;
    private string $work;
    private Database $db;
    private FakeTelegram $tg;
    private Bot $bot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tgm_'.uniqid('', true);
        mkdir($this->root);
        $this->work = $this->root.'/work';
        $origin = $this->root.'/origin.git';
        $this->sh('git init -q --bare '.escapeshellarg($origin));
        $this->sh('git clone -q '.escapeshellarg($origin).' '.escapeshellarg($this->work).' 2>/dev/null');
        foreach (['config user.email t@t.t', 'config user.name test', 'checkout -q -b main'] as $cmd) {
            $this->sh('git -C '.escapeshellarg($this->work).' '.$cmd);
        }
        file_put_contents($this->work.'/a.txt', "a\n");
        $this->sh('git -C '.escapeshellarg($this->work).' add -A && git -C '.escapeshellarg($this->work).' commit -q -m init && git -C '.escapeshellarg($this->work).' push -q -u origin main');

        mkdir($this->root.'/state');
        mkdir($this->root.'/outbox');
        mkdir($this->root.'/inbox');
        file_put_contents($this->root.'/claude', "#!/bin/sh\nd=\"$(dirname \"\$0\")\"\nn=\$(ls \"\$d\"/argv.* 2>/dev/null | wc -l)\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done > \"\$d/argv.\$n\"\ncat \"\$d/out.json\"\n");
        chmod($this->root.'/claude', 0755);
        file_put_contents($this->root.'/out.json', '{"type":"system","subtype":"init","session_id":"s-1","model":"claude-sonnet-actual"}'."\n"
            .'{"type":"result","is_error":false,"result":"done","session_id":"s-1","usage":{}}'."\n");

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

    /**
     * @param list<string>      $extraArgs
     * @param list<string>|null $models
     */
    private function makeBot(array $extraArgs = [], ?array $models = null): Bot
    {
        $config = new Config(...[
            'botToken' => 't',
            'allowedUserIds' => [self::USER],
            'repoPath' => $this->work,
            'forgeRepo' => '',
            'claudeBin' => $this->root.'/claude',
            'claudeTimeout' => 0,
            'claudeStallTimeout' => 900,
            'claudeExtraArgs' => $extraArgs,
            'claudeAppendSystemPrompt' => '',
            'stateDir' => $this->root.'/state',
            'outboxDir' => $this->root.'/outbox',
            'inboxDir' => $this->root.'/inbox',
            'pollTimeout' => 30,
            'gitTimeout' => 30,
            'forgeOwners' => new ForgeOwners(['gpslab']),
        ] + ($models === null ? [] : ['claudeModels' => $models]));

        return new Bot($config, $this->db, $this->tg, new GitManager($this->work, 30), new SessionFiles($this->root));
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod(Bot::class, $method))->invokeArgs($this->bot, $args);
    }

    private function say(int $thread, string $text, int $messageId): void
    {
        $this->call('enqueueMessage', [100, $thread, $messageId, [['message_id' => $messageId, 'text' => $text, 'files' => []]]]);
    }

    private function command(int $thread, string $text, int $messageId = 0): void
    {
        $this->call('handleCommand', [100, $thread, $text, $messageId, self::USER]);
    }

    private function tap(string $data, int $chat = 100): void
    {
        $this->call('handleCallback', [[
            'id' => 'cb',
            'from' => ['id' => self::USER],
            'data' => $data,
            'message' => ['chat' => ['id' => $chat], 'message_id' => 777],
        ]]);
    }

    private function runState(): RunState
    {
        return (new \ReflectionProperty(Bot::class, 'runState'))->getValue($this->bot);
    }

    private function finishJob(): void
    {
        for ($i = 0; $i < 200 && $this->runState()->activeJob !== null; ++$i) {
            $this->call('pollJob');
            usleep(50000);
        }
        self::assertNull($this->runState()->activeJob, 'the job did not finish');
    }

    private function runChat(int $thread, string $text, int $messageId): void
    {
        $this->say($thread, $text, $messageId);
        $this->call('startQueueHead');
        $this->finishJob();
    }

    /** @return list<string> */
    private function argv(int $launch): array
    {
        return explode("\0", (string) file_get_contents($this->root.'/argv.'.$launch));
    }

    private function modelOf(int $launch): ?string
    {
        $argv = $this->argv($launch);
        $found = [];
        foreach ($argv as $i => $arg) {
            if ($arg === '--model') {
                $found[] = $argv[$i + 1];
            } elseif (str_starts_with($arg, '--model=')) {
                $found[] = substr($arg, strlen('--model='));
            }
        }
        self::assertLessThanOrEqual(1, count($found), '--model is passed at most once');

        return $found[0] ?? null;
    }

    private function lastText(): string
    {
        $texts = $this->tg->textsTo(100, 1);

        return $texts === [] ? '' : $texts[count($texts) - 1];
    }

    private function busyTopic(int $thread): void
    {
        $state = $this->runState();
        $state->activeJob = new \GpsLab\WorkspaceAssistant\ClaudeRunner('claude', sys_get_temp_dir(), 0, []);
        $state->jobContext = [
            'kind' => 'chat', 'chat_id' => 100, 'thread_id' => $thread, 'thinking_id' => 0, 'prompt' => '', 'last_progress_at' => 0,
            'shown_count' => 0, 'compact_old_session_id' => null, 'session_saved' => false, 'mode' => 'pm', 'locale' => 'en',
            'queue_id' => 0, 'epic_slug' => null, 'epic_run' => null, 'pm_gate_run_id' => null, 'prompt_hash' => null, 'inbox_files' => [],
        ];
    }

    public function testNoModelFlagWhenNothingIsSet(): void
    {
        $this->runChat(1, 'hi', 10);

        self::assertNull($this->modelOf(0));
    }

    public function testTopicModelIsPassed(): void
    {
        $this->db->setTopicModel(100, 1, 'haiku');
        $this->runChat(1, 'hi', 10);

        self::assertSame('haiku', $this->modelOf(0));
    }

    public function testModelForAllTopicsIsPassedAndTopicModelWins(): void
    {
        $this->db->setDefaultModel('sonnet');
        $this->runChat(1, 'hi', 10);
        $this->db->setTopicModel(100, 2, 'opus[1m]');
        $this->runChat(2, 'hi', 11);

        self::assertSame('sonnet', $this->modelOf(0));
        self::assertSame('opus[1m]', $this->modelOf(1));
    }

    public function testTopicModelDoesNotLeakIntoOtherTopics(): void
    {
        $this->db->setTopicModel(100, 1, 'haiku');
        $this->runChat(2, 'hi', 10);

        self::assertNull($this->modelOf(0));
    }

    public function testNewKeepsTheTopicModel(): void
    {
        $this->db->setTopicModel(100, 1, 'haiku');
        $this->db->recordRun(100, 1, 's-old', null);
        $this->command(1, '/new');

        self::assertNull($this->db->getSessionId(100, 1));
        self::assertSame('haiku', $this->db->getTopicModel(100, 1));
    }

    public function testResetSessionKeepsModelAndLastModel(): void
    {
        $this->db->setTopicModel(100, 1, 'haiku');
        $this->db->setLastModel(100, 1, 'claude-haiku-x');
        $this->db->resetSession(100, 1);

        self::assertSame('haiku', $this->db->getTopicModel(100, 1));
        self::assertSame('claude-haiku-x', $this->db->getLastModel(100, 1));
    }

    public function testCompactSummarizeUsesPinnedModelAndReseedResolvesAgain(): void
    {
        $this->db->recordRun(100, 1, 's-old', null);
        $this->db->setTopicModel(100, 1, 'opus');
        $this->command(1, '/compact', 30);
        self::assertSame('opus', $this->db->queueHead()['model'] ?? null);
        // The topic model changes while the summarize item waits: the item keeps what it pinned.
        $this->db->setTopicModel(100, 1, 'sonnet');
        $this->call('startQueueHead');
        $this->finishJob(); // summarize
        $this->finishJob(); // reseed, started by the summarize completion

        self::assertSame('opus', $this->modelOf(0));
        self::assertSame('sonnet', $this->modelOf(1));
        self::assertSame('sonnet', $this->db->getTopicModel(100, 1), '/compact keeps the topic model');
    }

    public function testQueuedItemKeepsItsPinnedModel(): void
    {
        $this->db->setDefaultModel('sonnet');
        $this->say(9, 'busy', 1);
        $this->call('startQueueHead');
        $this->say(1, 'first', 10);
        $this->db->setDefaultModel('haiku');

        self::assertSame('sonnet', $this->db->queueLastWaiting(100, 1)['model'] ?? null);
    }

    public function testChangeForAllTopicsStopsGluingOntoAnItemPinnedToAnotherModel(): void
    {
        $this->say(9, 'busy', 1);
        $this->call('startQueueHead');
        $this->say(1, 'first', 10);
        $this->command(1, '/model sonnet all');
        $this->say(1, 'second', 11);

        $waiting = $this->db->queueWaiting();
        self::assertCount(2, $waiting);
        self::assertNull($waiting[0]['model']);
        self::assertSame('sonnet', $waiting[1]['model']);
    }

    public function testMessagesWithTheSameModelStillGlue(): void
    {
        $this->db->setDefaultModel('sonnet');
        $this->say(9, 'busy', 1);
        $this->call('startQueueHead');
        $this->say(1, 'first', 10);
        $this->say(1, 'second', 11);

        self::assertCount(1, $this->db->queueWaiting());
    }

    public function testTopicChangeClosesTheGluingGroup(): void
    {
        $this->say(9, 'busy', 1);
        $this->call('startQueueHead');
        $this->say(1, 'first', 10);
        $this->command(1, '/model default');
        $this->say(1, 'second', 11);

        self::assertCount(2, $this->db->queueWaiting());
    }

    public function testTextFormsSetTheLevels(): void
    {
        $this->command(1, '/model sonnet');
        self::assertSame('sonnet', $this->db->getTopicModel(100, 1));
        self::assertNull($this->db->getDefaultModel());

        $this->command(1, '/model haiku all');
        self::assertSame('haiku', $this->db->getDefaultModel());
        self::assertSame('sonnet', $this->db->getTopicModel(100, 1));

        $this->command(1, '/model default');
        self::assertNull($this->db->getTopicModel(100, 1));
        self::assertSame('haiku', $this->db->getDefaultModel());

        $this->command(1, '/model default all');
        self::assertNull($this->db->getDefaultModel());
    }

    public function testUnknownModelByTextListsTheAllowedOnesAndWritesNothing(): void
    {
        $this->command(1, '/model nosuchmodel');

        self::assertSame("Unknown model: nosuchmodel\nAvailable: fable, opus[1m], opus, sonnet, haiku", $this->lastText());
        self::assertNull($this->db->getTopicModel(100, 1));

        $this->command(1, '/model nosuchmodel all');
        self::assertNull($this->db->getDefaultModel());
    }

    public function testChangeWarningOnlyForANonEmptySessionAndNeverSuggestsCompact(): void
    {
        $this->command(1, '/model sonnet');
        self::assertSame('Model of this topic: sonnet.', $this->lastText());

        $this->db->recordRun(100, 1, 's-1', null);
        $this->command(1, '/model haiku');

        self::assertStringContainsString('drops the prompt cache', $this->lastText());
        self::assertStringContainsString('smaller context window', $this->lastText());
        self::assertStringContainsString('/new', $this->lastText());
        self::assertStringNotContainsString('/compact', $this->lastText());
    }

    public function testRunningJobRefusesTheTopicLevelButNotTheLevelForAllTopics(): void
    {
        $this->busyTopic(1);

        $this->command(1, '/model sonnet');
        self::assertStringContainsString('Work is in progress', $this->lastText());
        $this->command(1, '/model default');
        self::assertNull($this->db->getTopicModel(100, 1));
        $this->db->setTopicModel(100, 1, 'opus');
        $this->command(1, '/model default');
        self::assertSame('opus', $this->db->getTopicModel(100, 1), 'default is gated too');

        $this->command(1, '/model haiku all');
        self::assertSame('haiku', $this->db->getDefaultModel());

        $this->tap('model:topic:1:sonnet');
        self::assertSame('opus', $this->db->getTopicModel(100, 1));
        self::assertStringContainsString('Work is in progress', (string) end($this->tg->answers));
    }

    public function testStoredValueOutsideTheListIsSkippedAtEnqueueAndFallsToTheNextLevel(): void
    {
        $this->db->setTopicModel(100, 1, 'gone');
        $this->db->setDefaultModel('sonnet');
        $this->runChat(1, 'hi', 10);

        self::assertSame('sonnet', $this->modelOf(0));
    }

    public function testStoredTopicValueOutsideTheListGivesNoModelWhenNothingElseIsSet(): void
    {
        $this->db->setTopicModel(100, 1, 'gone');
        $this->runChat(1, 'hi', 10);

        self::assertNull($this->modelOf(0));
    }

    public function testPinnedValueOutsideTheListIsDroppedAtRunStartWithoutFallback(): void
    {
        $this->db->setDefaultModel('sonnet');
        $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [['message_id' => 10, 'text' => 'hi', 'files' => []]], 0, 'en', 'removed-from-list');
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertNull($this->modelOf(0));
    }

    public function testExtraArgsModelPinsTheInstance(): void
    {
        foreach ([['--model=haiku'], ['--model', 'haiku']] as $n => $args) {
            $this->bot = $this->makeBot($args);
            $this->db->setTopicModel(100, 1, 'opus');
            $this->runChat(1, 'hi', 10 + $n);

            self::assertSame('haiku', $this->modelOf($n), 'only the operator model reaches argv');
            self::assertNull($this->db->queueGet(1 + $n), 'the item is gone after the run');
        }

        $this->db->setTopicModel(100, 1, null);
        $this->command(1, '/model sonnet');
        self::assertStringContainsString('pinned by --model in CLAUDE_EXTRA_ARGS (haiku)', $this->lastText());
        self::assertNull($this->db->getTopicModel(100, 1));

        $this->tap('model:topic:1:sonnet');
        self::assertNull($this->db->getTopicModel(100, 1));
        self::assertStringContainsString('pinned by --model', (string) end($this->tg->answers));
    }

    public function testStatsShowsTheModelLineWithAndWithoutASession(): void
    {
        $this->command(1, '/stats');
        self::assertStringContainsString("Mode: pm\nModel: Claude Code default\n", $this->lastText());

        $this->db->setDefaultModel('sonnet');
        $this->command(1, '/stats');
        self::assertStringContainsString("Model: sonnet (all topics)\n", $this->lastText());

        $this->db->setTopicModel(100, 1, 'haiku');
        $this->runChat(1, 'hi', 10);
        $this->command(1, '/stats');
        self::assertStringContainsString("Model: haiku (this topic)\nLast run model: claude-sonnet-actual\n", $this->lastText());
        self::assertSame('claude-sonnet-actual', $this->db->getLastModel(100, 1));
    }

    public function testStatsMarksAStoredValueOutsideTheListAsNotApplied(): void
    {
        $this->db->setTopicModel(100, 1, 'gone');
        $this->command(1, '/stats');

        self::assertStringContainsString('Model: Claude Code default [gone: not in the list, not applied]', $this->lastText());
    }

    public function testQueueShowsTheModelOfAnItemWhenSet(): void
    {
        $this->say(9, 'busy', 1);
        $this->call('startQueueHead');
        $this->say(1, 'plain', 10);
        $this->db->setTopicModel(100, 2, 'haiku');
        $this->say(2, 'cheap', 11);
        $this->command(1, '/queue');

        self::assertMatchesRegularExpression('/\[pm\]\n?.*\[pm, haiku\]/s', $this->lastText());
    }

    public function testModelCommandWithoutAnArgumentShowsStateAndKeyboard(): void
    {
        $this->db->setDefaultModel('sonnet');
        $this->command(1, '/model');

        $sent = end($this->tg->sent);
        self::assertIsArray($sent);
        self::assertSame("Model: sonnet (all topics)\nThis topic: not set\nAll topics: sonnet", $sent['text']);
        self::assertIsArray($sent['markup']);
        $buttons = array_merge(...$sent['markup']['inline_keyboard']);
        self::assertSame(
            ['fable', 'opus[1m]', 'opus', 'sonnet ✓', 'haiku', 'default'],
            array_column($buttons, 'text'),
        );
        self::assertSame('model:pick:1:opus[1m]', $buttons[1]['callback_data']);
    }

    public function testCallbackStepsAskForTheLevelThenWrite(): void
    {
        $this->tap('model:pick:1:haiku');
        self::assertNull($this->db->getTopicModel(100, 1));
        $edit = end($this->tg->edits);
        self::assertIsArray($edit);
        self::assertIsArray($edit['markup']);
        self::assertSame(['model:topic:1:haiku', 'model:all:1:haiku'], array_column($edit['markup']['inline_keyboard'][0], 'callback_data'));

        $this->tap('model:all:1:haiku');
        self::assertSame('haiku', $this->db->getDefaultModel());
        self::assertNull($this->db->getTopicModel(100, 1));

        $this->tap('model:topic:1:default');
        $this->tap('model:topic:2:opus');
        self::assertSame('opus', $this->db->getTopicModel(100, 2));
        self::assertNull($this->db->getTopicModel(100, 1));
    }

    public function testCallbackValueWithAColonIsParsedAsOne(): void
    {
        $this->bot = $this->makeBot([], ['sonnet', 'vendor:model-1']);
        $this->tap('model:topic:3:vendor:model-1');

        self::assertSame('vendor:model-1', $this->db->getTopicModel(100, 3));
        self::assertSame(['step' => 'topic', 'thread_id' => 3, 'value' => 'vendor:model-1'], ModelSwitcher::parseCallback('model:topic:3:vendor:model-1'));
    }

    public function testCallbackWithAValueOutsideTheListGivesAnErrorToastAndWritesNothing(): void
    {
        $this->tap('model:topic:1:gone');
        $this->tap('model:pick:1:gone');
        $this->tap('model:all:1:gone');
        $this->tap('model:topic:x:sonnet');

        self::assertNull($this->db->getTopicModel(100, 1));
        self::assertNull($this->db->getDefaultModel());
        self::assertSame(['Invalid button data.', 'Invalid button data.', 'Invalid button data.', 'Invalid button data.'], $this->tg->answers);
        self::assertSame([], $this->tg->edits);
    }

    public function testEpicRunUsesOnlyTheModelForAllTopicsAndLeavesLastModelAlone(): void
    {
        $this->db->epicCreate('demo', 100, 1, [['task' => 'gpslab/ws#4', 'after' => []]], []);
        ColdFindings::save(ColdFindings::path($this->root.'/state', 'demo', 'gpslab/ws#5'), [
            'task' => 'gpslab/ws#4', 'pr' => 'gpslab/ws#5', 'head' => str_repeat('a', 40), 'findings' => [],
        ]);
        $this->db->setTopicModel(100, 1, 'haiku');
        $this->db->setDefaultModel('opus');
        $scheduler = (new \ReflectionProperty(Bot::class, 'queueScheduler'))->getValue($this->bot);
        self::assertInstanceOf(QueueScheduler::class, $scheduler);

        self::assertTrue($scheduler->enqueueEpicRun('demo', Run::REVIEW, 'gpslab/ws#5'));
        self::assertSame('opus', $this->db->queueHead()['model'] ?? null);
        $this->call('startQueueHead');
        $this->finishJob();

        self::assertSame('opus', $this->modelOf(0));
        self::assertNull($this->db->getLastModel(100, 1), 'an epic run does not touch the topic session state');
    }

    public function testRunnerNeverAddsItsOwnModelNextToOneFromTheExtraArguments(): void
    {
        $runner = new \GpsLab\WorkspaceAssistant\ClaudeRunner($this->root.'/claude', $this->work, 0, ['--model=haiku'], model: 'sonnet');
        $runner->start('hi', null);
        for ($i = 0; $i < 200 && $runner->poll() === \GpsLab\WorkspaceAssistant\RunnerState::Running; ++$i) {
            usleep(50000);
        }

        self::assertSame(1, count(array_filter($this->argv(0), static fn (string $a): bool => str_starts_with($a, '--model'))));
        self::assertNotContains('sonnet', $this->argv(0));
        self::assertSame('claude-sonnet-actual', $runner->actualModel());
    }

    public function testChangeForAllTopicsWarnsOnlyWhereItReachesTheSession(): void
    {
        $this->db->recordRun(100, 1, 's-1', null);
        $this->db->setTopicModel(100, 1, 'opus');
        $this->command(1, '/model haiku all');
        self::assertSame('Model for all topics: haiku.', $this->lastText());

        $this->db->setTopicModel(100, 1, null);
        $this->command(1, '/model sonnet all');
        self::assertStringContainsString('drops the prompt cache', $this->lastText());
    }
}
