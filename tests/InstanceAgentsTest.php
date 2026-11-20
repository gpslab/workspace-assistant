<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\AgentFrontMatter;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\InstanceAgents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstanceAgents::class)]
#[CoversClass(AgentFrontMatter::class)]
final class InstanceAgentsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/instance-agents-'.uniqid('', true);
        mkdir($this->root.'/repo/.claude/agents', 0o700, true);
        mkdir($this->root.'/home/.claude/agents', 0o700, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    public function testAFoldedDescriptionAndACommaSeparatedToolListAreRead(): void
    {
        file_put_contents(
            $this->root.'/repo/.claude/agents/mine.md',
            "---\nname: critic\ndescription: >\n  Judges the draft against the plan\n  and the acceptance criteria.\ntools: Read, Grep\nmodel: opus\n---\nBody line.\n",
        );

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame(['critic'], array_keys($agents));
        self::assertSame('Judges the draft against the plan and the acceptance criteria.', $agents['critic']['description']);
        self::assertSame(['Read', 'Grep'], $agents['critic']['tools']);
        self::assertSame("Body line.\n", $agents['critic']['prompt']);
        self::assertSame($this->root.'/repo/.claude/agents/mine.md', $agents['critic']['path']);
    }

    public function testALiteralBlockScalarKeepsItsLineBreaksAndAFoldedOneItsParagraphs(): void
    {
        file_put_contents(
            $this->root.'/repo/.claude/agents/literal.md',
            "---\nname: literal\ndescription: |\n  first line\n  second line\n---\nbody\n",
        );
        file_put_contents(
            $this->root.'/repo/.claude/agents/folded.md',
            "---\nname: folded\ndescription: >-\n  one paragraph\n\n  another one\n---\nbody\n",
        );

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame("first line\nsecond line", $agents['literal']['description']);
        self::assertSame("one paragraph\nanother one", $agents['folded']['description']);
    }

    public function testAnAgentWithoutAToolsKeyIsUnrestricted(): void
    {
        file_put_contents($this->root.'/repo/.claude/agents/free.md', "---\nname: free\ndescription: d\n---\nbody\n");

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertNull($agents['free']['tools']);
    }

    public function testAnEmptyToolsListIsARestrictionToNothing(): void
    {
        file_put_contents($this->root.'/repo/.claude/agents/none.md', "---\nname: none\ntools:\n---\nbody\n");

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame([], $agents['none']['tools']);
    }

    public function testTheNeutralDirectoryIsReadOnOpencodeOnly(): void
    {
        mkdir($this->root.'/repo/.agents/agents', 0o700, true);
        file_put_contents($this->root.'/repo/.agents/agents/critic.md', "---\nname: critic\ndescription: mine\n---\nbody\n");

        // Claude Code never reads `.agents/agents`, so on that engine the file must not count as the
        // instance's agent: the built-in one is what the run gets.
        self::assertSame([], $this->load(Config::DEFAULT_ENGINE_FINGERPRINT));
        self::assertArrayHasKey('critic', $this->load(Config::ENGINE_OPENCODE));
    }

    public function testASymlinkedAgentsDirectoryIsRead(): void
    {
        $repo = $this->root.'/linked-repo';
        mkdir($repo.'/.claude', 0o700, true);
        mkdir($this->root.'/shared-agents', 0o700, true);
        file_put_contents($this->root.'/shared-agents/linked.md', "---\nname: critic\ndescription: linked\n---\nbody\n");
        symlink($this->root.'/shared-agents', $repo.'/.claude/agents');

        $agents = InstanceAgents::load($repo, $this->root.'/home', Config::DEFAULT_ENGINE_FINGERPRINT);

        self::assertArrayHasKey('critic', $agents);
        self::assertSame('linked', $agents['critic']['description']);
        self::assertSame($repo.'/.claude/agents/linked.md', $agents['critic']['path']);
    }

    public function testASymlinkedAgentFileIsRead(): void
    {
        file_put_contents($this->root.'/target.md', "---\nname: critic\ndescription: linked\n---\nbody\n");
        symlink($this->root.'/target.md', $this->root.'/repo/.claude/agents/critic.md');

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame('linked', $agents['critic']['description']);
    }

    public function testTheRepoClaudeDirectoryWinsOverTheOtherTwoAndTheRepoOverTheHome(): void
    {
        mkdir($this->root.'/repo/.agents/agents', 0o700, true);
        file_put_contents($this->root.'/home/.claude/agents/critic.md', "---\nname: critic\ndescription: home\n---\nbody\n");
        file_put_contents($this->root.'/repo/.agents/agents/critic.md', "---\nname: critic\ndescription: neutral\n---\nbody\n");
        file_put_contents($this->root.'/repo/.claude/agents/critic.md', "---\nname: critic\ndescription: claude\n---\nbody\n");

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame('claude', $agents['critic']['description']);
    }

    public function testTheHomeAgentsAreReadOnBothEngines(): void
    {
        file_put_contents($this->root.'/home/.claude/agents/mine.md', "---\nname: verifier\ndescription: home\n---\nbody\n");

        self::assertArrayHasKey('verifier', $this->load(Config::DEFAULT_ENGINE_FINGERPRINT));
        self::assertArrayHasKey('verifier', $this->load(Config::ENGINE_OPENCODE));
    }

    public function testAFileWithoutAUsableNameIsIgnored(): void
    {
        file_put_contents($this->root.'/repo/.claude/agents/one.md', "---\nname: \"\"\ndescription: d\n---\nbody\n");
        file_put_contents($this->root.'/repo/.claude/agents/two.md', "no frontmatter at all\n");

        self::assertSame([], $this->load(Config::ENGINE_OPENCODE));
    }

    public function testAQuotedNameLosesItsQuotesAndAnUnknownKeyDoesNotSwallowTheNextOne(): void
    {
        file_put_contents(
            $this->root.'/repo/.claude/agents/mine.md',
            "---\nname: \"acceptor\"\nother: >\n  ignored block\n  of another key\ntools: Read\n---\nbody\n",
        );

        $agents = $this->load(Config::ENGINE_OPENCODE);

        self::assertSame(['acceptor'], array_keys($agents));
        self::assertSame(['Read'], $agents['acceptor']['tools']);
    }

    public function testDirectoriesArePerEngineInPrecedenceOrder(): void
    {
        self::assertSame(
            [$this->root.'/home/.claude/agents', $this->root.'/repo/.claude/agents'],
            InstanceAgents::directories($this->root.'/repo', $this->root.'/home', Config::DEFAULT_ENGINE_FINGERPRINT),
        );
        self::assertSame(
            [$this->root.'/home/.claude/agents', $this->root.'/repo/.agents/agents', $this->root.'/repo/.claude/agents'],
            InstanceAgents::directories($this->root.'/repo', $this->root.'/home', Config::ENGINE_OPENCODE),
        );
        self::assertSame(
            [$this->root.'/repo/.claude/agents'],
            InstanceAgents::directories($this->root.'/repo', '', Config::DEFAULT_ENGINE_FINGERPRINT),
        );
    }

    /**
     * @return array<string, array{description: string, prompt: string, tools: ?list<string>, path: string}>
     */
    private function load(string $engine): array
    {
        return InstanceAgents::load($this->root.'/repo', $this->root.'/home', $engine);
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) && !is_link($f) ? self::rrmdir($f) : unlink($f);
        }
        rmdir($dir);
    }
}
