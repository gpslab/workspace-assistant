<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiFormatStatus;
use GpsLab\WorkspaceAssistant\Kimi\KimiVersionCheck;
use GpsLab\WorkspaceAssistant\Kimi\KimiVersionGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiVersionGuard::class)]
#[CoversClass(KimiVersionCheck::class)]
#[CoversClass(KimiFormatStatus::class)]
final class KimiVersionGuardTest extends TestCase
{
    public function testTheVerifiedVersionAndProtocolAreKnown(): void
    {
        $check = KimiVersionGuard::check('2.7.2', '1.5');

        self::assertSame(KimiFormatStatus::Known, $check->version);
        self::assertSame(KimiFormatStatus::Known, $check->protocol);
        self::assertFalse($check->isUntrusted());
    }

    public function testAnUnknownProtocolVersionIsUntrusted(): void
    {
        $check = KimiVersionGuard::check('2.7.2', '1.6');

        self::assertSame(KimiFormatStatus::Unknown, $check->protocol);
        self::assertTrue($check->isUntrusted());
        // The version is checked independently: the protocol verdict says nothing about it.
        self::assertSame(KimiFormatStatus::Known, $check->version);
    }

    /**
     * `metadata` is written once at the start of a `wire.jsonl`, so a window of a resumed run has
     * no protocol version. That is `missing`, not `unknown`, and it is not untrusted: the caller
     * takes the value from the head of the file.
     */
    public function testAMissingProtocolIsMissingAndNotUntrusted(): void
    {
        $check = KimiVersionGuard::check('2.7.2', null);

        self::assertSame(KimiFormatStatus::Missing, $check->protocol);
        self::assertFalse($check->isUntrusted());
        self::assertSame(KimiFormatStatus::Known, $check->version);
    }

    public function testAMissingVersionIsMissing(): void
    {
        $check = KimiVersionGuard::check(null, '1.5');

        self::assertSame(KimiFormatStatus::Missing, $check->version);
        self::assertSame(KimiFormatStatus::Known, $check->protocol);
        self::assertFalse($check->isUntrusted());
    }

    public function testBothValuesMissingAreBothMissing(): void
    {
        $check = KimiVersionGuard::check(null, null);

        self::assertSame(KimiFormatStatus::Missing, $check->version);
        self::assertSame(KimiFormatStatus::Missing, $check->protocol);
        self::assertFalse($check->isUntrusted());
    }

    /**
     * A known protocol with an unknown version reports the version as unknown only: the wire
     * format is the verified one, so the CLI version alone does not untrust it.
     */
    public function testAKnownProtocolWithAnUnknownVersionReportsTheVersionAsUnknownOnly(): void
    {
        $check = KimiVersionGuard::check('2.8.0', '1.5');

        self::assertSame(KimiFormatStatus::Unknown, $check->version);
        self::assertSame(KimiFormatStatus::Known, $check->protocol);
        self::assertFalse($check->isUntrusted());
    }
}
