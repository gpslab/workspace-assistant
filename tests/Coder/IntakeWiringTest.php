<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Coder\IntakeApi;
use GpsLab\WorkspaceAssistant\Coder\IntakeConfig;
use GpsLab\WorkspaceAssistant\Coder\IntakeController;
use GpsLab\WorkspaceAssistant\Coder\Routing;
use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\GitManager;
use GpsLab\WorkspaceAssistant\SessionFiles;
use GpsLab\WorkspaceAssistant\Tests\FakeTelegram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

final class WiringIntakeApi implements IntakeApi
{
    public int $issueCalls = 0;

    public function openIssues(string $repository, int $page, int $perPage, int $timeoutSeconds): array
    {
        ++$this->issueCalls;

        return [[
            'number' => 7,
            'state' => 'open',
            'title' => 'Wired intake',
            'body' => 'Implement through the Bot loop',
            'updated_at' => '2025-12-31T23:59:59Z',
            'labels' => [['name' => 'openronin:do-it']],
        ]];
    }

    public function issueEvents(string $repository, int $issueNumber, int $timeoutSeconds): array
    {
        return [[
            'id' => 700,
            'event' => 'labeled',
            'created_at' => '2026-01-01T00:00:00Z',
            'actor' => ['login' => 'peter'],
            'label' => ['name' => 'openronin:do-it'],
        ]];
    }
}

#[CoversClass(Bot::class)]
#[CoversClass(IntakeController::class)]
final class IntakeWiringTest extends TestCase
{
    public function testBotLoopRunsScheduledIntakeWithoutStartingAWorker(): void
    {
        $root = sys_get_temp_dir().'/coder-intake-wiring-'.uniqid('', true);
        mkdir($root.'/repo/.git', 0775, true);
        mkdir($root.'/state/outbox', 0775, true);
        mkdir($root.'/state/inbox', 0775, true);
        $now = microtime(true);
        $store = new Store($root.'/state');
        $api = new WiringIntakeApi();
        $intakeConfig = new IntakeConfig(new Routing(['acme/one' => 'coder']), 'peter', 5);
        $controller = new IntakeController($intakeConfig, $store, $api, static fn (): float => $now);
        $config = new Config(
            botToken: 't',
            allowedUserIds: [555],
            repoPath: $root.'/repo',
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $root.'/state',
            outboxDir: $root.'/state/outbox',
            inboxDir: $root.'/state/inbox',
            pollTimeout: 30,
            gitTimeout: 30,
            coderIntake: $intakeConfig,
        );
        $telegram = new FakeTelegram();
        $db = new Database($root.'/state');
        $bot = new Bot(
            $config,
            $db,
            $telegram,
            new GitManager($root.'/repo', 30),
            new SessionFiles($root),
            coderIntake: $controller,
        );
        $tick = new \ReflectionMethod(Bot::class, 'tick');

        try {
            $tick->invoke($bot);
            $tick->invoke($bot);

            self::assertCount(1, $store->jobs());
            self::assertSame([], $store->workers());
            self::assertSame(1, $api->issueCalls, 'The second tick is before the next scheduled pass.');
            self::assertSame(1, $telegram->pollTimeouts[0]);
            self::assertGreaterThanOrEqual(4, $telegram->pollTimeouts[1]);
            self::assertLessThanOrEqual(5, $telegram->pollTimeouts[1]);
        } finally {
            $store->disconnect();
            exec('rm -rf '.escapeshellarg($root));
        }
    }
}
