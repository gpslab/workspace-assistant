<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Renders the markdown file a Kimi run passes to `--agent-file`.
 *
 * Kimi has no flag that appends to its system prompt. The supported mechanism is an agent file
 * whose body starts with `${base_prompt}`: that token keeps Kimi's own base prompt, and the text
 * around it is added. The bot therefore renders `${base_prompt}` followed by its assembled prompt,
 * in the order the bot uses everywhere: the mode prompt ({@see \GpsLab\WorkspaceAssistant\SystemPrompt::build()}),
 * the memory of the instance when there is one (this engine reads no user scope, so the bot injects
 * it — see {@see \GpsLab\WorkspaceAssistant\Memory\MemoryContext}), the instance addition
 * (`CLAUDE_APPEND_SYSTEM_PROMPT`) and finally the chat-language line, which must stay last so that
 * it overrides an earlier persona or addition.
 *
 * Pure: it returns text and writes nothing.
 */
final class KimiMainAgentRenderer
{
    /** Name of the agent the bot presents to Kimi. */
    public const string NAME = 'workspace-assistant';

    /** Description of the agent the bot presents to Kimi. */
    public const string DESCRIPTION = 'Workspace Assistant: the bot persona and rules for the managed repository.';

    /**
     * @param string $systemPrompt       the mode prompt (SystemPrompt::build)
     * @param string $memory             the memory block of the instance (`MEMORY_3LAYER_BIN`), or ''
     * @param string $appendSystemPrompt the instance addition (CLAUDE_APPEND_SYSTEM_PROMPT), or ''
     * @param string $languageLine       the chat-language line, or ''
     * @param string $name               the agent name in the front matter
     * @param string $description        the agent description in the front matter
     *
     * @throws \RuntimeException when the name is not one safe agent name
     */
    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $memory = '',
        private readonly string $appendSystemPrompt = '',
        private readonly string $languageLine = '',
        private readonly string $name = self::NAME,
        private readonly string $description = self::DESCRIPTION,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/i', $this->name) !== 1) {
            throw new \RuntimeException('Invalid Kimi main agent name: '.$this->name.'.');
        }
    }

    public function render(): string
    {
        $parts = [];
        foreach ([$this->systemPrompt, self::neutralizeMemoryTokens($this->memory), $this->appendSystemPrompt, $this->languageLine] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        // `${base_prompt}` is literal: Kimi substitutes it, the bot must not.
        $body = '${base_prompt}';
        if ($parts !== []) {
            $body .= "\n\n".implode("\n\n", $parts);
        }

        return "---\nname: ".$this->name."\ndescription: ".self::yamlString($this->description)."\n---\n\n".$body."\n";
    }

    /** A YAML double-quoted string: backslashes, double quotes and newlines are escaped. */
    private static function yamlString(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }

    /**
     * The memory of the instance with its `${name}` tokens broken so Kimi's renderPrompt does not
     * substitute them.
     *
     * Kimi substitutes `${name}` over the whole agent-file body, the memory included, so a memory file
     * that happens to mention `${base_prompt}` or `${cwd}` would be silently rewritten (and the former
     * would insert Kimi's whole base prompt). The memory is data, not a template: a zero-width space
     * after the `$` defeats the substitution regex while the text still reads as the original. A
     * zero-width space is invisible to a human reading the file.
     */
    private static function neutralizeMemoryTokens(string $memory): string
    {
        if ($memory === '' || !str_contains($memory, '${')) {
            return $memory;
        }

        return str_replace('${', "$\u{200B}{", $memory);
    }
}
