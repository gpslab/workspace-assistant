<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiRunHomeRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiRunHomeRenderer::class)]
final class KimiRunHomeRendererTest extends TestCase
{
    private string $root;
    private string $runHome;
    private string $shim;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-run-home-'.uniqid('', true);
        $this->runHome = $this->root.'/run-home';
        mkdir($this->runHome, 0o700, true);
        mkdir($this->root.'/repo/.claude/agents', 0o700, true);
        mkdir($this->root.'/home/.claude/agents', 0o700, true);
        $this->shim = $this->root.'/bin/kimi-hook.php';
        mkdir($this->root.'/bin', 0o700);
        file_put_contents($this->shim, '<?php');
    }

    protected function tearDown(): void
    {
        @chmod($this->runHome, 0o700);
        self::rrmdir($this->root);
    }

    public function testAllRunHomeFilesAreReturnedWithoutWritingToAReadOnlyTarget(): void
    {
        chmod($this->runHome, 0o500);
        try {
            $files = $this->renderer()->render();

            self::assertSame([], array_values(array_diff(scandir($this->runHome) ?: [], ['.', '..'])));
        } finally {
            chmod($this->runHome, 0o700);
        }

        self::assertArrayHasKey(KimiRunHomeRenderer::CONFIG_FILE, $files);
        self::assertArrayHasKey(KimiRunHomeRenderer::AGENT_FILE, $files);
        self::assertArrayHasKey('agents/critic.md', $files);
        self::assertArrayHasKey('agents/security.md', $files);
        self::assertStringContainsString('extra_agent_dirs = ["'.$this->runHome.'/agents"]', $files[KimiRunHomeRenderer::CONFIG_FILE]);
        self::assertStringContainsString('command = "'.self::toml(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->shim)).'"', $files[KimiRunHomeRenderer::CONFIG_FILE]);
        self::assertStringContainsString("\${base_prompt}\n\nmode prompt\n\ninstance addition\n\nlanguage line", $files[KimiRunHomeRenderer::AGENT_FILE]);
    }

    /**
     * An empty shim path means "no gate shim installed yet": the config is rendered without hook
     * tables, while the rest of the run home is the same.
     */
    public function testAnEmptyShimPathRendersNoHookTables(): void
    {
        $files = (new KimiRunHomeRenderer(
            (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/config.toml'),
            $this->runHome,
            '',
            'mode prompt',
            '',
            '',
            $this->root.'/repo',
            $this->root.'/home',
        ))->render();

        self::assertStringNotContainsString('[[hooks]]', $files[KimiRunHomeRenderer::CONFIG_FILE]);
        self::assertStringContainsString('extra_agent_dirs = ["'.$this->runHome.'/agents"]', $files[KimiRunHomeRenderer::CONFIG_FILE]);
        self::assertArrayHasKey(KimiRunHomeRenderer::AGENT_FILE, $files);
    }

    public function testAMissingShimIsAnError(): void
    {
        unlink($this->shim);
        $this->expectException(\RuntimeException::class);

        $this->renderer()->render();
    }

    public function testAnEmptyRunHomeIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiRunHomeRenderer(
            '',
            '',
            $this->shim,
            'mode prompt',
            '',
            '',
            $this->root.'/repo',
            $this->root.'/home',
        ))->render();
    }

    public function testAgentWarningsAreCollected(): void
    {
        $warnings = [];

        $this->renderer()->render($warnings);

        self::assertSame([], $warnings);
    }

    private function renderer(): KimiRunHomeRenderer
    {
        return new KimiRunHomeRenderer(
            (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/config.toml'),
            $this->runHome,
            $this->shim,
            'mode prompt',
            'instance addition',
            'language line',
            $this->root.'/repo',
            $this->root.'/home',
        );
    }

    /** The TOML escaping the renderer applies to a command string. */
    private static function toml(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value);
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (in_array(basename($path), ['.', '..'], true)) {
                continue;
            }
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        @rmdir($dir);
    }
}
