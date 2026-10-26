<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Chat texts of the bot depend on `BOT_LOCALE` / `LOCALES_DIR`.
 */
#[CoversClass(Bot::class)]
#[CoversClass(Locale::class)]
final class BotLocaleTest extends TestCase
{
    private const int USER = 555;

    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tgbotlocale_'.uniqid('', true);
        mkdir($this->dir.'/locales', 0777, true);
        $this->db = new Database($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/locales/*') ?: []);
        @rmdir($this->dir.'/locales');
        @unlink($this->dir.'/state.sqlite');
        @rmdir($this->dir);
    }

    private function makeBot(FakeTelegram $tg, ?string $locale = null, string $localesDir = ''): Bot
    {
        $tmp = sys_get_temp_dir();
        $config = new Config(
            botToken: 't',
            allowedUserIds: [self::USER],
            repoPath: $this->dir,
            githubRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $tmp,
            outboxDir: $tmp,
            inboxDir: $tmp,
            pollTimeout: 30,
            gitTimeout: 30,
            githubOwners: new GithubOwners(['anime-db']),
            botLocale: $locale ?? Locale::DEFAULT,
            localesDir: $localesDir,
        );

        return new Bot($config, $this->db, $tg, new GitManager($tmp, 30), new SessionFiles($tmp));
    }

    private function command(Bot $bot, string $text): void
    {
        (new \ReflectionMethod(Bot::class, 'handleCommand'))->invoke($bot, 100, 0, $text, 0);
    }

    private function lastText(FakeTelegram $tg): string
    {
        self::assertNotSame([], $tg->sent);

        return $tg->sent[array_key_last($tg->sent)]['text'];
    }

    public function testDefaultLocaleIsEnglish(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg);

        $this->command($bot, '/queue');
        self::assertSame('The queue is empty.', $this->lastText($tg));

        $this->command($bot, '/new');
        self::assertSame('A new session has been started. The context was reset.', $this->lastText($tg));

        $this->command($bot, '/nope');
        self::assertSame('Unknown command: /nope', $this->lastText($tg));

        $this->command($bot, '/mode');
        self::assertSame('Current mode: pm', $this->lastText($tg));

        $this->command($bot, '/mode bogus');
        self::assertSame("Unknown mode: bogus\nAvailable: arch, review, pm", $this->lastText($tg));

        $this->command($bot, '/mode review');
        self::assertSame('Mode switched: review.', $this->lastText($tg));

        $this->command($bot, '/verbose');
        self::assertSame('Token statistics display: enabled', $this->lastText($tg));

        $this->command($bot, '/stats');
        self::assertSame("Mode: review\nQueue: no requests of this topic\nThere is no active session. Write a message to start one.", $this->lastText($tg));

        $this->command($bot, '/epic');
        self::assertSame("Nothing to do for the epic here.\nUsage: /epic start <slug> | pause | resume | status", $this->lastText($tg));

        $this->command($bot, '/cancel');
        self::assertSame('Nothing to cancel.', $this->lastText($tg));

        $this->command($bot, '/help');
        self::assertStringStartsWith('A bot for working with a knowledge base through Claude Code.', $this->lastText($tg));
    }

    public function testExplicitEnglishAndRussianDiffer(): void
    {
        $en = new FakeTelegram();
        $ru = new FakeTelegram();

        $this->command($this->makeBot($en, 'en'), '/queue');
        $this->command($this->makeBot($ru, 'ru'), '/queue');

        self::assertSame('The queue is empty.', $this->lastText($en));
        self::assertSame('Очередь пуста.', $this->lastText($ru));
    }

    public function testAccessDeniedIsLocalized(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg);

        (new \ReflectionMethod(Bot::class, 'handleCallback'))->invoke($bot, ['id' => 'cb', 'from' => ['id' => 1], 'data' => 'commit', 'message' => ['chat' => ['id' => 100]]]);

        self::assertSame(['Access denied.'], $tg->answers);
    }

    public function testInstanceLocaleWithNewCodeIsSelectedByBotLocale(): void
    {
        file_put_contents($this->dir.'/locales/tlh.json', json_encode(['_language' => 'Klingon', 'queue.empty' => 'yIDoghQo\''], JSON_THROW_ON_ERROR));
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'tlh', 'locales');

        $this->command($bot, '/queue');
        self::assertSame('yIDoghQo\'', $this->lastText($tg));

        // A key missing in the selected locale is shown in English.
        $this->command($bot, '/nope');
        self::assertSame('Unknown command: /nope', $this->lastText($tg));
    }

    public function testInstanceFileOverridesBuiltinLocale(): void
    {
        file_put_contents($this->dir.'/locales/ru.json', json_encode(['queue.empty' => 'Пусто.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'ru', 'locales');

        $this->command($bot, '/queue');
        self::assertSame('Пусто.', $this->lastText($tg));

        $this->command($bot, '/nope');
        self::assertSame('Неизвестная команда: /nope', $this->lastText($tg));
    }

    public function testUnknownBotLocaleFallsBackToEnglish(): void
    {
        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'zz');

        $this->command($bot, '/queue');

        self::assertSame('The queue is empty.', $this->lastText($tg));
    }

    /**
     * @return array<string, array<string, string>> locale code => command description by command, from the files
     */
    private static function builtinDescriptions(): array
    {
        $result = [];
        foreach (glob(__DIR__.'/../resources/locales/*.json') ?: [] as $file) {
            $texts = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $result[basename($file, '.json')] = $texts;
        }

        return $result;
    }

    /**
     * @param array<string, string> $texts
     * @param array<string, string> $english
     *
     * @return list<array{command: string, description: string}>
     */
    private static function expectedCommands(array $texts, array $english): array
    {
        $expected = [];
        foreach (['new', 'compact', 'cancel', 'queue', 'stats', 'verbose', 'mode', 'lang', 'epic', 'restart', 'help'] as $command) {
            $expected[] = ['command' => $command, 'description' => $texts["command.{$command}"] ?? $english["command.{$command}"]];
        }

        return $expected;
    }

    public function testCommandsAreRegisteredForEveryLocaleAndOnceWithoutCode(): void
    {
        $catalogs = self::builtinDescriptions();
        $english = $catalogs['en'];
        $codes = array_keys($catalogs);
        sort($codes);

        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'ru');
        (new \ReflectionMethod(Bot::class, 'registerCommands'))->invoke($bot);

        $expected = [];
        foreach ($codes as $code) {
            $expected[] = ['language_code' => $code, 'commands' => self::expectedCommands($catalogs[$code], $english)];
        }
        $expected[] = ['language_code' => null, 'commands' => self::expectedCommands($catalogs['ru'], $english)];
        self::assertSame($expected, $tg->commandLists);
    }

    public function testDefaultCommandListFollowsBotLocaleAndIncludesInstanceLocales(): void
    {
        file_put_contents($this->dir.'/locales/tlh.json', json_encode(['_language' => 'Klingon', 'command.help' => 'QaH'], JSON_THROW_ON_ERROR));
        $english = self::builtinDescriptions()['en'];

        $tg = new FakeTelegram();
        $bot = $this->makeBot($tg, 'tlh', 'locales');
        (new \ReflectionMethod(Bot::class, 'registerCommands'))->invoke($bot);

        $byCode = [];
        foreach ($tg->commandLists as $list) {
            $byCode[$list['language_code'] ?? ''] = $list['commands'];
        }
        self::assertArrayHasKey('tlh', $byCode);
        self::assertSame(self::expectedCommands(['command.help' => 'QaH'], $english), $byCode['tlh']);
        self::assertSame($byCode['tlh'], $byCode[''], 'the default list uses BOT_LOCALE');
        $last = end($tg->commandLists);
        self::assertIsArray($last);
        self::assertNull($last['language_code']);
        self::assertCount(1, array_filter($tg->commandLists, static fn (array $l): bool => $l['language_code'] === null));
    }
}
