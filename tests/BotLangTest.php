<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * /lang: the chat language of the Claude replies is stored per user and fixed per queue item.
 */
#[CoversClass(Bot::class)]
#[CoversClass(Database::class)]
#[CoversClass(SystemPrompt::class)]
final class BotLangTest extends TestCase
{
    private const int USER_A = 555;
    private const int USER_B = 556;
    private const string LINE = "Write your final reply to the user — the Telegram message — in %s. This does not set the language of anything else: GitHub texts (issues, reviews, comments), commit messages and repository files follow the managed repository's rules; where none are given, write them in English.";

    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tgbotlang_'.uniqid('', true);
        mkdir($this->dir, 0777, true);
        $this->db = new Database($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function makeBot(FakeTelegram $tg, string $locale = 'en', string $claudeBin = 'claude', string $localesDir = ''): Bot
    {
        $tmp = sys_get_temp_dir();
        $config = new Config(
            botToken: 't',
            allowedUserIds: [self::USER_A, self::USER_B],
            repoPath: $this->dir,
            forgeRepo: '',
            claudeBin: $claudeBin,
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $tmp,
            outboxDir: $tmp,
            inboxDir: $tmp,
            pollTimeout: 30,
            gitTimeout: 30,
            forgeOwners: new ForgeOwners(['anime-db']),
            botLocale: $locale,
            localesDir: $localesDir,
        );

        return new Bot($config, $this->db, $tg, new GitManager($tmp, 30), new SessionFiles($tmp));
    }

    private function command(Bot $bot, int $user, string $text): void
    {
        (new \ReflectionMethod(Bot::class, 'handleCommand'))->invoke($bot, 100, 0, $text, 0, $user);
    }

    /** @param array<string, mixed> $from */
    private function say(Bot $bot, int $user, int $messageId, string $text, array $from = []): void
    {
        (new \ReflectionMethod(Bot::class, 'dispatch'))->invoke($bot, ['update_id' => $messageId, 'message' => [
            'message_id' => $messageId,
            'chat' => ['id' => 100],
            'from' => ['id' => $user] + $from,
            'text' => $text,
        ]]);
    }

    private function lastText(FakeTelegram $tg): string
    {
        self::assertNotSame([], $tg->sent);

        return $tg->sent[array_key_last($tg->sent)]['text'];
    }

    /** @return array<string, array{string}> */
    public static function builtinLocales(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__).'/resources/locales/*.json') ?: [] as $file) {
            $cases[basename($file, '.json')] = [basename($file, '.json')];
        }

        return $cases;
    }

    /** @return list<string|null> */
    private function itemLocales(): array
    {
        return array_map(static fn (array $item): ?string => $item['locale'], $this->db->queueWaiting());
    }

    public function testPromptEndsWithLanguageLineAfterAppendText(): void
    {
        foreach (['', 'INSTANCE-APPEND'] as $append) {
            foreach ((new Locale())->codes() as $code) {
                $locale = new Locale($code);
                $argv = $this->runClaudeAndGetPrompt($locale, $append);

                self::assertStringEndsWith(sprintf(self::LINE, $locale->languageName()), $argv, "{$code}/{$append}");
                if ($append !== '') {
                    self::assertGreaterThan(strpos($argv, $append), strpos($argv, 'Write your final reply'));
                }
            }
        }
    }

    private function runClaudeAndGetPrompt(Locale $locale, string $append): string
    {
        $argvFile = $this->dir.'/argv.txt';
        @unlink($argvFile);
        $fake = $this->dir.'/claude.sh';
        file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done > ".escapeshellarg($argvFile)."\n");
        chmod($fake, 0755);

        $runner = new ClaudeRunner($fake, $this->dir, 10, [], 0, $append, SystemPrompt::MODE_ARCH, locale: $locale);
        $runner->start('hi', null);
        for ($i = 0; $i < 50 && !is_file($argvFile); ++$i) {
            usleep(100000);
        }
        usleep(200000);
        $runner->kill();

        $args = explode("\0", (string) file_get_contents($argvFile));

        return $args[array_search('--append-system-prompt', $args, true) + 1];
    }

    #[DataProvider('builtinLocales')]
    public function testLangSwitchesOnlyTheCallingUsersNextItem(string $code): void
    {
        $base = $code === 'en' ? 'ru' : 'en';
        $bot = $this->makeBot(new FakeTelegram(), $base);
        $this->say($bot, self::USER_A, 1, 'before');
        $this->command($bot, self::USER_A, '/lang '.$code);
        $this->say($bot, self::USER_A, 2, 'after');
        $this->say($bot, self::USER_B, 3, 'from b');

        self::assertSame([$base, $code, $base], $this->itemLocales());
        self::assertSame($code, $this->db->getUserLocale(self::USER_A));
        self::assertNull($this->db->getUserLocale(self::USER_B));
    }

    public function testItemsKeepTheLocaleFixedAtQueueing(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');

        $this->say($bot, self::USER_A, 1, 'old');
        $this->command($bot, self::USER_A, '/lang ru');
        $this->say($bot, self::USER_A, 2, 'new');
        $this->command($bot, self::USER_A, '/lang en');

        // The messages are not merged: the locale differs; both items keep their own.
        self::assertSame(['en', 'ru'], $this->itemLocales());
        $head = $this->db->queueHead();
        self::assertNotNull($head);
        self::assertSame('en', $head['locale']);
    }

    public function testMessagesWithSameModeAndLocaleAreMerged(): void
    {
        $bot = $this->makeBot(new FakeTelegram(), 'en');
        $this->say($bot, self::USER_A, 1, 'one');
        $this->say($bot, self::USER_A, 2, 'two');

        self::assertCount(1, $this->db->queueWaiting());
    }

    public function testLanguageIsReadFromKvAfterRestart(): void
    {
        $this->command($this->makeBot(new FakeTelegram()), self::USER_A, '/lang ru');

        $raw = new \PDO('sqlite:'.$this->dir.'/state.sqlite');
        $stmt = $raw->query("SELECT v FROM kv WHERE k = 'locale:user:".self::USER_A."'");
        self::assertNotFalse($stmt);
        self::assertSame('ru', $stmt->fetchColumn());
        unset($stmt, $raw);

        $reopened = new Database($this->dir);
        self::assertSame('ru', $reopened->getUserLocale(self::USER_A));
        self::assertNull($reopened->getUserLocale(self::USER_B));
    }

    public function testQueueLocaleColumnIsMigrated(): void
    {
        $dir = $this->dir.'/old';
        mkdir($dir);
        $pdo = new \PDO('sqlite:'.$dir.'/state.sqlite');
        $pdo->exec("CREATE TABLE queue (id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, thread_id INTEGER NOT NULL DEFAULT 0, message_id INTEGER NOT NULL, kind TEXT NOT NULL DEFAULT 'chat', mode TEXT NOT NULL, parts TEXT NOT NULL DEFAULT '[]', placeholder_id INTEGER NOT NULL DEFAULT 0, closed INTEGER NOT NULL DEFAULT 0, running INTEGER NOT NULL DEFAULT 0, UNIQUE (chat_id, message_id))");
        $pdo->exec("INSERT INTO queue (chat_id, message_id, mode) VALUES (1, 2, 'pm')");
        unset($pdo);

        $db = new Database($dir);
        $item = $db->queueHead();
        self::assertNotNull($item);
        self::assertNull($item['locale']);

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function testLanguageFromTelegramIsIgnored(): void
    {
        $bot = $this->makeBot(new FakeTelegram(), 'en');
        $this->say($bot, self::USER_A, 1, 'privet', ['language_code' => 'ru']);

        self::assertSame(['en'], $this->itemLocales());
        self::assertNull($this->db->getUserLocale(self::USER_A));
    }

    public function testBotLocaleIsUsedForUsersWithoutChoice(): void
    {
        $bot = $this->makeBot(new FakeTelegram(), 'ru');
        $this->say($bot, self::USER_A, 1, 'hi');

        self::assertSame(['ru'], $this->itemLocales());
    }

    public function testEpicRunsUseBotLocale(): void
    {
        $bot = $this->makeBot(new FakeTelegram(), 'en');
        $this->db->epicCreate('epic', 100, 2, [['task' => 'a#1', 'after' => []]], []);
        $this->command($bot, self::USER_A, '/lang ru');

        self::assertTrue((new \ReflectionProperty(Bot::class, 'queueScheduler'))->getValue($bot)->enqueueEpicRun('epic', 'tick'));
        self::assertSame(['en'], $this->itemLocales());
    }

    public function testRestartMessagesUseItemLocaleOrBotLocale(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $id = (int) $this->db->queueAdd(100, 1, 10, 'chat', 'pm', [], 0, 'ru');
        $this->db->queueMarkRunning($id);
        $legacy = (int) $this->db->queueAdd(100, 2, 11, 'chat', 'pm', [], 0, null);
        $this->db->queueMarkRunning($legacy);
        $this->db->queueAdd(100, 3, 12, 'chat', 'pm', [], 0, 'ru');
        $this->command($bot, self::USER_A, '/lang ru');

        (new \ReflectionMethod(Bot::class, 'recoverQueueOnStart'))->invoke($bot);

        self::assertStringContainsString('Бот перезапущен — задача прервана', implode("\n", $tg->textsTo(100, 1)));
        self::assertStringContainsString('The bot was restarted', implode("\n", $tg->textsTo(100, 2)));
        // The "queue kept" notice has no author: the bot locale.
        self::assertStringContainsString('The bot was restarted', implode("\n", $tg->textsTo(100, 3)));
    }

    #[DataProvider('builtinLocales')]
    public function testLangWithoutArgumentShowsCurrentAndAvailable(string $code): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->command($bot, self::USER_A, '/lang');

        $text = $this->lastText($tg);
        self::assertStringContainsString('en (English)', $text);
        self::assertStringContainsString($code.' (', $text);
        self::assertStringContainsString('Current language: en (English)', $text);
    }

    #[DataProvider('builtinLocales')]
    public function testLangSwitchReplyIsInNewLanguageWithWarning(string $code): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->command($bot, self::USER_A, '/lang '.strtoupper($code));

        $locale = new Locale($code);
        self::assertSame(
            $locale->t('lang.switched', ['code' => $code, 'language' => $locale->languageName()]).$locale->t('lang.session_warning'),
            $this->lastText($tg),
        );
        self::assertSame($code, $this->db->getUserLocale(self::USER_A));
    }

    public function testUnknownCodeListsAvailable(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->command($bot, self::USER_A, '/lang xx-nope');

        $text = $this->lastText($tg);
        self::assertStringContainsString('Unknown language: xx-nope', $text);
        foreach ((new Locale())->codes() as $code) {
            self::assertStringContainsString($code.' (', $text);
        }
        self::assertNull($this->db->getUserLocale(self::USER_A));
    }

    /**
     * @param array<string, mixed>|null $markup
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private function rows(?array $markup): array
    {
        self::assertIsArray($markup);

        return $markup['inline_keyboard'];
    }

    private function press(Bot $bot, int $user, string $data, int $messageId = 77): void
    {
        (new \ReflectionMethod(Bot::class, 'dispatch'))->invoke($bot, ['update_id' => 1, 'callback_query' => [
            'id' => 'cb',
            'from' => ['id' => $user],
            'data' => $data,
            'message' => ['message_id' => $messageId, 'chat' => ['id' => 100]],
        ]]);
    }

    public function testLangWithoutArgumentSendsKeyboardOfAllLocales(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->command($bot, self::USER_A, '/lang ru');
        $this->command($bot, self::USER_A, '/lang');

        $rows = $this->rows($tg->sent[count($tg->sent) - 1]['markup']);
        $codes = (new Locale())->codes();
        $buttons = array_merge(...$rows);

        self::assertSame($codes, array_map(static fn (array $b): string => substr($b['callback_data'], 5), $buttons));
        foreach (array_slice($rows, 0, -1) as $row) {
            self::assertCount(3, $row);
        }
        self::assertLessThanOrEqual(3, count($rows[count($rows) - 1]));
        foreach ($buttons as $button) {
            $isRu = $button['callback_data'] === 'lang:ru';
            self::assertSame($isRu, str_ends_with($button['text'], ' ✓'), $button['callback_data']);
        }
        $byData = array_column($buttons, 'text', 'callback_data');
        self::assertSame('🇷🇺 Русский ✓', $byData['lang:ru']);
        self::assertSame('🇧🇷 Português', $byData['lang:pt']);
    }

    public function testPressSavesPressersLanguageAndEditsMessage(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->command($bot, self::USER_A, '/lang');
        $this->press($bot, self::USER_B, 'lang:ru');

        $ru = new Locale('ru');
        self::assertSame('ru', $this->db->getUserLocale(self::USER_B));
        self::assertNull($this->db->getUserLocale(self::USER_A));
        self::assertSame([$ru->t('lang.switched', ['code' => 'ru', 'language' => 'Russian'])], $tg->answers);
        self::assertCount(1, $tg->edits);
        self::assertSame(77, $tg->edits[0]['message_id']);
        self::assertSame(
            $ru->t('lang.switched', ['code' => 'ru', 'language' => 'Russian']).$ru->t('lang.session_warning'),
            $tg->edits[0]['text'],
        );
        self::assertDoesNotMatchRegularExpression('/[*_`]|\[.*\]\(/', $tg->edits[0]['text']);
        $checked = array_filter(array_merge(...$this->rows($tg->edits[0]['markup'])), static fn (array $b): bool => str_ends_with($b['text'], '✓'));
        self::assertSame(['lang:ru'], array_column($checked, 'callback_data'));
    }

    public function testPressWithUnknownCodeShowsErrorAndWritesNothing(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->press($bot, self::USER_B, 'lang:xx-nope');

        self::assertSame(['Invalid button data.'], $tg->answers);
        self::assertSame([], $tg->edits);
        self::assertNull($this->db->getUserLocale(self::USER_B));
    }

    public function testPressKeepsPendingDecision(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $this->db->setDecision(100, 5, 42);
        $this->press($bot, self::USER_A, 'lang:de');

        self::assertSame('de', $this->db->getUserLocale(self::USER_A));
        self::assertSame(['chat_id' => 100, 'thread_id' => 5, 'message_id' => 42], $this->db->getDecision());
    }

    public function testButtonLabelsComeFromTheOwnCatalogWithoutFallback(): void
    {
        $locales = $this->dir.'/locales';
        mkdir($locales);
        file_put_contents($locales.'/aa.json', '{"_language": "Aa-lang"}');
        file_put_contents($locales.'/bb.json', '{"_language": "Bb-lang", "_flag": "🏴"}');
        file_put_contents($locales.'/cc.json', '{"_native": "Ccish"}');
        file_put_contents($locales.'/dd.json', '{}');

        $locale = new Locale('en', $locales);
        self::assertNull($locale->flag('aa'));
        self::assertSame('Aa-lang', $locale->nativeName('aa'));
        self::assertSame('🏴', $locale->flag('bb'));
        self::assertSame('Ccish', $locale->nativeName('cc'));
        self::assertNull($locale->flag('dd'));
        self::assertSame('dd', $locale->nativeName('dd'));
        self::assertSame('🇬🇧', $locale->flag('en'));

        array_map('unlink', glob($locales.'/*') ?: []);
        rmdir($locales);
    }

    public function testCompactPromptsHaveNoCyrillic(): void
    {
        $files = glob(dirname(__DIR__).'/prompts/compact_*.md.dist') ?: [];
        self::assertCount(3, $files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', (string) file_get_contents($file), basename($file));
        }
    }

    public function testPressReportsFailureWhenSavingTheLanguageFails(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en');
        $raw = new \PDO('sqlite:'.$this->dir.'/state.sqlite');
        $raw->exec('DROP TABLE kv');
        unset($raw);

        $this->press($bot, self::USER_B, 'lang:ru');

        self::assertSame(['Could not save the language.'], $tg->answers);
        self::assertSame([], $tg->edits);
    }

    public function testLangKeyboardSkipsCodesThatDoNotFitCallbackData(): void
    {
        $long = str_repeat('a', 60);
        mkdir($this->dir.'/loc');
        file_put_contents($this->dir.'/loc/'.$long.'.json', '{"_language": "Long"}');
        file_put_contents($this->dir.'/loc/ok.json', '{"_language": "Okay"}');
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'en', localesDir: 'loc');
        $this->command($bot, self::USER_A, '/lang');

        $buttons = array_merge(...$this->rows($tg->sent[count($tg->sent) - 1]['markup']));
        $data = array_column($buttons, 'callback_data');
        self::assertContains('lang:ok', $data);
        self::assertNotContains('lang:'.$long, $data);
        foreach ($data as $item) {
            self::assertLessThanOrEqual(64, strlen($item));
        }

        array_map('unlink', glob($this->dir.'/loc/*') ?: []);
        rmdir($this->dir.'/loc');
    }
}
