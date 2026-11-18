<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\RunnerFactory;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunnerFactory::class)]
final class RunnerFactoryTest extends TestCase
{
    public function testResolvesTheClaudeEngineToARunner(): void
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 0,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: sys_get_temp_dir(),
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 30,
        );

        $runner = (new RunnerFactory($config, new Locale()))->create(
            SystemPrompt::MODE_ARCH,
            '',
            [],
            Locale::DEFAULT,
            true,
            false,
            null,
            null,
        );

        self::assertInstanceOf(ClaudeRunner::class, $runner);
    }
}
