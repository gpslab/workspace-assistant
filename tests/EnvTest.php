<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env_') ?: throw new \RuntimeException('tmp');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('TG_TEST_OVERRIDE');
    }

    private function write(string $content): void
    {
        file_put_contents($this->file, $content);
    }

    public function testReadsValuesStripsQuotesAndComments(): void
    {
        $this->write(<<<'ENV'
            # comment
            PLAIN=value
            QUOTED="double"
            SQUOTED='single'

            EMPTY=
            ENV);

        $env = Env::load($this->file);

        self::assertSame('value', $env->get('PLAIN'));
        self::assertSame('double', $env->get('QUOTED'));
        self::assertSame('single', $env->get('SQUOTED'));
        self::assertSame('default', $env->get('MISSING', 'default'));
    }

    public function testListSplitsOnCommaAndTrims(): void
    {
        $this->write("IDS=111, 222 , 333,\nEMPTY=");

        $env = Env::load($this->file);

        self::assertSame(['111', '222', '333'], $env->list('IDS'));
        self::assertSame([], $env->list('EMPTY'));
        self::assertSame([], $env->list('ABSENT'));
    }

    public function testRequireThrowsWhenMissing(): void
    {
        $this->write('FOO=bar');
        $env = Env::load($this->file);

        $this->expectException(\RuntimeException::class);
        $env->require('NOPE');
    }

    public function testRealEnvOverridesFile(): void
    {
        $this->write('TG_TEST_OVERRIDE=fromfile');
        $env = Env::load($this->file);
        self::assertSame('fromfile', $env->get('TG_TEST_OVERRIDE'));

        putenv('TG_TEST_OVERRIDE=fromenv');
        self::assertSame('fromenv', $env->get('TG_TEST_OVERRIDE'));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::load('/no/such/path/.env');
    }
}
