<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiConfigRenderer;
use GpsLab\WorkspaceAssistant\Kimi\KimiHooksRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiConfigRenderer::class)]
final class KimiConfigRendererTest extends TestCase
{
    private const string AGENTS_DIR = '/state/kimi-run-1/agents';
    private const string HOOKS = "[[hooks]]\nevent = \"PreToolUse\"\ncommand = \"php /bot/bin/kimi-hook.php\"\ntimeout = 600\n";

    public function testTheSourceConfigIsFilteredAndTheBotsTablesAreAdded(): void
    {
        $rendered = $this->renderFixture();

        // The user's hooks and extra directories never reach a bot run.
        self::assertStringNotContainsString('user-gate.php', $rendered);
        self::assertStringNotContainsString('session.mjs', $rendered);
        self::assertStringNotContainsString('/work/home/team-agents', $rendered);
        self::assertStringNotContainsString('/work/home/.agents/skills', $rendered);
        self::assertStringNotContainsString('extra_skill_dirs', $rendered);

        // The bot's own agents directory is the only `extra_agent_dirs`, and it is top-level.
        self::assertSame(1, substr_count($rendered, 'extra_agent_dirs'));
        self::assertStringContainsString('extra_agent_dirs = ["'.self::AGENTS_DIR.'"]', $rendered);
        self::assertLessThan(
            (int) strpos($rendered, '[providers.'),
            (int) strpos($rendered, 'extra_agent_dirs'),
            'a top-level key must precede every table',
        );

        // The bot's hooks and tool policy are appended.
        self::assertStringContainsString(self::HOOKS, $rendered);
        self::assertStringContainsString(
            "[tools]\ndisabled = [\"CronCreate\", \"CronDelete\", \"CronList\", \"AskUserQuestion\"]",
            $rendered,
        );

        // Provider, model, comments, multi-line arrays and inline tables survive.
        self::assertStringContainsString('[providers."managed:kimi-code"]', $rendered);
        self::assertStringContainsString('[models."kimi-code/kimi-for-coding"]', $rendered);
        self::assertStringContainsString('custom_headers = { "X-Bot" = "REDACTED" }', $rendered);
        self::assertStringContainsString("capabilities = [\"thinking\", \"always_thinking\",\n  \"image_in\", \"video_in\", \"tool_use\"]", $rendered);
        self::assertStringContainsString('# Kimi Code 2.7.2 configuration captured for the fixtures.', $rendered);
        self::assertStringContainsString('default_model = "kimi-code/kimi-for-coding"', $rendered);

        // Static permission rules stay in effect in `-p`.
        self::assertSame(2, substr_count($rendered, '[[permission.rules]]'));
        self::assertStringContainsString('pattern = "Bash(rm -rf*)"', $rendered);
    }

    public function testAValueWithBracketsAndHashesInsideStringsIsOneStatement(): void
    {
        $source = "capabilities = [\"a\", # comment\n  \"b]c\", \"d#e\"]\n[thinking]\nenabled = true\n";

        $rendered = (new KimiConfigRenderer())->render($source, self::AGENTS_DIR, self::HOOKS);

        self::assertStringContainsString($source, $rendered);
    }

    public function testAnExistingToolsTableKeepsItsOtherKeysAndMergesTheDisabledLists(): void
    {
        $source = "[tools]\ndisabled = [\n  \"WebSearch\",\n]\nother = true\n";

        $rendered = (new KimiConfigRenderer())->render($source, self::AGENTS_DIR, self::HOOKS);

        self::assertSame(1, substr_count($rendered, '[tools]'));
        self::assertStringContainsString('other = true', $rendered);
        self::assertStringContainsString(
            'disabled = ["WebSearch", "CronCreate", "CronDelete", "CronList", "AskUserQuestion"]',
            $rendered,
        );
    }

    public function testATopLevelHooksArrayIsDropped(): void
    {
        $source = "default_model = \"x\"\nhooks = [{event = \"PreToolUse\", command = \"curl evil\"}]\n";

        $rendered = (new KimiConfigRenderer())->render($source, self::AGENTS_DIR, self::HOOKS);

        self::assertStringContainsString('default_model = "x"', $rendered);
        self::assertStringNotContainsString('evil', $rendered);
        self::assertStringNotContainsString('hooks =', $rendered);
    }

