<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiHooksRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiHooksRenderer::class)]
final class KimiHooksRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-hooks-'.uniqid('', true);
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }

    public function testEveryEventIsRenderedWithoutAMatcherAndWithTheShim(): void
    {
        $shim = $this->shim();

        $tables = (new KimiHooksRenderer($shim))->render();

        self::assertSame(2, substr_count($tables, '[[hooks]]'));
        self::assertStringNotContainsString('matcher', $tables);
        self::assertSame(2, substr_count($tables, 'event = "'));
        self::assertStringContainsString('event = "SessionStart"', $tables);
        self::assertStringContainsString('event = "PreToolUse"', $tables);
        self::assertStringContainsString('command = "php '.$shim.'"', $tables);
        self::assertSame(2, substr_count($tables, 'timeout = '.KimiHooksRenderer::TIMEOUT));
        self::assertContains(KimiHooksRenderer::TIMEOUT, range(1, 600));
    }

    public function testAMissingShimIsAnErrorNotAnAbsentHook(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiHooksRenderer($this->root.'/missing.php'))->render();
    }

    public function testAPathWithQuotesAndBackslashesIsEscaped(): void
    {
        $shim = $this->root.'/we"ird\path.php';
        file_put_contents($shim, '<?php');

        $tables = (new KimiHooksRenderer($shim))->render();

        self::assertStringContainsString('command = "php '.addcslashes($shim, '"\\').'"', $tables);
    }

    private function shim(): string
    {
        $shim = $this->root.'/kimi-hook.php';
        file_put_contents($shim, '<?php');

        return $shim;
    }
}
