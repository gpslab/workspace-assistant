<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Reader of the front matter of a markdown agent file (the Claude Code agent format).
 *
 * The bot has no runtime dependencies, so this reads the subset an agent file uses rather than
 * parsing YAML as a whole:
 *
 * - `key: value`, with the value optionally in single or double quotes;
 * - the block scalars `>` (folded) and `|` (literal), with the optional chomping and indentation
 *   indicators (`>-`, `|-`, `>2`, ...), which is how a long `description:` is written;
 * - `tools:` as a comma-separated scalar (`A, B`), as a YAML flow sequence (`[A, B]`) or as a YAML
 *   block sequence (`- A` / `- B` lines indented under the key).
 *
 * Only `name`, `description`, `model` and `tools` are read; every other key is skipped, together
 * with its block. A file with no front matter has only a body, and a value this reader does not
 * understand never silently becomes another key's value. Trailing line breaks of a block scalar are
 * always dropped: every key read here is used as one value, where they carry no meaning.
 */
final class AgentFrontMatter
{
    /** Keys the bot reads; anything else in the front matter is ignored. */
    private const array KEYS = ['name', 'description', 'model', 'tools'];

    /**
     * Splits a markdown agent file into front matter keys and body; a file without front matter has only a body.
     *
     * @return array{name?: string, description?: string, model?: string, tools?: list<string>, body: string}
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (preg_match('/\A---\n(.*?)\n---(?:\n(.*))?\z/s', $text, $m) !== 1) {
            return ['body' => $text];
        }
        $out = ['body' => $m[2] ?? ''];
        $lines = explode("\n", $m[1]);
        for ($i = 0, $count = count($lines); $i < $count; ++$i) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):[ \t]*(.*)$/', $lines[$i], $kv) !== 1) {
                continue;
            }
            $key = $kv[1];
            if (!in_array($key, self::KEYS, true)) {
                continue;
            }
            $value = rtrim($kv[2]);
            $isBlock = false;
            if (preg_match('/^([>|])(?:[1-9][+-]?|[+-][1-9]?)?$/', $value, $block) === 1) {
                [$blockLines, $i] = self::blockLines($lines, $i + 1);
                $value = self::blockScalar($blockLines, $block[1] === '>');
                $isBlock = true;
            } else {
                $value = self::unquote($value);
            }
            if ($key === 'tools') {
                if ($value === '' && !$isBlock) {
                    // An empty `tools:` may open a block sequence (`- Read` / `- Grep`) instead of
                    // an empty list; the helper consumes the items when the next line starts one.
                    [$items, $i] = self::blockSequence($lines, $i + 1);
                    $out['tools'] = $items;
                } else {
                    $out['tools'] = self::tools($value);
                }
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The lines of a block scalar: every following line that is indented, blank lines included.
     *
     * @param list<string> $lines
     *
     * @return array{0: list<string>, 1: int} the block lines and the index of the last line consumed
     */
    private static function blockLines(array $lines, int $start): array
    {
        $block = [];
        for ($i = $start, $count = count($lines); $i < $count; ++$i) {
            if ($lines[$i] !== '' && preg_match('/^[ \t]/', $lines[$i]) !== 1) {
                break;
            }
            $block[] = $lines[$i];
        }

        return [$block, max($start - 1, $i - 1)];
    }

    /**
     * A block scalar as one string: the indentation of its first line is removed, `>` folds the
     * lines into a single paragraph and `|` keeps every line break.
     *
     * @param list<string> $lines
     */
    private static function blockScalar(array $lines, bool $folded): string
    {
        $indent = null;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $indent = strspn($line, " \t");

                break;
            }
        }
        if ($indent === null) {
            return '';
        }
        $values = [];
        foreach ($lines as $line) {
            $values[] = trim($line) === '' ? '' : (string) preg_replace('/^[ \t]{0,'.$indent.'}/', '', $line);
        }
        $text = $values[0];
        foreach (array_slice($values, 1) as $index => $line) {
            if ($line === '') {
                // A blank line ends the paragraph: it becomes a line break, not a space.
                $text .= "\n";
            } elseif ($values[$index] === '') {
                $text .= $line;
            } else {
                $text .= ' '.$line;
            }
        }

        return rtrim($folded ? $text : implode("\n", $values), "\n");
    }

    /**
     * The items of a YAML block sequence under a `tools:` key: consecutive `- item` lines at any
     * indentation, including none (`- Read`). Each item is trimmed and unquoted. Returns an empty
     * list, leaving the index on the `tools:` line, when the next line does not start an item. A
     * key of the main loop starts with a letter, so an unindented item cannot be mistaken for one.
     *
     * @param list<string> $lines
     * @param int          $start index of the first line after the `tools:` key
     *
     * @return array{0: list<string>, 1: int} the items and the index of the last line consumed
     */
    private static function blockSequence(array $lines, int $start): array
    {
        $items = [];
        for ($i = $start, $count = count($lines); $i < $count; ++$i) {
            if (preg_match('/^[ \t]*-[ \t]*(.*)$/', $lines[$i], $m) !== 1) {
                break;
            }
            $item = trim(self::unquote(trim($m[1])));
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return [$items, $i - 1];
    }

    /**
     * A `tools:` list: a comma-separated scalar (`A, B`) or a flow sequence (`[A, B]`), trimmed,
     * with each entry unquoted and without empty entries.
     *
     * @return list<string>
     */
    private static function tools(string $value): array
    {
        $value = trim($value);
        // A flow sequence: strip the wrapping brackets so the entries split on the commas.
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $value = substr($value, 1, -1);
        }
        $tools = [];
        foreach (explode(',', $value) as $tool) {
            $tool = trim(self::unquote(trim($tool)));
            if ($tool !== '') {
                $tools[] = $tool;
            }
        }

        return array_values(array_unique($tools));
    }

    /** A scalar without its surrounding quotes; an unpaired quote is part of the value. */
    private static function unquote(string $value): string
    {
        $first = $value[0] ?? '';
        if (strlen($value) >= 2 && ($first === '"' || $first === "'") && str_ends_with($value, $first)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