    public function testATopLevelHooksDottedKeyIsDropped(): void
    {
        $source = "hooks.event = \"PreToolUse\"\nhooks.command = \"curl evil\"\n";

        $rendered = (new KimiConfigRenderer())->render($source, self::AGENTS_DIR, self::HOOKS);

        self::assertStringNotContainsString('evil', $rendered);
        self::assertStringNotContainsString('hooks.event', $rendered);
        self::assertStringNotContainsString('hooks.command', $rendered);
    }

    public function testATopLevelToolsDottedKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render("tools.disabled = [\"WebSearch\"]\n", self::AGENTS_DIR, self::HOOKS);
    }

    public function testATopLevelToolsInlineTableIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render("tools = { disabled = [\"WebSearch\"] }\n", self::AGENTS_DIR, self::HOOKS);
    }

    public function testAnUnparsableToolsDisabledValueThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render("[tools]\ndisabled = WebSearch\n", self::AGENTS_DIR, self::HOOKS);
    }

    /**
     * A run whose gate shim is not installed yet gets no hooks through the explicit entry point;
     * the rest of the bot's config (agents directory and disabled tools) is unchanged.
     */
    public function testRenderWithoutHooksAddsNoHookTables(): void
    {
        $rendered = (new KimiConfigRenderer())->renderWithoutHooks('', self::AGENTS_DIR);

        self::assertStringNotContainsString('[[hooks]]', $rendered);
        self::assertStringContainsString('extra_agent_dirs = ["'.self::AGENTS_DIR.'"]', $rendered);
        self::assertStringContainsString('[tools]', $rendered);
    }

    public function testAnEmptySourceStillYieldsTheBotsConfig(): void
    {
        $rendered = (new KimiConfigRenderer())->render('', self::AGENTS_DIR, self::HOOKS);

        self::assertStringContainsString('extra_agent_dirs = ["'.self::AGENTS_DIR.'"]', $rendered);
        self::assertStringContainsString(self::HOOKS, $rendered);
        self::assertStringContainsString('[tools]', $rendered);
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('unparsableConfigs')]
    public function testUnparsableInputThrows(array $segments): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render(implode("\n", $segments), self::AGENTS_DIR, self::HOOKS);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unparsableConfigs(): iterable
    {
        yield 'unclosed array' => [['key = [1, 2']];
        yield 'unclosed inline table' => [['key = { a = 1']];
        yield 'unterminated string' => [['key = "abc']];
        yield 'unclosed table header' => [['[providers']];
        yield 'text after a table header' => [['[providers] trailing']];
        yield 'a line that is neither a key nor a table' => [['this is not toml']];
        yield 'an empty key' => [['= 1']];
        yield 'garbage in a dropped table' => [['[[hooks]]', 'not toml']];
        yield 'an unclosed value before the end' => [['key = [', '"[1]"']];
    }

    public function testMissingArgumentsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render('', '', self::HOOKS);
    }

    public function testEmptyHooksAreRejectedSoAGateCannotDisappear(): void
    {
        $this->expectException(\RuntimeException::class);

        (new KimiConfigRenderer())->render('', self::AGENTS_DIR, '');
    }

    public function testTheRenderedConfigIsValidAccordingToKimi(): void
    {
        $kimi = self::findBinary('kimi');
        if ($kimi === null) {
            self::markTestSkipped('The `kimi` binary is not installed.');
        }

        $root = sys_get_temp_dir().'/kimi-doctor-'.uniqid('', true);
        mkdir($root.'/agents', 0o700, true);
        mkdir($root.'/bin', 0o700);
        $shim = $root.'/bin/kimi-hook.php';
        file_put_contents($shim, '<?php');
        $path = $root.'/config.toml';
        try {
            file_put_contents(
                $path,
                (new KimiConfigRenderer())->render(
                    (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/config.toml'),
                    $root.'/agents',
                    (new KimiHooksRenderer($shim))->render(),
                ),
            );
            $env = getenv();
            $env['KIMI_CODE_NO_AUTO_UPDATE'] = '1';
            $process = proc_open([$kimi, 'doctor', 'config', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            self::assertSame(0, $code, $stdout.$stderr);
        } finally {
            @unlink($path);
            @unlink($shim);
            @rmdir($root.'/agents');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    private function renderFixture(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/config.toml');

        return (new KimiConfigRenderer())->render($source, self::AGENTS_DIR, self::HOOKS);
    }

    private static function findBinary(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/').'/'.$name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
