<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiGatedPreconditionResult;
use GpsLab\WorkspaceAssistant\Kimi\KimiGatedPreconditions;
use GpsLab\WorkspaceAssistant\Kimi\KimiGateReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiGatedPreconditions::class)]
#[CoversClass(KimiGatedPreconditionResult::class)]
#[CoversClass(KimiGateReason::class)]
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
        self::assertSame([], $result->failed);
    }

    /** Kimi reads agents, skills and AGENTS.md from `$HOME/.agents` even with a relocated home. */
    public function testTheHomeAgentsDirectoryIsAReason(): void
    {
        mkdir($this->home.'/.agents', 0o700);

        $result = KimiGatedPreconditions::check($this->home, '1.5', null);

        self::assertFalse($result->isOk());
        self::assertSame([KimiGateReason::HomeAgents], $result->failed);
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('.agents', $result->reasons[0]);
    }

    public function testAnUnknownProtocolVersionIsAReason(): void
    {
        $result = KimiGatedPreconditions::check($this->home, '9.9', null);

        self::assertFalse($result->isOk());
        self::assertSame([KimiGateReason::ProtocolUnknown], $result->failed);
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('protocol version 9.9 is unknown', $result->reasons[0]);
        // The version is kept apart: the chat text names it as its own placeholder.
        self::assertSame('9.9', $result->detail(KimiGateReason::ProtocolUnknown));
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
        self::assertSame([KimiGateReason::SelfTestFailed], $result->failed);
        self::assertCount(1, $result->reasons);
        self::assertStringContainsString('self-test failed', $result->reasons[0]);
        self::assertStringContainsString('the run home is not writable', $result->reasons[0]);
        self::assertSame('the run home is not writable', $result->detail(KimiGateReason::SelfTestFailed));
    }

    public function testEveryFailingConditionIsReported(): void
    {
        mkdir($this->home.'/.agents', 0o700);

        $result = KimiGatedPreconditions::check($this->home, '9.9', 'boom');

        self::assertSame(
            [KimiGateReason::HomeAgents, KimiGateReason::ProtocolUnknown, KimiGateReason::SelfTestFailed],
            $result->failed,
        );
        self::assertCount(3, $result->reasons);
    }
}
