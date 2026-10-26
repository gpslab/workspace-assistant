<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\RunnerState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaudeRunner::class)]
final class ClaudeRunnerTest extends TestCase
{
    public function testSessionIdFromInitAndResultEvents(): void
    {
        self::assertSame('sess-abc', ClaudeRunner::sessionIdFromEvent(
            ['type' => 'system', 'subtype' => 'init', 'session_id' => 'sess-abc'],
        ));
        self::assertSame('sess-xyz', ClaudeRunner::sessionIdFromEvent(
            ['type' => 'result', 'session_id' => 'sess-xyz'],
        ));
    }

    public function testSessionIdFromEventNullWhenAbsentOrEmpty(): void
    {
        self::assertNull(ClaudeRunner::sessionIdFromEvent(['type' => 'assistant']));
        self::assertNull(ClaudeRunner::sessionIdFromEvent(['session_id' => '']));
        self::assertNull(ClaudeRunner::sessionIdFromEvent(['session_id' => 123]));
    }

    public function testFormatReadEditWriteUsesBasename(): void
    {
        self::assertSame('📖 Bot.php', ClaudeRunner::formatToolUse('Read', ['file_path' => '/srv/app/src/Bot.php']));
        self::assertSame('✏️ TelegramClient.php', ClaudeRunner::formatToolUse('Edit', ['file_path' => 'src/TelegramClient.php']));
        self::assertSame('📝 new.md', ClaudeRunner::formatToolUse('Write', ['file_path' => 'new.md']));
    }

    public function testFormatBashShowsCommandClipped(): void
    {
        self::assertSame('🔧 git log --oneline -5', ClaudeRunner::formatToolUse('Bash', ['command' => 'git log --oneline -5']));

        $long = str_repeat('x', 100);
        $out = ClaudeRunner::formatToolUse('Bash', ['command' => $long]);
        // emoji + space + 60 chars + ellipsis
        self::assertSame('🔧 '.str_repeat('x', 60).'…', $out);
    }

    public function testFormatBashSkipsLeadingComments(): void
    {
        $cmd = "# Check the dependencies\ncurl -sL https://example.com/api";
        self::assertSame('🔧 curl -sL https://example.com/api', ClaudeRunner::formatToolUse('Bash', ['command' => $cmd]));

        // Comments only — take the whole text as a fallback (clip)
        $onlyComments = "# first\n# second";
        self::assertSame('🔧 # first # second', ClaudeRunner::formatToolUse('Bash', ['command' => $onlyComments]));
    }

    public function testFormatAgentShowsDescription(): void
    {
        self::assertSame('🤖 run the tests', ClaudeRunner::formatToolUse('Agent', ['description' => 'run the tests']));
    }

    public function testFormatUnknownToolUsesName(): void
    {
        self::assertSame('🔨 Grep', ClaudeRunner::formatToolUse('Grep', []));
        self::assertSame('🔨 Tool', ClaudeRunner::formatToolUse('', []));
    }

