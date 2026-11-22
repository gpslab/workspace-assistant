<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\BuiltinAgents;
use GpsLab\WorkspaceAssistant\Kimi\KimiAgentsRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiAgentsRenderer::class)]
#[CoversClass(BuiltinAgents::class)]
final class KimiAgentsRendererTest extends TestCase
{
    private string $root;
    private string $repo;
    private string $home;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-agents-'.uniqid('', true);
        $this->repo = $this->root.'/repo';
        $this->home = $this->root.'/home';
        mkdir($this->repo.'/.claude/agents', 0o700, true);
        mkdir($this->repo.'/.agents/agents', 0o700, true);
        mkdir($this->home.'/.claude/agents', 0o700, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    public function testAllBuiltinAgentsAreRenderedWithTheirClaudeTools(): void
    {
        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertSame(['acceptor', 'critic', 'editor', 'scope-check', 'security', 'verifier'], array_keys($files));
        foreach (BuiltinAgents::TOOLS as $name => $tools) {
            self::assertStringContainsString('name: '.$name, $files[$name]);
            self::assertStringContainsString("tools:\n  - \"".implode("\"\n  - \"", $tools)."\"\n", $files[$name]);
            self::assertStringNotContainsString('model:', $files[$name]);
            self::assertNotSame('', trim($files[$name]));
        }
    }

    public function testEveryBuiltinToolNameIsInKimisRecordedToolList(): void
    {
        /** @var list<array{name: string}> $tools */
        $tools = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/kimi/tools.json'), true, 512, JSON_THROW_ON_ERROR);
        $names = array_column($tools, 'name');

        foreach (BuiltinAgents::TOOLS as $agent => $agentTools) {
            foreach ($agentTools as $tool) {
                self::assertContains($tool, $names, $agent.' uses a tool Kimi does not have: '.$tool);
            }
        }
    }

    public function testAnInstanceAgentShadowsABuiltinOfTheSameName(): void
    {
        $this->agent($this->repo.'/.claude/agents/mine.md', 'critic', 'instance critic body');

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertStringContainsString('instance critic body', $files['critic']);
        self::assertStringNotContainsString('You are a cold critic.', $files['critic']);
    }

    public function testAHomeAgentShadowsABuiltinOfTheSameName(): void
    {
        $this->agent($this->home.'/.claude/agents/mine.md', 'verifier', 'home verifier body');

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertStringContainsString('home verifier body', $files['verifier']);
    }

    public function testTheNeutralAgentsDirectoryIsNotCopiedBecauseKimiReadsItItself(): void
    {
        $this->agent($this->repo.'/.agents/agents/critic.md', 'critic', 'neutral critic body');
        $this->agent($this->repo.'/.agents/agents/neutral-only.md', 'neutral-only', 'neutral only body');

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertStringNotContainsString('neutral critic body', $files['critic']);
        self::assertStringContainsString('You are a cold critic.', $files['critic']);
        self::assertArrayNotHasKey('neutral-only', $files);
    }

    public function testANewInstanceAgentIsAddedAndModelAndFileNameAreIgnored(): void
    {
        file_put_contents(
            $this->repo.'/.claude/agents/any-file-name.md',
            "---\nname: mine\ndescription: My agent\nmodel: opus\ntools: Read, Grep\n---\nmy body\n",
        );

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertArrayHasKey('mine', $files);
        self::assertStringContainsString('name: mine', $files['mine']);
        self::assertStringContainsString('description: "My agent"', $files['mine']);
        self::assertStringContainsString("tools:\n  - \"Read\"\n  - \"Grep\"\n", $files['mine']);
        self::assertStringNotContainsString('model:', $files['mine']);
        self::assertStringEndsWith("\n\nmy body\n", $files['mine']);
    }

    public function testAnInstanceAgentWithoutToolsIsUnrestrictedAndHasNoToolsKey(): void
    {
        file_put_contents($this->repo.'/.claude/agents/mine.md', "---\nname: mine\ndescription: d\n---\nbody\n");

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertArrayHasKey('mine', $files);
        self::assertStringNotContainsString('tools:', $files['mine']);
    }

    public function testAnUnsafeAgentNameIsNotRendered(): void
    {
        $this->agent($this->repo.'/.claude/agents/evil.md', '../escape', 'attacker body');

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertArrayNotHasKey('../escape', $files);
        self::assertSame(['acceptor', 'critic', 'editor', 'scope-check', 'security', 'verifier'], array_keys($files));
    }

    public function testInstanceAgentToolsAreQuotedAsYamlScalars(): void
    {
        file_put_contents(
            $this->repo.'/.claude/agents/weird.md',
            "---\nname: mine\ndescription: d\ntools: [Bash, \"Bash(git commit: *)\", \"Grep #x\"]\n---\nbody\n",
        );

        $files = (new KimiAgentsRenderer())->render($this->repo, $this->home);

        self::assertArrayHasKey('mine', $files);
        self::assertStringContainsString(
            "tools:\n  - \"Bash\"\n  - \"Bash(git commit: *)\"\n  - \"Grep #x\"\n",
            $files['mine'],
        );
    }

    private function agent(string $path, string $name, string $body): void
    {
        file_put_contents($path, "---\nname: ".$name."\ndescription: d\n---\n".$body."\n");
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (in_array(basename($path), ['.', '..'], true)) {
                continue;
            }
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
