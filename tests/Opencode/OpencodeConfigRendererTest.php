<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\Opencode\OpencodeAgent;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeConfigRenderer;
use GpsLab\WorkspaceAssistant\Opencode\ToolPermissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpencodeConfigRenderer::class)]
#[CoversClass(OpencodeAgent::class)]
#[CoversClass(ToolPermissions::class)]
final class OpencodeConfigRendererTest extends TestCase
{
    private const string PROMPT_PATH = '/state/opencode-run-1/system-prompt.md';
    private const string REPO = '/repo';

    public function testSystemPromptFileCarriesTheAssembledPrompt(): void
    {
        $files = $this->render();

        self::assertSame('the system prompt', $files[OpencodeConfigRenderer::SYSTEM_PROMPT_FILE]);
    }

    public function testInstructionsAlwaysContainTheSystemPromptFile(): void
    {
        $files = $this->render();

        self::assertSame([self::PROMPT_PATH], $this->opencodeJson($files)['instructions']);
    }

    /**
     * @param bool $included whether the instructions are expected to contain <repo>/CLAUDE.md
     */
    #[DataProvider('claudeMdCases')]
    public function testClaudeMdIsAddedOnlyWhenPresentAndAgentsMdIsAbsent(bool $hasClaudeMd, bool $hasAgentsMd, bool $included): void
    {
        $files = $this->render(hasClaudeMd: $hasClaudeMd, hasAgentsMd: $hasAgentsMd);

        $instructions = $this->opencodeJson($files)['instructions'];
        self::assertSame(self::PROMPT_PATH, $instructions[0]);
        self::assertSame($included, in_array(self::REPO.'/CLAUDE.md', $instructions, true));
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function claudeMdCases(): iterable
    {
        yield 'claude present, agents present -> omitted' => [true, true, false];
        yield 'claude present, agents absent -> included' => [true, false, true];
        yield 'claude absent, agents present -> omitted' => [false, true, false];
        yield 'claude absent, agents absent -> omitted' => [false, false, false];
    }

    public function testAgentRendersModeDescriptionAndBodyAsSystem(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('critic', 'Reviews things', 'You are a critic.', ['Read'])]);

