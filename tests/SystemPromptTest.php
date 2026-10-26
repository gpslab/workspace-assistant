<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

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
}
