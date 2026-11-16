<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubRepoSync::class)]
final class SubRepoSyncProfileTest extends TestCase
{
    private function sync(bool $autoCommit = true, string $subreposDir = ''): SubRepoSync
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: 'owner/repo',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: sys_get_temp_dir(),
            outboxDir: sys_get_temp_dir(),
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 30,
            autoCommit: $autoCommit,
            subreposDir: $subreposDir,
        );

        return new SubRepoSync($config, new Locale());
    }

    public function testEmptySubreposDirHasNoSubReposPart(): void
    {
        $profile = $this->sync(true, '')->formatSyncProfile(0.2, 0.0, []);

        self::assertStringNotContainsString('docs/', $profile['message']);
        self::assertStringNotContainsString('docs/', $profile['log']);
        self::assertStringNotContainsString('/*', $profile['message']);
        self::assertStringNotContainsString('/*', $profile['log']);
        self::assertStringContainsString('managed repository', $profile['log']);
    }

    public function testConfiguredSubreposDirAppearsInMessageAndLog(): void
    {
        $profile = $this->sync(true, 'subrepos')->formatSyncProfile(0.2, 1.5, ['a' => 1.0, 'b' => 0.5]);

        self::assertStringContainsString('subrepos/*', $profile['message']);
        self::assertStringContainsString('subrepos/*', $profile['log']);
        self::assertStringNotContainsString('docs/', $profile['message']);
        self::assertStringNotContainsString('docs/', $profile['log']);
    }

    public function testConfiguredSubreposDirWithoutTimingsKeepsZeroRepoLine(): void
    {
        $profile = $this->sync(true, 'subrepos')->formatSyncProfile(0.2, 0.0, []);

        self::assertStringContainsString('subrepos/*', $profile['message']);
        self::assertStringContainsString('0 repos', $profile['message']);
        self::assertStringContainsString('subrepos/*', $profile['log']);
        self::assertStringContainsString('0 repos', $profile['log']);
    }

    public function testAutoCommitOffHasNoManagedRepositoryPart(): void
    {
        $profile = $this->sync(false, 'subrepos')->formatSyncProfile(0.2, 1.5, ['a' => 1.5]);

        self::assertStringNotContainsString('managed repository', $profile['message']);
        self::assertStringNotContainsString('managed repository', $profile['log']);
        self::assertStringContainsString('subrepos/*', $profile['message']);
        // total is the sum of the parts that were performed (the sub-repositories only).
        self::assertStringContainsString('total 1.5s', $profile['log']);
    }

    public function testNothingSyncedReturnsSkippedLogAndEmptyMessage(): void
    {
        $profile = $this->sync(false, '')->formatSyncProfile(0.0, 0.0, []);

        self::assertSame('sync-profile: skipped (nothing to sync)', $profile['log']);
        self::assertSame('', $profile['message']);
    }
}