    public function testProgressLinesIncludeTextThenTools(): void
    {
        $event = [
            'type' => 'assistant',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Checking the config now'],
                    ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'a/b/Foo.php']],
                    ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls -la']],
                ],
            ],
        ];

        // Claude's speech (text) and tool calls — in order of appearance.
        self::assertSame(
            ['💬 Checking the config now', '📖 Foo.php', '🔧 ls -la'],
            ClaudeRunner::progressLinesFromEvent($event),
        );
    }

    public function testThinkingBlocksAreIgnored(): void
    {
        $event = [
            'type' => 'assistant',
            'message' => [
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'internal reasoning (paid)'],
                    ['type' => 'text', 'text' => 'Done'],
                ],
            ],
        ];

        self::assertSame(['💬 Done'], ClaudeRunner::progressLinesFromEvent($event));
    }

    public function testTextBlockMarkdownIsStripped(): void
    {
        $event = [
            'type' => 'assistant',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => '**Option A**: build the index in `meilisearch_new/`'],
                ],
            ],
        ];

        self::assertSame(['💬 Option A: build the index in meilisearch_new/'], ClaudeRunner::progressLinesFromEvent($event));
    }

    public function testNonAssistantEventYieldsNoLines(): void
    {
        self::assertSame([], ClaudeRunner::progressLinesFromEvent(['type' => 'result', 'result' => 'ok']));
        self::assertSame([], ClaudeRunner::progressLinesFromEvent(['type' => 'rate_limit_event']));
        self::assertSame([], ClaudeRunner::progressLinesFromEvent(['type' => 'assistant', 'message' => ['content' => 'no blocks']]));
    }

    /**
     * Regression: claude -p produced an answer but does not exit (Monitor is alive), and after
     * a long silence makes a service turn with a second result. The answer is the first one,
     * the process is finished off after resultGrace of silence, without waiting for the service turn.
     */
    public function testLingeringProcessAfterAnswerIsFinishedWithAnswer(): void
    {
        $runner = $this->runFake(<<<'SH'
            echo '{"type":"system","subtype":"init","session_id":"s-1"}'
            echo '{"type":"result","subtype":"success","is_error":false,"result":"real answer","session_id":"s-1"}'
            sleep 5
            echo '{"type":"system","subtype":"init","session_id":"s-1"}'
            echo '{"type":"result","subtype":"success","is_error":false,"result":"Monitor completed","session_id":"s-1"}'
            exec sleep 60
            SH);

        self::assertSame('real answer', $runner->result()->text);
        self::assertFalse($runner->result()->isError);
    }

    /**
     * Regression: on --resume claude first processes the accumulated
     * task-notification (empty result), then the prompt. An empty first result
     * must neither abort the run nor replace the answer.
     */
    public function testEmptyNotificationResultDoesNotCutOffPrompt(): void
    {
        $runner = $this->runFake(<<<'SH'
            echo '{"type":"system","subtype":"init","session_id":"s-1"}'
            echo '{"type":"result","subtype":"success","is_error":false,"result":"","session_id":"s-1"}'
            sleep 1
            echo '{"type":"system","subtype":"init","session_id":"s-1"}'
            sleep 3
            echo '{"type":"result","subtype":"success","is_error":false,"result":"answer to the prompt","session_id":"s-1"}'
            exec sleep 60
            SH);

        self::assertSame('answer to the prompt', $runner->result()->text);
    }

    public function testTailWrittenByDescendantAfterExitIsDrained(): void
    {
        $runner = $this->runFake(<<<'SH'
            echo '{"type":"system","subtype":"init","session_id":"s-1"}'
            (sleep 1; echo '{"type":"result","is_error":false,"result":"late tail","session_id":"s-1"}') &
            exit 0
            SH, false);

        self::assertSame('late tail', $runner->result()->text);
        self::assertFalse($runner->result()->exitedWithoutResult);
    }

    public function testExitWithoutResultRecoversAssistantTextAndWritesDiagnostics(): void
    {
        $dir = sys_get_temp_dir().'/runner-diag-'.uniqid('', true);
        $bin = tempnam(sys_get_temp_dir(), 'fake-claude-');
        self::assertIsString($bin);
        file_put_contents($bin, "#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"answer\"}],\"usage\":{\"output_tokens\":7}}}'\necho oops >&2\n");
        chmod($bin, 0o755);

        try {
            $runner = new ClaudeRunner($bin, sys_get_temp_dir(), 0, [], stateDir: $dir);
            $runner->start('prompt', null);
            while ($runner->poll() === RunnerState::Running) {
                usleep(50000);
            }
            $result = $runner->result();
            self::assertFalse($result->isError);
            self::assertTrue($result->exitedWithoutResult);
            self::assertTrue($result->recovered);
            self::assertSame('answer', $result->text);
            self::assertSame(7, $result->outputTokens);
            $path = $runner->diagnosticsPath();
            self::assertNotNull($path);
            self::assertStringStartsWith($dir.'/claude-no-result-', $path);
            $body = (string) file_get_contents($path);
            self::assertStringContainsString('assistant', $body);
            self::assertStringNotContainsString('answer', $body); // content is not persisted
            self::assertStringContainsString('oops', $body);
            self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        } finally {
            unlink($bin);
            array_map('unlink', glob($dir.'/*.log') ?: []);
            @rmdir($dir.'/runs');
            @rmdir($dir);
        }
    }

    /** Regression: a non-blocking writer must not lose its tail when the bot does not read for a while. */
    public function testOutputLargerThanPipeSurvivesBusyReader(): void
    {
        $bin = $this->phpFake(<<<'PHP'
            stream_set_blocking(STDOUT, false);
            fwrite(STDOUT, '{"type":"system","subtype":"init","session_id":"s"}'."\n");
            fwrite(STDOUT, '{"type":"user","x":"'.str_repeat('a', 200000).'"}'."\n");
            fwrite(STDOUT, '{"type":"result","is_error":false,"result":"ok","session_id":"s"}'."\n");
            exit(0);
            PHP);
        $stateDir = sys_get_temp_dir().'/runner-state-'.uniqid('', true);

        try {
            $runner = new ClaudeRunner($bin, sys_get_temp_dir(), 0, [], stateDir: $stateDir);
            $runner->start('prompt', null);
            sleep(1);
            self::assertSame(RunnerState::Done, $runner->poll());
            self::assertSame('ok', $runner->result()->text);
            self::assertFalse($runner->result()->exitedWithoutResult);
            self::assertSame([], glob($stateDir.'/runs/*') ?: []);
        } finally {
            unlink($bin);
            @rmdir($stateDir.'/runs');
            @rmdir($stateDir);
        }
    }

    public function testRunFilesAreRemovedAfterNoResultAndKill(): void
    {
        $stateDir = sys_get_temp_dir().'/runner-state-'.uniqid('', true);
        $bin = tempnam(sys_get_temp_dir(), 'fake-claude-');
        self::assertIsString($bin);
        file_put_contents($bin, "#!/bin/sh\nif [ \"\$1\" = -p ] && [ \"\$2\" = hang ]; then echo '{\"type\":\"system\"}'; exec sleep 60; fi\necho '{\"type\":\"system\"}'\n");
        chmod($bin, 0o755);

        try {
            $runner = new ClaudeRunner($bin, sys_get_temp_dir(), 0, [], stateDir: $stateDir);
            $runner->start('prompt', null);
            while ($runner->poll() === RunnerState::Running) {
                usleep(50000);
            }
            self::assertTrue($runner->result()->exitedWithoutResult);
            self::assertSame([], glob($stateDir.'/runs/*') ?: []);

            $runner->start('hang', null);
            usleep(300000);
            $runner->poll();
            self::assertNotSame([], glob($stateDir.'/runs/*') ?: [], 'run files exist while running');
            $runner->kill();
            self::assertSame([], glob($stateDir.'/runs/*') ?: []);
        } finally {
            unlink($bin);
            array_map('unlink', glob($stateDir.'/claude-no-result-*') ?: []);
            @rmdir($stateDir.'/runs');
            @rmdir($stateDir);
        }
    }

    public function testNothingIsCreatedInRepoPathWithEmptyStateDir(): void
    {
        $repo = sys_get_temp_dir().'/runner-repo-'.uniqid('', true);
        mkdir($repo, 0o700);
        $bin = $this->phpFake(<<<'PHP'
            fwrite(STDOUT, '{"type":"result","is_error":false,"result":"ok","session_id":"s"}'."\n");
            PHP);
        $tmpDirsBefore = glob(sys_get_temp_dir().'/claude-run-*') ?: [];

        try {
            $runner = new ClaudeRunner($bin, $repo, 0, []);
            $runner->start('prompt', null);
            while ($runner->poll() === RunnerState::Running) {
                usleep(50000);
            }
            self::assertSame('ok', $runner->result()->text);
            self::assertSame([], array_values(array_diff(scandir($repo) ?: [], ['.', '..'])));
            self::assertSame($tmpDirsBefore, glob(sys_get_temp_dir().'/claude-run-*') ?: []);
        } finally {
            unlink($bin);
            @rmdir($repo);
        }
    }

    public function testPurgeRunFilesEmptiesRunsDirectory(): void
    {
        $stateDir = sys_get_temp_dir().'/runner-state-'.uniqid('', true);
        mkdir($stateDir.'/runs', 0o700, true);
        touch($stateDir.'/runs/old.out');
        touch($stateDir.'/keep.txt');

        ClaudeRunner::purgeRunFiles($stateDir);

        self::assertSame([], glob($stateDir.'/runs/*') ?: []);
        self::assertFileExists($stateDir.'/keep.txt');
        unlink($stateDir.'/keep.txt');
        rmdir($stateDir.'/runs');
        rmdir($stateDir);
    }

    public function testPurgeRunFilesKeepsFilesOfLiveProcesses(): void
    {
        $stateDir = sys_get_temp_dir().'/runner-state-'.uniqid('', true);
        mkdir($stateDir.'/runs', 0o700, true);
        $live = $stateDir.'/runs/20260101-000000-'.getmypid().'-abcd.out';
        $dead = $stateDir.'/runs/20260101-000000-2147483646-abcd.out';
        touch($live);
        touch($dead);

        ClaudeRunner::purgeRunFiles($stateDir);

        self::assertFileExists($live);
        self::assertFileDoesNotExist($dead);
        unlink($live);
        rmdir($stateDir.'/runs');
        rmdir($stateDir);
    }

    /** Executable PHP-script fake claude (shebang = the PHP running the tests). */
    private function phpFake(string $code): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'fake-claude-');
        self::assertIsString($bin);
        file_put_contents($bin, '#!'.PHP_BINARY."\n<?php\n".$code."\n");
        chmod($bin, 0o755);

        return $bin;
    }

    /** Runs a fake claude (sh script) to Done with resultGrace=2 and checks that it is finished off. */
    private function runFake(string $script, bool $expectKilled = true): ClaudeRunner
    {
        $bin = tempnam(sys_get_temp_dir(), 'fake-claude-');
        self::assertIsString($bin);
        file_put_contents($bin, "#!/bin/sh\n".$script."\n");
        chmod($bin, 0o755);

        try {
            $runner = new ClaudeRunner($bin, sys_get_temp_dir(), 0, [], 0, '', resultGrace: 2);
            $runner->start('prompt', null);
            $pid = $runner->pid();
            $started = microtime(true);
            while (($state = $runner->poll()) === RunnerState::Running) {
                self::assertLessThan(15, microtime(true) - $started, 'the run did not finish after the answer');
                usleep(100000);
            }

            self::assertSame(RunnerState::Done, $state);
            if ($expectKilled) {
                self::assertFalse(posix_kill($pid, 0), 'the process must be killed');
            }

            return $runner;
        } finally {
            unlink($bin);
        }
    }
}
