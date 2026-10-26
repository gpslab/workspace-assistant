<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Timezone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Timezone::class)]
final class TimezoneTest extends TestCase
{
    private string $original;
    private string $dir;

    protected function setUp(): void
    {
        $this->original = date_default_timezone_get();
        $this->dir = sys_get_temp_dir().'/tgtz_'.uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->original);
        @unlink($this->dir.'/.env');
        @unlink($this->dir.'/review-hook.log');
        @rmdir($this->dir);
    }

    public function testBootstrapDoesNotHardcodeMoscow(): void
    {
        self::assertStringNotContainsString('Europe/Moscow', (string) file_get_contents(__DIR__.'/../bootstrap.php'));
    }

    public function testUtcWithoutEnvFileOrKey(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        Timezone::applyFromEnvFile($this->dir.'/missing.env');
        self::assertSame('UTC', date_default_timezone_get());

        date_default_timezone_set('Asia/Tokyo');
        file_put_contents($this->dir.'/.env', "BOT_TOKEN=x\n");
        Timezone::applyFromEnvFile($this->dir.'/.env');
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testAppliesConfiguredTimezone(): void
    {
        file_put_contents($this->dir.'/.env', "TIMEZONE=Europe/Moscow\n");
        Timezone::applyFromEnvFile($this->dir.'/.env');
        self::assertSame('Europe/Moscow', date_default_timezone_get());
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        file_put_contents($this->dir.'/.env', "TIMEZONE=Nope/Nowhere\n");
        Timezone::applyFromEnvFile($this->dir.'/.env');
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testStopHookLogUsesConfiguredTimezone(): void
    {
        $root = dirname(__DIR__);
        $cmd = 'echo '.escapeshellarg('{"session_id":"s"}').' | TIMEZONE=Asia/Kolkata '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/bin/review-stop-hook.php').' '.escapeshellarg($this->dir).' 2>&1';
        exec($cmd);

        $log = (string) file_get_contents($this->dir.'/review-hook.log');
        self::assertMatchesRegularExpression('/^\[[\d-]+ [\d:]+\+05:30\]/', $log);
    }

    public function testAcceptsEtcAndBackwardCompatibleIdentifiers(): void
    {
        foreach (['Etc/GMT-3', 'GMT'] as $timezone) {
            self::assertTrue(Timezone::isValid($timezone), $timezone);
        }
        file_put_contents($this->dir.'/.env', "TIMEZONE=Etc/GMT-3\n");
        Timezone::applyFromEnvFile($this->dir.'/.env');
        self::assertSame('Etc/GMT-3', date_default_timezone_get());
    }
}
