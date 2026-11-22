<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Kimi\KimiGatedPreconditionResult;
use GpsLab\WorkspaceAssistant\Kimi\KimiGatedPreconditions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiGatedPreconditions::class)]
#[CoversClass(KimiGatedPreconditionResult::class)]
final class KimiGatedPreconditionsTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/kimi-preconditions-'.uniqid('', true);
        mkdir($this->home, 0o700, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->home.'/.agents');
        @rmdir($this->home);
    }

    public function testOkWhenNothingIsWrong(): void
    {
        $result = KimiGatedPreconditions::check($this->home, '1.5', null);

        self::assertTrue($result->isOk());
        self::assertSame([], $result->reasons);
    }

    /** Kimi reads agents, skills and AGENTS.md from `$HOME/.agents` even with a relocated home. */
    public function testTheHomeAgentsDirectoryIsAReason(): void
    {
        mkdir($this->home.'/.agents', 0o700);

        $result = KimiGatedPreconditions::check($this->home, '1.5', null);

        self::assertFalse($result->isOk());
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('.agents', $result->reasons[0]);
    }

    public function testAnUnknownProtocolVersionIsAReason(): void
    {
        $result = KimiGatedPreconditions::check($this->home, '9.9', null);

        self::assertFalse($result->isOk());
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('protocol version 9.9 is unknown', $result->reasons[0]);
    }

    /** A missing protocol version is not unknown: the window simply did not reach the head of the file. */
    public function testAMissingProtocolVersionIsNotAReason(): void
    {
        self::assertTrue(KimiGatedPreconditions::check($this->home, null, null)->isOk());
    }

    public function testASelfTestFailureIsAReasonAndKeepsItsText(): void
    {
        $result = KimiGatedPreconditions::check($this->home, '1.5', 'the run home is not writable');

        self::assertFalse($result->isOk());
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('self-test failed', $result->reasons[0]);
        self::assertStringContainsString('the run home is not writable', $result->reasons[0]);
    }

    public function testEveryFailingConditionIsReported(): void
    {
        mkdir($this->home.'/.agents', 0o700);

        $result = KimiGatedPreconditions::check($this->home, '9.9', 'boom');

        self::assertCount(3, $result->reasons);
    }

    /**
     * This task does not connect the predicate: the refusal of gated modes on Kimi is unchanged.
     */
    public function testTheRefusalOfGatedModesOnKimiIsUnchanged(): void
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
            engine: Config::ENGINE_KIMI,
        );

        self::assertTrue($config->gatesUnavailable());
    }
}
