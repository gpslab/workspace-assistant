<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\RunnerFactory;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunnerFactory::class)]
final class RunnerFactoryTest extends TestCase
{
    public function testResolvesTheClaudeEngineToARunner(): void
    {
        $runner = $this->factory($this->config())->create(
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

    public function testWiresTheModelAndTheRequestedLocaleIntoTheRunner(): void
    {
        $argvFile = tempnam(sys_get_temp_dir(), 'factory-argv-');
        $stateDir = sys_get_temp_dir().'/factory-run-'.uniqid('', true);
        self::assertIsString($argvFile);
        $bin = $this->argvRecorder($argvFile);

        try {
            $runner = $this->factory($this->config(claudeBin: $bin, stateDir: $stateDir))->create(
                SystemPrompt::MODE_ARCH,
                '',
                [],
                'ru',
                false,
                false,
                null,
                'sonnet',
            );
            $runner->start('hello', null);
            while ($runner->poll() === RunnerState::Running) {
                usleep(50000);
            }

            /** @var list<string> $argv */
            $argv = json_decode((string) file_get_contents($argvFile), true);

            // The resolved model leaves the factory as --model <m>.
            $modelAt = array_search('--model', $argv, true);
            self::assertIsInt($modelAt);
            self::assertSame('sonnet', $argv[$modelAt + 1]);

            // The requested locale ends up in the system prompt passed by --append-system-prompt,
            // not the factory's own default locale.
            $appendAt = array_search('--append-system-prompt', $argv, true);
            self::assertIsInt($appendAt);
            self::assertStringContainsString('in Russian', $argv[$appendAt + 1]);
        } finally {
            unlink($bin);
            unlink($argvFile);
            @rmdir($stateDir.'/runs');
            @rmdir($stateDir);
        }
    }

    public function testUnsupportedEngineFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported ENGINE=opencode.');

        $this->factory($this->config(engine: 'opencode'))->create(
            SystemPrompt::MODE_ARCH,
            '',
            [],
            Locale::DEFAULT,
            true,
            false,
            null,
            null,
        );
    }

    private function factory(Config $config): RunnerFactory
    {
        return new RunnerFactory($config, new Locale());
    }

    /** A minimal config: only the fields the factory reads, so a signature change breaks the test, not the factory. */
    private function config(string $engine = 'claude', string $claudeBin = 'claude', string $stateDir = ''): Config
    {
        return new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: $claudeBin,
            claudeTimeout: 0,
            claudeStallTimeout: 0,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $stateDir,
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 30,
            engine: $engine,
        );
    }

    /** A fake claude that writes its argv (minus the script path) as JSON to $argvFile and then answers ok. */
    private function argvRecorder(string $argvFile): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'factory-claude-');
        self::assertIsString($bin);
        file_put_contents(
            $bin,
            '#!'.PHP_BINARY."\n<?php\n"
            .'file_put_contents('.(string) var_export($argvFile, true).", json_encode(array_slice(\$argv, 1)));\n"
            ."echo '{\"type\":\"result\",\"is_error\":false,\"result\":\"ok\",\"session_id\":\"s\"}'.\"\\n\";\n",
        );
        chmod($bin, 0o755);

        return $bin;
    }
}
