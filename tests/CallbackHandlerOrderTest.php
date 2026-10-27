<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Callback\EpicHandler;
use GpsLab\WorkspaceAssistant\Callback\Handler;
use GpsLab\WorkspaceAssistant\Callback\LangHandler;
use GpsLab\WorkspaceAssistant\Callback\ModeHandler;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bot::class)]
#[CoversClass(DirtyTreeDecision::class)]
final class CallbackHandlerOrderTest extends TestCase
{
    /**
     * The stateless handlers must come before the decision handler, which supports every data:
     * a stateless keyboard moved after it would be swallowed by the commit/discard checks.
     */
    public function testStatelessHandlersPrecedeTheDecisionHandler(): void
    {
        self::assertSame(
            [ModeHandler::class, LangHandler::class, EpicHandler::class, DirtyTreeDecision::class],
            array_map(static fn (Handler $handler): string => $handler::class, $this->handlers()),
        );
    }

    public function testEachKeyboardGoesToItsOwnHandler(): void
    {
        $firstSupporting = static function (array $handlers, string $data): string {
            foreach ($handlers as $handler) {
                if ($handler->supports($data)) {
                    return $handler::class;
                }
            }

            return '';
        };
        $handlers = $this->handlers();

        self::assertSame(ModeHandler::class, $firstSupporting($handlers, 'mode:pm:0'));
        self::assertSame(LangHandler::class, $firstSupporting($handlers, 'lang:en'));
        self::assertSame(EpicHandler::class, $firstSupporting($handlers, 'epic:resume:0'));
        self::assertSame(DirtyTreeDecision::class, $firstSupporting($handlers, 'commit'));
        self::assertSame(DirtyTreeDecision::class, $firstSupporting($handlers, 'discard'));
        self::assertSame(DirtyTreeDecision::class, $firstSupporting($handlers, 'anything'));
    }

    /**
     * @return list<Handler>
     */
    private function handlers(): array
    {
        $dir = sys_get_temp_dir().'/tgbot_'.uniqid('', true);
        mkdir($dir);
        $db = new Database($dir);
        $config = new Config(
            botToken: 'test-token',
            allowedUserIds: [1],
            repoPath: $dir,
            githubRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $dir,
            outboxDir: $dir,
            inboxDir: $dir,
            pollTimeout: 30,
            gitTimeout: 30,
            githubOwners: new GithubOwners(['anime-db']),
        );
        $bot = new Bot($config, $db, new FakeTelegram(), new GitManager($dir, 30), new SessionFiles($dir));
        $handlers = (new \ReflectionProperty(Bot::class, 'callbackHandlers'))->getValue($bot);
        self::assertIsArray($handlers);
        $handlers = array_values($handlers);
        // The handlers hold the bot, which holds the database: drop them before cleanup.
        unset($bot, $db);
        foreach (['state.sqlite', 'state.sqlite-wal', 'state.sqlite-shm'] as $file) {
            @unlink($dir.'/'.$file);
        }
        @rmdir($dir);

        return $handlers;
    }
}
