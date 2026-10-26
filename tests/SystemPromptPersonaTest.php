<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemPrompt::class)]
#[CoversClass(ClaudeRunner::class)]
final class SystemPromptPersonaTest extends TestCase
{
    /** Contract lines that no persona file may remove. */
    private const array CONTRACT = [
        'You are running as Workspace Assistant',
        'Delivery — the user sees ONLY your final text response:',
        'Self-preservation — you ARE the bot process',
        'NEVER run bin/ctl.sh',
    ];

    private const array REVIEW_CONTRACT = [
        'Response size — the chat is Telegram',
        'exactly one of two outcomes',
        'one line per id with a verdict',
        'starts with 🟢 or 🔴',
        'Findings go into a PR review comment',
    ];

    private string $dir;

    private string $dist;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/prompts-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        // Shipped texts only: a local prompts/<name>.md of the checkout must not leak into the tests.
        $this->dist = sys_get_temp_dir().'/prompts-dist-'.bin2hex(random_bytes(4));
        mkdir($this->dist);
        foreach (glob(dirname(__DIR__).'/prompts/*.md.dist') ?: [] as $file) {
            copy($file, $this->dist.'/'.basename($file));
        }
        PromptFiles::useDirectory($this->dist);
    }

    protected function tearDown(): void
    {
        PromptFiles::useDirectory(null);
        foreach (glob($this->dist.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dist);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testWithoutPromptsDirKeepsBuiltInText(): void
    {
        foreach (SystemPrompt::MODES as $mode) {
            $built = SystemPrompt::build($mode);
            self::assertSame([], $built['warnings']);
            self::assertStringContainsString('Working style:', $built['text']);
        }
        self::assertStringContainsString('the bot packages it into a file automatically', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
    }

    public function testBuiltInReviewPromptStructure(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_REVIEW);

        $order = [
            'Delivery — the user sees ONLY',
            'Response size — the chat is Telegram',
            'PR review contract —',
            'AT MOST three lines',
            'Role — you are reviewing a PR',
            'File a side finding as a separate issue',
            'Chat verdict format',
            'Optional extra line: "ℹ️ also filed',
        ];
        $last = -1;
        foreach ($order as $needle) {
            $pos = strpos($text, $needle);
            self::assertNotFalse($pos, $needle);
            self::assertGreaterThan($last, $pos, $needle);
            $last = $pos;
        }
        // The verdict rules must be top-level items, not nested under another item.
        self::assertStringContainsString("\n- Chat verdict format", $text);
        self::assertStringContainsString("\n- Optional extra line", $text);
        self::assertStringContainsString('"🟢 Merge — repo#NNN', $text);
        self::assertStringContainsString('the bot packages it into a file automatically', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
    }

    public function testContractHasNoCyrillic(): void
    {
        foreach (['common', 'common_limits', 'contract_review'] as $name) {
            self::assertSame(0, preg_match('/\p{Cyrillic}/u', PromptFiles::load($name)), $name);
        }
    }

    public function testRoleBlockComesFromFileAndContractStays(): void
    {
        file_put_contents($this->dir.'/arch.md', "Custom architect persona.\n");

        $text = SystemPrompt::forMode(SystemPrompt::MODE_ARCH, $this->dir);

        self::assertStringContainsString('Custom architect persona.', $text);
        self::assertStringNotContainsString('you are the architect of the managed repository', $text);
        foreach (self::CONTRACT as $line) {
            self::assertStringContainsString($line, $text);
        }
    }

    public function testCommonFileFollowsContractInAllModes(): void
    {
        file_put_contents($this->dir.'/common.md', 'CUSTOM-COMMON');

        foreach (SystemPrompt::MODES as $mode) {
            $text = SystemPrompt::forMode($mode, $this->dir);
            $common = strpos($text, 'CUSTOM-COMMON');
            self::assertNotFalse($common, $mode);
            self::assertGreaterThan(strpos($text, 'NEVER run bin/ctl.sh'), $common, $mode);
            self::assertStringNotContainsString('Working style:', $text);
        }
    }

    public function testPersonaFileCannotRemoveContract(): void
    {
        foreach (SystemPrompt::MODES as $mode) {
            file_put_contents($this->dir.'/common.md', 'x');
            file_put_contents($this->dir.'/'.$mode.'.md', 'y');

            $text = SystemPrompt::forMode($mode, $this->dir);

            $expected = self::CONTRACT;
            if ($mode !== SystemPrompt::MODE_ARCH) {
                $expected[] = self::REVIEW_CONTRACT[0];
            }
            if ($mode === SystemPrompt::MODE_REVIEW) {
                $expected = [...$expected, ...self::REVIEW_CONTRACT];
            }
            foreach ($expected as $line) {
                self::assertStringContainsString($line, $text, $mode);
            }
        }
    }

    public function testMissingFileFallsBackWithWarning(): void
    {
        foreach (SystemPrompt::MODES as $mode) {
            $built = SystemPrompt::build($mode, $this->dir);

            self::assertSame(SystemPrompt::forMode($mode), $built['text']);
            self::assertCount(2, $built['warnings']);
            self::assertStringContainsString('common.md', $built['warnings'][0]);
            self::assertStringContainsString($mode.'.md', $built['warnings'][1]);
        }
    }

    public function testEmptyFileFallsBackWithRelativeWarning(): void
    {
        file_put_contents($this->dir.'/review.md', " \n\t");
        file_put_contents($this->dir.'/common.md', '');

        $built = SystemPrompt::build(SystemPrompt::MODE_REVIEW, $this->dir, 'context/prompts');

        self::assertSame(SystemPrompt::forMode(SystemPrompt::MODE_REVIEW), $built['text']);
        self::assertCount(2, $built['warnings']);
        self::assertStringContainsString('context/prompts/common.md', $built['warnings'][0]);
        self::assertStringContainsString('context/prompts/review.md', $built['warnings'][1]);
        self::assertStringNotContainsString($this->dir, $built['warnings'][1]);
    }

    public function testFileEditAppliesOnNextCall(): void
    {
        file_put_contents($this->dir.'/pm.md', 'first');
        self::assertStringContainsString('first', SystemPrompt::forMode(SystemPrompt::MODE_PM, $this->dir));

        file_put_contents($this->dir.'/pm.md', 'second');
        $text = SystemPrompt::forMode(SystemPrompt::MODE_PM, $this->dir);

        self::assertStringContainsString('second', $text);
        self::assertStringNotContainsString('first', $text);
    }

    public function testRunnerPassesPromptsDirAndAppendsInstanceText(): void
    {
        mkdir($this->dir.'/p');
        file_put_contents($this->dir.'/p/arch.md', 'RUNNER-PERSONA');
        $argvFile = $this->dir.'/argv.txt';
        $fake = $this->dir.'/claude.sh';
        file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done > ".escapeshellarg($argvFile)."\n");
        chmod($fake, 0755);

        $runner = new ClaudeRunner($fake, $this->dir, 10, [], 0, 'INSTANCE-APPEND', SystemPrompt::MODE_ARCH, promptsDir: 'p', withAgents: false);
        try {
            $this->runnerAssertions($runner, $argvFile);
        } finally {
            $runner->kill();
            @unlink($this->dir.'/p/arch.md');
            @rmdir($this->dir.'/p');
            @unlink($fake);
            @unlink($argvFile);
        }
    }

    private function runnerAssertions(ClaudeRunner $runner, string $argvFile): void
    {
        $runner->start('hi', null);
        for ($i = 0; $i < 50 && !is_file($argvFile); ++$i) {
            usleep(100000);
        }
        usleep(200000);

        $args = explode("\0", (string) file_get_contents($argvFile));
        $prompt = $args[array_search('--append-system-prompt', $args, true) + 1];
        self::assertStringContainsString('RUNNER-PERSONA', $prompt);
        self::assertStringEndsWith('write them in English.', $prompt);
        self::assertStringContainsString(SystemPrompt::languageLine('English'), $prompt);
        self::assertGreaterThan(strpos($prompt, 'RUNNER-PERSONA'), strpos($prompt, 'INSTANCE-APPEND'));
        self::assertGreaterThan(strpos($prompt, 'INSTANCE-APPEND'), strpos($prompt, 'Write your final reply'));
        self::assertCount(1, $runner->promptWarnings());
        self::assertStringContainsString('p/common.md', $runner->promptWarnings()[0]);
        self::assertStringNotContainsString($this->dir, $runner->promptWarnings()[0]);
    }

    public function testPersonaIsLoadedFromPromptsDirFixture(): void
    {
        $fixture = __DIR__.'/fixtures/prompts';

        foreach (SystemPrompt::MODES as $mode) {
            $built = SystemPrompt::build($mode, $fixture);

            self::assertSame([], $built['warnings'], $mode);
            self::assertStringContainsString('FIXTURE-COMMON-PERSONA', $built['text'], $mode);
            self::assertStringContainsString('FIXTURE-'.strtoupper($mode).'-PERSONA', $built['text'], $mode);
            self::assertStringNotContainsString('Working style:', $built['text'], $mode);
        }
    }

    public function testDefaultTextsAreNeutral(): void
    {
        $forbidden = ['AnimeDB', 'animedb-', '`critic`', '`verifier`', '`editor`', '`scope-guard`', 'agents.md', 'openronin'];
        foreach (SystemPrompt::MODES as $mode) {
            $text = SystemPrompt::forMode($mode);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString($needle, $text, $mode);
            }
            self::assertSame(0, preg_match('/\p{Cyrillic}/u', $text), $mode);
        }
    }

    public function testDefaultPersonasDoNotSetChatLanguage(): void
    {
        foreach (['persona_common', 'role_arch', 'role_review', 'role_pm'] as $name) {
            self::assertDoesNotMatchRegularExpression('/\b(reply|respond|answer|write|speak)\b[^.\n]*\b(in|using) (Russian|English|the user\'s language)|\blanguage of the (chat|user)\b/i', PromptFiles::load($name), $name);
        }
    }

    public function testContractLinesAfterTheFirstAreUnchanged(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_ARCH);

        self::assertStringStartsWith('You are running as Workspace Assistant: a Claude Code session driven through a Telegram bot. Follow these rules.', $text);
        self::assertStringContainsString("Delivery — the user sees ONLY your final text response:\n- The user communicates via Telegram and CANNOT see tool calls", $text);
        self::assertStringContainsString("Self-preservation — you ARE the bot process (bin/bot.php):\n- NEVER run bin/ctl.sh and never stop, restart, or kill the bot process", $text);
        self::assertStringContainsString('report status by reading files and logs (state/bot.log) and git state, but never manage the process itself.', $text);
    }
}
