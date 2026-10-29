<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The /epic keyboard: buttons by the state of the topic's epic, the stateless callback branch,
 * and the three-step start (candidate list, confirmation, start).
 */
#[CoversClass(Bot::class)]
final class KeyboardTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/tgk_'.uniqid('', true);
        $this->work = $this->root.'/work';
        mkdir($this->work.'/context/epics', 0775, true);
        mkdir($this->root.'/state');
        exec('git -C '.escapeshellarg($this->work).' init -q -b main 2>&1; git -C '.escapeshellarg($this->work).' remote add origin git@github.com:anime-db/ws.git 2>&1');

        $this->db = new Database($this->root.'/state');
        $this->tg = new FakeTelegram();
        $config = new Config(
            botToken: 't',
            allowedUserIds: [555],
            repoPath: $this->work,
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->root.'/state',
            outboxDir: $this->root.'/state',
            inboxDir: $this->root.'/state',
            pollTimeout: 30,
            gitTimeout: 30,
            epicsDir: 'context/epics',
            forgeOwners: new ForgeOwners(['anime-db', 'gpslab']),
            botLocale: 'en',
        );
        $this->bot = new Bot($config, $this->db, $this->tg, new GitManager($this->work, 30), new SessionFiles($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function writePlan(string $slug, string $json = self::PLAN, ?int $mtime = null): void
    {
        $file = $this->work.'/context/epics/'.$slug.'.md';
        file_put_contents($file, "# Plan\n\n```json\n{$json}\n```\n");
        if ($mtime !== null) {
            touch($file, $mtime);
        }
    }

    private function command(string $text, int $thread = self::THREAD): void
    {
        (new \ReflectionMethod(Bot::class, 'handleCommand'))->invoke($this->bot, self::CHAT, $thread, $text, 0);
    }

    private function press(string $data, int $messageId = 77, string $text = 'shown'): void
    {
        (new \ReflectionMethod(Bot::class, 'handleCallback'))->invoke($this->bot, [
            'id' => 'cb',
            'from' => ['id' => 555],
            'message' => ['chat' => ['id' => self::CHAT], 'message_id' => $messageId, 'text' => $text],
            'data' => $data,
        ]);
    }

    /** Starts the epic through the text command and puts it into the given state. */
    private function epicIn(string $slug, string $status = 'running', bool $blockedTask = false, int $thread = self::THREAD): void
    {
        $this->writePlan($slug);
        $this->command('/epic start '.$slug, $thread);
        $this->db->epicSetStatus($slug, $status);
        if ($blockedTask) {
            $this->db->epicSetTaskStatus($slug, 'a#1', 'blocked');
        }
        $this->tg->sent = [];
    }

    /** @return list<string> */
    private function labels(): array
    {
        $markup = $this->tg->sent[count($this->tg->sent) - 1]['markup'] ?? null;

        return $markup === null ? [] : array_column($markup['inline_keyboard'][0], 'text');
    }

    /** @return array<string, string> text => callback_data */
    private function buttons(): array
    {
        $markup = $this->tg->sent[count($this->tg->sent) - 1]['markup'] ?? null;

        return $markup === null ? [] : array_column($markup['inline_keyboard'][0], 'callback_data', 'text');
    }

    /**
     * @param array{markup: array<string, mixed>|null} $edit
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private function rows(array $edit): array
    {
        /** @var list<list<array{text: string, callback_data: string}>> $rows */
        $rows = $edit['markup']['inline_keyboard'] ?? [];

        return $rows;
    }

    private function lastText(): string
    {
        return $this->tg->sent[count($this->tg->sent) - 1]['text'];
    }

    /** @return array<string, array{string, bool, bool, list<string>}> */
    public static function stateProvider(): array
    {
        return [
            'running, no blocked tasks' => ['running', false, false, ['⏸ pause', '📊 status']],
            'running with blocked tasks' => ['running', true, false, ['⏸ pause', '▶️ resume', '📊 status']],
            'blocked' => ['blocked', false, false, ['⏸ pause', '▶️ resume', '📊 status']],
            'paused' => ['paused', false, false, ['▶️ resume', '📊 status']],
            'done, with candidates' => ['done', false, true, ['🚀 start', '📊 status']],
            'done, no candidates' => ['done', false, false, ['📊 status']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stateProvider')]
    public function testButtonsFollowTheEpicState(string $status, bool $blockedTask, bool $candidate, array $expected): void
    {
        $this->epicIn('cur', $status, $blockedTask);
        if ($candidate) {
            $this->writePlan('next');
        }
        $this->command('/epic');

        self::assertSame($expected, $this->labels());
        $data = [
            '⏸ pause' => 'epic:pause:7:cur',
            '▶️ resume' => 'epic:resume:7:cur',
            '📊 status' => 'epic:status:7:cur',
            '🚀 start' => 'epic:list:7',
        ];
        self::assertSame(array_combine($expected, array_map(static fn (string $l): string => $data[$l], $expected)), $this->buttons());
    }

    public function testPressingTheSentButtonsRunsTheMatchingAction(): void
    {
        $this->epicIn('cur', 'running');
        $this->command('/epic');
        $buttons = $this->buttons();

        $this->press($buttons['⏸ pause']);

        self::assertSame('paused', $this->db->epicGet('cur')['status'] ?? null);
    }

    public function testNoEpicStartButtonLeadsToCandidates(): void
    {
        $this->writePlan('next');
        $this->command('/epic');

        $this->press($this->buttons()['🚀 start']);

        self::assertSame('epic:pick:7:next', $this->rows($this->tg->edits[0])[0][0]['callback_data']);
    }

    /** @return array<string, array{string}> */
    public static function malformedProvider(): array
    {
        return [
            'traversal in pick' => ['epic:pick:7:../secret'],
            'hidden file in go' => ['epic:go:7:.hidden'],
            'missing slug' => ['epic:pause:7'],
            'extra part' => ['epic:list:7:extra'],
            'non-numeric thread' => ['epic:status:x:cur'],
            'unknown action' => ['epic:boom:7'],
            'negative thread' => ['epic:go:-1:demo'],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedCallbackDataIsRejected(string $data): void
    {
        $this->writePlan('demo');
        $this->press($data);

        self::assertSame(['The keyboard is outdated.'], $this->tg->answers);
        self::assertSame([], $this->tg->sent);
        self::assertSame([], $this->tg->edits);
        self::assertNull($this->db->epicByThread(self::CHAT, self::THREAD));
        self::assertNull($this->db->epicGet('demo'));
    }

    #[DataProvider('pickerActionProvider')]
    public function testPickerKeyboardDoesNothingWhileTheTopicHasAnActiveEpic(string $data): void
    {
        $this->epicIn('cur');
        $this->writePlan('next');

        $this->press($data);

        self::assertSame(['The keyboard is outdated.'], $this->tg->answers);
        self::assertSame([], $this->tg->edits);
        self::assertNull($this->db->epicGet('next'));
    }

    /** @return array<string, array{string}> */
    public static function pickerActionProvider(): array
    {
        return ['list' => ['epic:list:7'], 'pick' => ['epic:pick:7:next']];
    }

    public function testNumericSlugIsListedAsString(): void
    {
        $this->writePlan('2026');

        $this->press('epic:list:'.self::THREAD);

        self::assertSame('2026', $this->rows($this->tg->edits[0])[0][0]['text']);
    }

    public function testNoEpicWithCandidatesOffersStart(): void
    {
        $this->writePlan('next');
        $this->command('/epic');

        self::assertSame(['🚀 start'], $this->labels());
    }

    public function testNoEpicAndNoCandidatesGivesTextAndUsage(): void
    {
        $this->command('/epic');

        self::assertNull($this->tg->sent[0]['markup']);
        self::assertStringContainsString('Nothing to do', $this->tg->sent[0]['text']);
        self::assertStringContainsString('Usage: /epic start', $this->tg->sent[0]['text']);
    }

    public function testStaleKeyboardOfAnotherEpicDoesNothing(): void
    {
        $this->epicIn('old');
        $this->db->epicSetStatus('old', 'done');
        $this->epicIn('new');

        $this->press('epic:pause:'.self::THREAD.':old');

        self::assertSame('The keyboard is outdated.', end($this->tg->answers));
        self::assertSame([], $this->tg->sent);
        self::assertSame('done', $this->db->epicGet('old')['status'] ?? null);
        self::assertSame('running', $this->db->epicGet('new')['status'] ?? null);
    }

    public function testCandidateListFiltersAndSorts(): void
    {
        $this->writePlan('b-old', mtime: 1000);
        $this->writePlan('a-same', mtime: 2000);
        $this->writePlan('c-same', mtime: 2000);
        $this->writePlan('newest', mtime: 3000);
        $this->writePlan('taken', mtime: 4000);
        $this->writePlan('.hidden', mtime: 4000);
        $this->writePlan('bad slug', mtime: 4000);
        $this->writePlan('newest.journal', mtime: 4000);
        $this->db->epicCreate('taken', self::CHAT, 99, [], []);

        $this->press('epic:list:'.self::THREAD);

        $rows = $this->rows($this->tg->edits[0]);
        $slugs = array_map(static fn (array $r): string => $r[0]['text'], array_slice($rows, 0, -1));
        self::assertSame(['newest', 'a-same', 'c-same', 'b-old'], $slugs);
        self::assertSame('epic:pick:7:newest', $rows[0][0]['callback_data']);
        self::assertSame('epic:cancel:7', $rows[4][0]['callback_data']);
    }

    public function testCandidateListIsCappedAtTen(): void
    {
        for ($i = 0; $i < 13; ++$i) {
            $this->writePlan(sprintf('p%02d', $i), mtime: 1000 + $i);
        }
        $this->press('epic:list:'.self::THREAD);

        self::assertCount(11, $this->rows($this->tg->edits[0])); // 10 plans + cancel
        self::assertSame('p12', $this->rows($this->tg->edits[0])[0][0]['text']);
    }

    public function testLongCandidateIsLeftOutWithHint(): void
    {
        $long = str_repeat('x', 60);
        $this->writePlan($long);
        $this->writePlan('short');
        $this->press('epic:list:'.self::THREAD);

        $edit = $this->tg->edits[0];
        $slugs = array_map(static fn (array $r): string => $r[0]['text'], array_slice($this->rows($edit), 0, -1));
        self::assertSame(['short'], $slugs);
        self::assertStringContainsString('/epic start <slug>', $edit['text']);
        foreach ($this->rows($edit) as $row) {
            self::assertLessThanOrEqual(64, strlen($row[0]['callback_data']));
        }
    }

    public function testShortListHasNoHintAndNoMarkdown(): void
    {
        $this->writePlan('short');
        $this->press('epic:list:'.self::THREAD);

        self::assertStringNotContainsString('/epic start', $this->tg->edits[0]['text']);
        self::assertSame(1, preg_match('/^[^*_`\[\]]+$/', $this->tg->edits[0]['text']));
    }

    public function testPickShowsConfirmationWithBareUrl(): void
    {
        $this->writePlan('demo');
        $this->press('epic:pick:'.self::THREAD.':demo');

        $edit = $this->tg->edits[0];
        self::assertSame(
            'Start epic demo: 2 tasks, 1 releases, plan: https://github.com/anime-db/ws/blob/main/context/epics/demo.md',
            $edit['text'],
        );
        self::assertStringNotContainsString('](', $edit['text']);
        self::assertSame(['Start', 'Cancel'], array_column($this->rows($edit)[0], 'text'));
        self::assertSame('epic:go:7:demo', $this->rows($edit)[0][0]['callback_data']);
        self::assertNull($this->db->epicGet('demo'));
    }

    public function testPickOfInvalidPlanShowsErrorWithoutStartButton(): void
    {
        file_put_contents($this->work.'/context/epics/broken.md', "no plan here\n");
        $this->press('epic:pick:'.self::THREAD.':broken');

        $edit = $this->tg->edits[0];
        self::assertStringStartsWith('The epic was not created, the plan is invalid:', $edit['text']);
        self::assertSame([], $this->rows($edit));
        self::assertNull($this->db->epicGet('broken'));
    }

    public function testGoStartsTheEpicLikeTheTextCommand(): void
    {
        $this->writePlan('demo');
        $this->command('/epic start demo', 8);
        $expected = $this->lastText();
        $this->db->epicSetStatus('demo', 'done');
        // The same plan under another slug for the button run.
        copy($this->work.'/context/epics/demo.md', $this->work.'/context/epics/demo2.md');
        $this->tg->sent = [];

        $this->press('epic:go:'.self::THREAD.':demo2', 90, 'confirm card');

        self::assertNotNull($this->db->epicGet('demo2'));
        self::assertSame('demo2', $this->db->epicByThread(self::CHAT, self::THREAD)['slug'] ?? null);
        self::assertSame(self::THREAD, $this->tg->sent[count($this->tg->sent) - 1]['thread']);
        self::assertSame(str_replace('demo', 'demo2', $expected), $this->lastText());
        self::assertSame('confirm card', $this->tg->edits[0]['text']);
        self::assertSame(['inline_keyboard' => []], $this->tg->edits[0]['markup']);
        self::assertSame(90, $this->tg->edits[0]['message_id']);
    }

    public function testCancelRemovesTheKeyboard(): void
    {
        $this->press('epic:cancel:'.self::THREAD);

        self::assertSame('Start cancelled.', $this->tg->edits[0]['text']);
        self::assertSame(['inline_keyboard' => []], $this->tg->edits[0]['markup']);
    }

    /** @return array<string, array{string, string}> */
    public static function actionProvider(): array
    {
        return [
            'pause' => ['pause', '/epic pause'],
            'status' => ['status', '/epic status'],
            'resume' => ['resume', '/epic resume'],
        ];
    }

    #[DataProvider('actionProvider')]
    public function testActionButtonRepliesWithTheTextCommandOutput(string $action, string $text): void
    {
        $initial = $action === 'resume' ? 'paused' : 'running';
        $this->epicIn('cur', $initial);
        $this->command($text);
        $expected = $this->lastText();
        // Back to the state before the text command.
        $this->db->epicSetStatus('cur', $initial);
        $this->tg->sent = [];

        $this->press("epic:{$action}:".self::THREAD.':cur', 55, 'epic keyboard');

        self::assertCount(1, $this->tg->sent);
        self::assertSame(self::THREAD, $this->tg->sent[0]['thread']);
        self::assertSame($expected, $this->lastText());
        $edit = $this->tg->edits[count($this->tg->edits) - 1];
        self::assertSame(['inline_keyboard' => []], $edit['markup']);
        self::assertSame('epic keyboard', $edit['text']);
    }

    public function testPressWithPendingDecisionRunsAndKeepsTheRecord(): void
    {
        $this->epicIn('cur');
        $this->db->setDecision(self::CHAT, 3, 55);

        $this->press('epic:pause:'.self::THREAD.':cur');

        self::assertSame('paused', $this->db->epicGet('cur')['status'] ?? null);
        self::assertSame(['chat_id' => self::CHAT, 'thread_id' => 3, 'message_id' => 55], $this->db->getDecision());
    }
}
