<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiMainAgentRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiMainAgentRenderer::class)]
final class KimiMainAgentRendererTest extends TestCase
{
    public function testTheBodyKeepsTheBasePromptAndAppendsTheAssembledPromptInOrder(): void
    {
        $rendered = (new KimiMainAgentRenderer('mode prompt', '', 'instance addition', 'language line'))->render();

        self::assertStringContainsString('name: workspace-assistant', $rendered);
        self::assertStringContainsString('description: "'.KimiMainAgentRenderer::DESCRIPTION.'"', $rendered);
        self::assertStringStartsWith("---\nname: workspace-assistant\ndescription: ", $rendered);
        self::assertStringContainsString('${base_prompt}', $rendered);
        self::assertStringContainsString("\${base_prompt}\n\nmode prompt\n\ninstance addition\n\nlanguage line\n", $rendered);
    }

    /**
     * The memory of the instance goes right after the mode prompt and before the instance addition: the
     * order the bot uses on every engine that injects it (OpenCode and Kimi).
     */
    public function testTheMemoryOfTheInstanceFollowsTheModePrompt(): void
    {
        $rendered = (new KimiMainAgentRenderer('mode prompt', 'memory block', 'instance addition', 'language line'))->render();

        self::assertStringContainsString(
            "\${base_prompt}\n\nmode prompt\n\nmemory block\n\ninstance addition\n\nlanguage line\n",
            $rendered,
        );
    }

    public function testTheLanguageLineIsLastEvenWhenTheOtherPartsAreEmpty(): void
    {
        $rendered = (new KimiMainAgentRenderer('', '', '', 'language line'))->render();

        self::assertStringContainsString("\${base_prompt}\n\nlanguage line\n", $rendered);
    }

    public function testOnlyTheBasePromptWhenNothingIsAssembled(): void
    {
        $rendered = (new KimiMainAgentRenderer(''))->render();

        self::assertStringEndsWith("---\n\n\${base_prompt}\n", $rendered);
    }

    public function testTheDescriptionIsEscapedAsYaml(): void
    {
        $rendered = (new KimiMainAgentRenderer('prompt', '', '', '', 'other-agent', 'a "quoted" \\ description'))->render();

        self::assertStringContainsString('name: other-agent', $rendered);
        self::assertStringContainsString('description: "a \\"quoted\\" \\\\ description"', $rendered);
    }

    public function testAnUnsafeNameIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        new KimiMainAgentRenderer('prompt', '', '', '', '../escape');
    }
}
