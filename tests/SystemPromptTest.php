<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemPrompt::class)]
final class SystemPromptTest extends TestCase
{
    public function testIsNotEmpty(): void
    {
        foreach (SystemPrompt::MODES as $mode) {
            self::assertNotSame('', trim(SystemPrompt::forMode($mode)));
        }
    }

    public function testContainsSelfPreservationRule(): void
    {
        self::assertStringContainsString('bin/ctl.sh', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
        self::assertStringContainsString('restart', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
    }

    public function testContainsDeliveryRule(): void
    {
        self::assertStringContainsString('Telegram', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
        self::assertStringContainsString('final text', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
    }

    public function testCommonPartIsSharedAcrossModes(): void
    {
        // Self-preservation and Delivery are the common part, independent of the mode.
        foreach (SystemPrompt::MODES as $mode) {
            $text = SystemPrompt::forMode($mode);
            self::assertStringContainsString('bin/ctl.sh', $text);
            self::assertStringContainsString('Telegram', $text);
        }
    }

    public function testModesHaveDistinctRoleBlocks(): void
    {
        self::assertStringContainsString('architect', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
        self::assertStringContainsString('verdict', SystemPrompt::forMode(SystemPrompt::MODE_REVIEW));
        self::assertStringContainsString('PM loop', SystemPrompt::forMode(SystemPrompt::MODE_PM));
    }

    public function testResponseSizeHasExplicitLimitsOutsideArch(): void
    {
        foreach ([SystemPrompt::MODE_REVIEW, SystemPrompt::MODE_PM] as $mode) {
            $text = SystemPrompt::forMode($mode);
            self::assertStringContainsString('4096 characters', $text);
            self::assertStringContainsString('1500-2000 characters', $text);
        }
    }

    public function testArchHasNoNumericResponseSizeLimits(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_ARCH);
        self::assertStringNotContainsString('4096 characters', $text);
        self::assertStringNotContainsString('1500-2000 characters', $text);
    }

    public function testFileOverflowIsNotAnErrorOnlyInArch(): void
    {
        $arch = SystemPrompt::forMode(SystemPrompt::MODE_ARCH);
        self::assertStringContainsString('not an error to react to', $arch);
        self::assertStringNotContainsString('mode failure', $arch);

        foreach ([SystemPrompt::MODE_REVIEW, SystemPrompt::MODE_PM] as $mode) {
            $text = SystemPrompt::forMode($mode);
            self::assertStringContainsString('mode failure', $text);
            self::assertStringNotContainsString('not an error to react to', $text);
        }
    }

    public function testIssueOpeningRolesCheckScopeBeforeIssues(): void
    {
        $pm = SystemPrompt::forMode(SystemPrompt::MODE_PM);
        self::assertStringContainsString('PM runbook', $pm);
        self::assertStringNotContainsString('scope-check', $pm);
        $runbook = PromptFiles::loadDist('pm_runbook');
        self::assertStringContainsString('passes its repository\'s CI gates', $runbook);
        self::assertStringContainsString('before any issue is opened', $runbook);
        self::assertStringContainsString('check that every task is in scope for its repository', SystemPrompt::forMode(SystemPrompt::MODE_ARCH));
        self::assertStringContainsString('check that its text is in scope for the repository it targets before filing', SystemPrompt::forMode(SystemPrompt::MODE_REVIEW));
        self::assertStringNotContainsString('Before opening any issue', SystemPrompt::forMode(SystemPrompt::MODE_REVIEW));
    }

    public function testReviewRoleCoversWorkNotJustVerdictFormat(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_REVIEW);
        self::assertStringContainsString('fit to intent', $text);
        self::assertStringContainsString('Mutation-test new tests', $text);
        self::assertStringContainsString('Burden of proof is on the finding', $text);
        self::assertStringContainsString('Read ALL comments on the PR', $text);
        self::assertStringContainsString('inline review comments', $text);
    }

    public function testReviewRoleCoversCrossProjectScope(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_REVIEW);
        self::assertStringContainsString('cross-project scope', $text);
        self::assertStringContainsString('paired half in another repository', $text);
        self::assertStringContainsString('mandatory', $text);
    }

    public function testReviewRolePublishesFormalReviewState(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_REVIEW);
        self::assertStringContainsString('--approve', $text);
        self::assertStringContainsString('exactly one of two outcomes', $text);
        self::assertStringContainsString('only a preparatory step — is 🔴', $text);
        self::assertStringContainsString('--request-changes', $text);
    }

    public function testPmRoleCoversPipelineNotJustLoop(): void
    {
        $text = SystemPrompt::forMode(SystemPrompt::MODE_PM);
        self::assertStringContainsString('delivery pipeline', $text);
        self::assertStringContainsString('PM runbook', $text);
        self::assertStringContainsString('opened issues', $text);
    }

    public function testUnknownModeFallsBackToArch(): void
    {
        self::assertSame(SystemPrompt::forMode(SystemPrompt::MODE_ARCH), SystemPrompt::forMode('bogus'));
    }

    public function testIsValidMode(): void
    {
        self::assertTrue(SystemPrompt::isValidMode('arch'));
        self::assertTrue(SystemPrompt::isValidMode('review'));
        self::assertTrue(SystemPrompt::isValidMode('pm'));
        self::assertFalse(SystemPrompt::isValidMode('bogus'));
        self::assertFalse(SystemPrompt::isValidMode(''));
    }

    public function testGithubForgeAddsNothing(): void
    {
        $fragment = PromptFiles::loadDist('forge_gitlab');

        foreach (SystemPrompt::MODES as $mode) {
            $text = SystemPrompt::forMode($mode, null, Config::FORGE_GITHUB);
            self::assertSame(SystemPrompt::forMode($mode), $text, $mode);
            self::assertStringNotContainsString($fragment, $text, $mode);
            self::assertStringNotContainsString('glab', $text, $mode);
            self::assertSame($text, SystemPrompt::build($mode, null, null, Config::FORGE_GITHUB)['text'], $mode);
        }
    }

    public function testGitlabForgeAppendsFragmentInEveryMode(): void
    {
        $fragment = PromptFiles::loadDist('forge_gitlab');

        foreach (SystemPrompt::MODES as $mode) {
            $github = SystemPrompt::forMode($mode);
            $built = SystemPrompt::build($mode, null, null, Config::FORGE_GITLAB);

            self::assertSame($github."\n\n".$fragment, $built['text'], $mode);
            self::assertSame($built['text'], SystemPrompt::forMode($mode, null, Config::FORGE_GITLAB), $mode);
            self::assertSame(SystemPrompt::build($mode)['warnings'], $built['warnings'], $mode);
        }
    }

    public function testGitlabFragmentMapsEveryGhCommand(): void
    {
        $fragment = PromptFiles::loadDist('forge_gitlab');

        self::assertStringContainsString('GitLab', $fragment);
        self::assertSame([], PromptFiles::placeholders($fragment));
        foreach (['gh issue view', 'gh issue list', 'gh issue create', 'gh pr view', 'gh pr diff', 'gh pr checks', 'gh pr comment', 'gh pr review', 'gh api',
            'glab issue view', 'glab issue list', 'glab issue create', 'glab mr view', 'glab mr diff', 'glab ci status', 'glab mr note', 'glab mr approve', 'glab api',
            '`!N`', '<namespace>/<project>!N', 'merge request', 'pipelines'] as $needle) {
            self::assertStringContainsString($needle, $fragment, $needle);
        }
    }

    public function testAgentPromptsNameBothCli(): void
    {
        foreach (['critic', 'verifier', 'acceptor', 'scope-check'] as $agent) {
            $text = (string) file_get_contents(dirname(__DIR__).'/prompts/agents/'.$agent.'.md.dist');
            self::assertStringContainsString('`gh ... view`/`list` on GitHub, `glab ... view`/`list` on GitLab', $text, $agent);
        }
    }

    public function testRunnerPassesForgeToSystemPrompt(): void
    {
        $dir = sys_get_temp_dir().'/forge-runner-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $fake = $dir.'/claude.sh';
        file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done > \"\$0.argv\"\n");
        chmod($fake, 0755);

        try {
            foreach ([Config::FORGE_GITHUB => false, Config::FORGE_GITLAB => true] as $forge => $expected) {
                @unlink($fake.'.argv');
                $runner = new ClaudeRunner($fake, $dir, 10, [], 0, '', SystemPrompt::MODE_PM, withAgents: false, forge: $forge);
                $runner->start('hi', null);
                for ($i = 0; $i < 50 && !is_file($fake.'.argv'); ++$i) {
                    usleep(100000);
                }
                usleep(200000);
                $runner->kill();

                $args = explode("\0", (string) file_get_contents($fake.'.argv'));
                $prompt = $args[array_search('--append-system-prompt', $args, true) + 1];
                self::assertSame($expected, str_contains($prompt, PromptFiles::loadDist('forge_gitlab')), $forge);
            }
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
