<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeErrorText;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaudeErrorText::class)]
final class ClaudeErrorTextTest extends TestCase
{
    private function errorText(): ClaudeErrorText
    {
        return new ClaudeErrorText(new Locale());
    }

    public function testForeignRateLimitOnBare429(): void
    {
        self::assertSame('rate', $this->errorText()->foreignLimit('HTTP 429 Too Many Requests'));
    }

    public function testForeignRateLimitOnRateLimitText(): void
    {
        self::assertSame('rate', $this->errorText()->foreignLimit('You are being rate limited.'));
    }

    public function testForeignQuotaOnQuotaText(): void
    {
        self::assertSame('quota', $this->errorText()->foreignLimit('Your quota has been exceeded.'));
    }

    public function testForeignQuotaOnBalanceText(): void
    {
        self::assertSame('quota', $this->errorText()->foreignLimit('Account balance is exhausted.'));
    }

    public function testForeignQuotaOnInsufficientText(): void
    {
        self::assertSame('quota', $this->errorText()->foreignLimit('Insufficient credits.'));
    }

    public function testQuotaWordWinsOverBare429(): void
    {
        // Aggregators report "429 insufficient credits" for an exhausted balance; that must not pause.
        self::assertSame('quota', $this->errorText()->foreignLimit('429 insufficient quota'));
    }

    public function testNonLimitTextIsNotForeignLimit(): void
    {
        self::assertNull($this->errorText()->foreignLimit('The model refused the request.'));
    }
}
