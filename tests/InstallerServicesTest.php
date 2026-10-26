<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\InstallerServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstallerServices::class)]
final class InstallerServicesTest extends TestCase
{
    public function testRunProcessCapturesOutputAndExitCode(): void
    {
        [$code, $out, $err] = InstallerServices::runProcess(['sh', '-c', 'echo out; echo err >&2; exit 3'], [], 5, null, false);

        self::assertSame(3, $code);
        self::assertSame("out\n", $out);
        self::assertSame("err\n", $err);
    }

    public function testRunProcessKillsOnTimeout(): void
    {
        $start = microtime(true);
        [$code] = InstallerServices::runProcess(['sleep', '10'], [], 1, null, false);

        self::assertSame(InstallerServices::EXIT_TIMEOUT, $code);
        self::assertLessThan(5, microtime(true) - $start);
    }

    public function testRunProcessReportsMissingProgram(): void
    {
        [$code] = InstallerServices::runProcess(['definitely-not-a-program-x'], [], 5, null, false);

        self::assertSame(InstallerServices::EXIT_NOT_FOUND, $code);
    }

    public function testSystemServicesReflectTheRealEnvironment(): void
    {
        $services = InstallerServices::system();

        self::assertSame(PHP_BINARY, $services->phpBinary);
        self::assertTrue(($services->extensionLoaded)('json'));
        self::assertSame(stream_isatty(STDIN), ($services->isTty)());
    }
}