        self::assertSame(
            "---\nmode: subagent\ndescription: \"Reviews things\"\n---\n\nYou are a critic.\n",
            $files['agents/critic.md'],
        );
    }

    public function testBuiltinAndOverrideAgentsAreBothRendered(): void
    {
        $files = $this->render(
            builtin: [new OpencodeAgent('critic', 'c', 'c-body', ['Read'])],
            override: [new OpencodeAgent('patcher', 'p', 'p-body', ['Write', 'Edit'])],
        );

        self::assertStringContainsString('c-body', $files['agents/critic.md']);
        self::assertStringContainsString('p-body', $files['agents/patcher.md']);
    }

    public function testOverrideShadowsABuiltinOfTheSameName(): void
    {
        $files = $this->render(
            builtin: [new OpencodeAgent('critic', 'built-in', 'built-in body', ['Read'])],
            override: [new OpencodeAgent('critic', 'override', 'override body', ['Write', 'Edit'])],
        );

        self::assertStringContainsString('override body', $files['agents/critic.md']);
        self::assertStringNotContainsString('built-in body', $files['agents/critic.md']);

        // The override's tools, not the built-in's, must drive the permissions: deny-* + allow-edit,
        // with no read allow from the shadowed built-in.
        self::assertSame(
            [
                ['action' => '*', 'resource' => '*', 'effect' => 'deny'],
                ['action' => 'edit', 'resource' => '*', 'effect' => 'allow'],
            ],
            $this->explicitJson($files)['agents']['critic']['permissions'],
        );
    }

    public function testAnOverrideNameWithPathTraversalIsSkipped(): void
    {
        $files = $this->render(override: [new OpencodeAgent('../system-prompt', 'd', 'ATTACKER PROMPT', ['Bash'])]);

        // No file is rendered outside agents/, and the base artefacts are untouched.
        self::assertSame(
            [OpencodeConfigRenderer::SYSTEM_PROMPT_FILE, 'opencode.json', 'explicit.json'],
            array_keys($files),
        );
        self::assertSame('the system prompt', $files[OpencodeConfigRenderer::SYSTEM_PROMPT_FILE]);
    }

    public function testAgentPermissionsAreRenderedIntoExplicitJson(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('critic', 'c', 'body', ['Read', 'Grep', 'Glob', 'Bash'])]);

        $explicit = $this->explicitJson($files);
        self::assertSame(['critic'], array_keys($explicit['agents']));
        self::assertSame([
            ['action' => '*', 'resource' => '*', 'effect' => 'deny'],
            ['action' => 'read', 'resource' => '*', 'effect' => 'allow'],
            ['action' => 'grep', 'resource' => '*', 'effect' => 'allow'],
            ['action' => 'glob', 'resource' => '*', 'effect' => 'allow'],
            ['action' => 'shell', 'resource' => '*', 'effect' => 'allow'],
        ], $explicit['agents']['critic']['permissions']);
    }

    public function testANumericNamedOverrideIsSkippedAndAgentsStaysAnObject(): void
    {
        $files = $this->render(override: [new OpencodeAgent('0', 'd', 'body', ['Bash'])]);

        // A numeric name is rejected and never rendered, and `agents` encodes as an object (not
        // a JSON list, which would silently drop the name -> permissions binding).
        self::assertArrayNotHasKey('agents/0.md', $files);
        self::assertStringContainsString('"agents":{}', $files['explicit.json']);
    }

    public function testPermissionsStayOutOfOpencodeJson(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('critic', 'c', 'body', ['Read'])]);

        $config = json_decode($files['opencode.json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        self::assertSame(['instructions'], array_keys($config));
    }

    public function testAGatedRunListsTheGateShimInThePlugins(): void
    {
        $files = $this->render(gatePlugin: '/bot/opencode-plugin');

        $explicit = $this->explicitJson($files);
        self::assertArrayHasKey('plugins', $explicit);
        self::assertSame(['/bot/opencode-plugin'], $explicit['plugins']);
    }

    public function testARunWithNoGateListsNoPlugins(): void
    {
        $files = $this->render();

        self::assertArrayNotHasKey('plugins', $this->explicitJson($files));
    }

    public function testEditingToolsWithoutShellDenyTheShell(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('patcher', 'p', 'body', ['Read', 'Grep', 'Glob', 'Write', 'Edit'])]);

        $rules = $this->explicitJson($files)['agents']['patcher']['permissions'];
        self::assertSame(['action' => '*', 'resource' => '*', 'effect' => 'deny'], $rules[0]);
        self::assertContains(['action' => 'edit', 'resource' => '*', 'effect' => 'allow'], $rules);
        self::assertNotContains(['action' => 'shell', 'resource' => '*', 'effect' => 'allow'], $rules);
    }

    public function testAToolAbsentFromTheListIsDenied(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('editor', 'e', 'body', ['Read', 'Grep', 'Glob'])]);

        $rules = $this->explicitJson($files)['agents']['editor']['permissions'];
        self::assertSame(['action' => '*', 'resource' => '*', 'effect' => 'deny'], $rules[0]);
        self::assertNotContains(['action' => 'shell', 'resource' => '*', 'effect' => 'allow'], $rules);
        self::assertNotContains(['action' => 'edit', 'resource' => '*', 'effect' => 'allow'], $rules);
    }

    public function testAnUnknownToolNameGrantsNothing(): void
    {
        $rules = ToolPermissions::rules(['Mystery']);

        self::assertSame([['action' => '*', 'resource' => '*', 'effect' => 'deny']], $rules);
    }

    public function testWriteAndEditBothMapToTheSingleEditAction(): void
    {
        $rules = ToolPermissions::rules(['Write', 'Edit']);

        self::assertSame(
            [
                ['action' => '*', 'resource' => '*', 'effect' => 'deny'],
                ['action' => 'edit', 'resource' => '*', 'effect' => 'allow'],
            ],
            $rules,
        );
    }

    public function testNoPerAgentModelIsRendered(): void
    {
        $files = $this->render(builtin: [new OpencodeAgent('critic', 'c', 'body', ['Read'])]);

        self::assertStringNotContainsString('model', $files['agents/critic.md']);
        self::assertArrayNotHasKey('model', $this->explicitJson($files)['agents']['critic']);
    }

    public function testTheMapContainsEveryArtefact(): void
    {
        $files = $this->render(
            builtin: [
                new OpencodeAgent('critic', 'c', 'c-body', ['Read']),
                new OpencodeAgent('verifier', 'v', 'v-body', ['Read']),
            ],
        );

        self::assertSame(
            [
                OpencodeConfigRenderer::SYSTEM_PROMPT_FILE,
                'opencode.json',
                'explicit.json',
                'agents/critic.md',
                'agents/verifier.md',
            ],
            array_keys($files),
        );
    }

    /**
     * @param list<OpencodeAgent> $builtin
     * @param list<OpencodeAgent> $override
     *
     * @return array<string, string>
     */
    private function render(
        array $builtin = [],
        array $override = [],
        bool $hasClaudeMd = false,
        bool $hasAgentsMd = false,
        ?string $gatePlugin = null,
    ): array {
        return (new OpencodeConfigRenderer(
            'the system prompt',
            self::PROMPT_PATH,
            $builtin,
            $override,
            self::REPO,
            $hasClaudeMd,
            $hasAgentsMd,
            $gatePlugin,
        ))->render();
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{instructions: list<string>}
     */
    private function opencodeJson(array $files): array
    {
        /** @var array{instructions: list<string>} $data */
        $data = json_decode($files['opencode.json'], true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{agents: array<string, array{permissions: list<array{action: string, resource: string, effect: string}>}>, plugins?: list<string>}
     */
    private function explicitJson(array $files): array
    {
        /** @var array{agents: array<string, array{permissions: list<array{action: string, resource: string, effect: string}>}>, plugins?: list<string>} $data */
        $data = json_decode($files['explicit.json'], true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
