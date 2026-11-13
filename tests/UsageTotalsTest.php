<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\UsageTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsageTotals::class)]
final class UsageTotalsTest extends TestCase
{
    public function testFormatCostUsesFourDecimalsBelowOneDollarAndTwoFromIt(): void
    {
        self::assertSame('0.0043', UsageTotals::formatCost(0.0043));
        self::assertSame('1.50', UsageTotals::formatCost(1.5));
        self::assertSame('1.00', UsageTotals::formatCost(1.0));
    }
}
