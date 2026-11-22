<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Builds the text of a Kimi run's `config.toml` from the user's own Kimi configuration.
 *
 * A run may not inherit the user's configuration unchanged: the user's `[[hooks]]` would run inside
 * a bot run, and their `extra_agent_dirs` / `extra_skill_dirs` would point at directories the run
 * does not own. The filter is line based (the bot has no runtime dependencies, so no TOML library)
 * and fail-closed: a construct it cannot parse is an error, never a pass-through that would keep a
 * user hook in a bot run.
 *
 * It keeps the user's provider and model tables, their comments, multi-line arrays, inline tables
 * and `[[permission.rules]]` (the static rules stay in effect in `-p`). It removes every `hooks`
 * table or top-level `hooks.*` key and the top-level `extra_agent_dirs` / `extra_skill_dirs` keys,
 * then adds the bot's own: one rendered agents directory, its session-start and pre-tool-use hook
 * tables, and a `[tools]` table that disables the cron and question tools (a pending cron keeps
 * `-p` alive). A `[tools]` table of the user is kept and its `disabled` list is merged with the
 * bot's, because the bot owns the run's tool policy; a top-level `tools.*` key is rejected.
 *
 * Pure: it returns text and writes nothing.
 */
final class KimiConfigRenderer
{
    /**
     * Top-level keys and tables of a Kimi config that belong to the user's environment, not to a
     * run. Written as a table header (`[[hooks]]`) or as a top-level key (`hooks = [...]`,
     * `hooks.x = ...`) the whole construct is removed.
     */
    private const array DROPPED_TABLES = ['hooks', 'extra_agent_dirs', 'extra_skill_dirs'];

    /** Kimi's tool list minus the bot's: a pending cron or question would outlive a `-p` run. */
    private const array DISABLED_TOOLS = ['CronCreate', 'CronDelete', 'CronList', 'AskUserQuestion'];

    /**
     * @param string $source     the text of the user's source `config.toml`
     * @param string $agentsDir  absolute path of the rendered agents directory of the run
     * @param string $hookTables the rendered `[[hooks]]` tables ({@see KimiHooksRenderer})
     *
     * @throws \RuntimeException when the source cannot be parsed, or an argument would render an
     *                           invalid or unsafe config
     */
    public function render(string $source, string $agentsDir, string $hookTables): string
    {
        if (trim($agentsDir) === '') {
            throw new \RuntimeException('The agents directory of a Kimi run must not be empty.');
        }
        if (trim($hookTables) === '') {
            throw new \RuntimeException('The hook tables of a Kimi run must not be empty.');
        }

        $lines = self::lines($source);
        $out = [];
        $index = 0;
        $count = count($lines);
        /** @var list<string>|null $table the path of the table the current line is in, null at the top level */
        $table = null;
        $sawToolsTable = false;
        $toolsDisabledPending = false;
        /** @var list<string> $userDisabledTools the user's `[tools] disabled` list, merged with the bot's */
        $userDisabledTools = [];
        $sawDisabledKey = false;

        while ($index < $count) {
            $line = $lines[$index];
            if (self::isBlankOrComment($line)) {
                $out[] = $line;
                ++$index;

                continue;
            }

            if (self::isHeader($line)) {
                [, $path] = self::parseHeader($line);
                if ($table === ['tools']) {
                    self::flushToolsDisabled($out, $toolsDisabledPending, $userDisabledTools);
                }
                $root = $path[0];
                if (in_array($root, self::DROPPED_TABLES, true)) {
                    $table = null;
                    $index = self::skipTable($lines, $index + 1);

                    continue;
                }
                if ($path === ['tools']) {
                    if ($sawToolsTable) {
                        throw new \RuntimeException('The source config.toml defines the [tools] table more than once.');
                    }
                    $sawToolsTable = true;
                    $toolsDisabledPending = true;
                    $userDisabledTools = [];
                    $sawDisabledKey = false;
                }
                $out[] = $line;
                $table = $path;
                ++$index;

                continue;
            }

            $root = self::keyRoot($line);
            $end = self::statementEnd($lines, $index);
            if ($table === null && in_array($root, self::DROPPED_TABLES, true)) {
                $index = $end + 1;

                continue;
            }
            if ($table === null && $root === 'tools') {
                throw new \RuntimeException('The source config.toml sets the tools table with a top-level key; write it as a [tools] table or remove it.');
            }
            if ($table === ['tools'] && $root === 'disabled') {
                if ($sawDisabledKey) {
                    throw new \RuntimeException('The source config.toml defines the [tools] disabled key more than once.');
                }
                $userDisabledTools = self::parseStringArray(self::valueText($lines, $index, $end));
                $sawDisabledKey = true;
                $index = $end + 1;

                continue;
            }
            for ($i = $index; $i <= $end; ++$i) {
                $out[] = $lines[$i];
            }
            $index = $end + 1;
        }
        self::flushToolsDisabled($out, $toolsDisabledPending, $userDisabledTools);

        // A top-level key must appear before the first table: once a table starts, later keys
        // belong to it. The bot's agents directory is therefore rendered ahead of the source.
        $rendered = "# Workspace Assistant: the user's hooks and extra agent/skill directories are removed;\n"
            ."# the bot's agents directory wins over \$HOME/.agents/agents for the same agent name.\n"
            .'extra_agent_dirs = ['.self::string($agentsDir).']'."\n\n";
        $body = rtrim(implode("\n", $out), "\n");
        if ($body !== '') {
            $rendered .= $body."\n";
        }
        $rendered .= "\n".rtrim($hookTables, "\n")."\n";
        if (!$sawToolsTable) {
            $rendered .= "\n[tools]\ndisabled = ".self::disabledTools()."\n";
        }

        return $rendered;
    }

    /**
     * The lines of the source without the line terminator and without a byte-order mark. A source
     * with a final line break gains no empty trailing line, so a re-render is stable.
     *
     * @return list<string>
     */
    private static function lines(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }
        if ($source === '') {
            return [];
        }

        return explode("\n", rtrim($source, "\n"));
    }

    private static function isBlankOrComment(string $line): bool
    {
        $trimmed = ltrim($line);

        return $trimmed === '' || str_starts_with($trimmed, '#');
    }

    private static function isHeader(string $line): bool
    {
        return str_starts_with(ltrim($line), '[');
    }

    /**
     * A table header: the array-of-tables marker and the path segments, unquoted.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private static function parseHeader(string $line): array
    {
        $trimmed = ltrim($line);
        $isArray = str_starts_with($trimmed, '[[');
        $offset = $isArray ? 2 : 1;
        $closing = $isArray ? ']]' : ']';
        $length = strlen($trimmed);
        $quote = '';
        for ($i = $offset; $i < $length; ++$i) {
            $char = $trimmed[$i];
            if ($quote !== '') {
                if ($char === '\\' && $quote === '"') {
                    ++$i;

                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === ']') {
                if (substr($trimmed, $i, strlen($closing)) !== $closing) {
                    throw new \RuntimeException('Unparsable Kimi config: a table header is not closed.');
                }
                $rest = ltrim(substr($trimmed, $i + strlen($closing)));
                if ($rest !== '' && !str_starts_with($rest, '#')) {
                    throw new \RuntimeException('Unparsable Kimi config: text follows a table header.');
                }
                $inner = substr($trimmed, $offset, $i - $offset);

                return [$isArray, self::splitPath($inner)];
            }
        }

        throw new \RuntimeException('Unparsable Kimi config: a table header is not closed.');
    }

    /**
     * The first segment of the key of an assignment line.
     *
     * @throws \RuntimeException when the line is not a key assignment
     */
    private static function keyRoot(string $line): string
    {
        $equals = self::findEquals($line);
        if ($equals === null) {
            throw new \RuntimeException('Unparsable Kimi config: expected a key assignment or a table header.');
        }
        $key = rtrim(substr($line, 0, $equals));
        if ($key === '' || str_starts_with(ltrim($key), '[')) {
            throw new \RuntimeException('Unparsable Kimi config: the key of an assignment is empty.');
        }
        $path = self::splitPath($key);

        return $path[0];
    }

    /**
     * The index of the last line of the assignment that starts at $start. The value may span lines
     * (a multi-line array or inline table, a multi-line string, a comment after either); bracket
     * depth and string state are tracked outside quoted text and comments.
     *
     * @param list<string> $lines
     */
    private static function statementEnd(array $lines, int $start): int
    {
        $equals = self::findEquals($lines[$start]);
        if ($equals === null) {
            throw new \RuntimeException('Unparsable Kimi config: expected a key assignment.');
        }
        $mode = 'normal';
        $depth = 0;
        for ($i = $start, $count = count($lines); $i < $count; ++$i) {
            $line = $lines[$i];
            $length = strlen($line);
            $j = $i === $start ? $equals + 1 : 0;
            while ($j < $length) {
                if ($mode === 'basic') {
                    if ($line[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($line[$j] === '"') {
                        $mode = 'normal';
                    }
                    ++$j;

                    continue;
                }
                if ($mode === 'literal') {
                    if ($line[$j] === "'") {
                        $mode = 'normal';
                    }
                    ++$j;

                    continue;
                }
                if ($mode === 'ml-basic') {
                    if (substr($line, $j, 3) === '"""') {
                        $mode = 'normal';
                        $j += 3;

                        continue;
                    }
                    if ($line[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    ++$j;

                    continue;
                }
                if ($mode === 'ml-literal') {
                    if (substr($line, $j, 3) === "'''") {
                        $mode = 'normal';
                        $j += 3;

                        continue;
                    }
                    ++$j;

                    continue;
                }
                $char = $line[$j];
                if ($char === '#') {
                    $mode = 'comment';

                    break;
                }
                if (substr($line, $j, 3) === '"""') {
                    $mode = 'ml-basic';
                    $j += 3;

                    continue;
                }
                if (substr($line, $j, 3) === "'''") {
                    $mode = 'ml-literal';
                    $j += 3;

                    continue;
                }
                if ($char === '"') {
                    $mode = 'basic';
                    ++$j;

                    continue;
                }
                if ($char === "'") {
                    $mode = 'literal';
                    ++$j;

                    continue;
                }
                if ($char === '[' || $char === '{') {
                    ++$depth;
                    ++$j;

                    continue;
                }
                if ($char === ']' || $char === '}') {
                    --$depth;
                    if ($depth < 0) {
                        throw new \RuntimeException('Unparsable Kimi config: unbalanced brackets in a value.');
                    }
                    ++$j;

                    continue;
                }
                ++$j;
            }
            if ($mode === 'comment') {
                $mode = 'normal';
            }
            if ($mode === 'basic' || $mode === 'literal') {
                throw new \RuntimeException('Unparsable Kimi config: an unterminated string in a value.');
            }
            if ($mode === 'normal' && $depth === 0) {
                return $i;
            }
        }

        throw new \RuntimeException('Unparsable Kimi config: a value is not closed.');
    }

    /**
     * Skips the body of a dropped table: its key assignments, comments and blank lines, up to the
     * next table header (or the end of the source). A body line that is neither is an error.
     *
     * @param list<string> $lines
     *
     * @return int the index of the next table header, or the line count
     */
    private static function skipTable(array $lines, int $index): int
    {
        for ($count = count($lines); $index < $count;) {
            $line = $lines[$index];
            if (self::isBlankOrComment($line)) {
                ++$index;

                continue;
            }
            if (self::isHeader($line)) {
                return $index;
            }
            $index = self::statementEnd($lines, $index) + 1;
        }

        return $index;
    }

    /**
     * Emits the merged `disabled` key at the end of the user's `[tools]` table, before the header
     * that closes it, so the user's table stays valid and the bot's tool policy wins. The user's
     * entries come first and the bot's four tools are appended, so nothing the user disabled comes
     * back on in a bot run.
     *
     * @param list<string> $out
     * @param list<string> $userDisabled
     */
    private static function flushToolsDisabled(array &$out, bool &$pending, array $userDisabled): void
    {
        if (!$pending) {
            return;
        }
        $out[] = 'disabled = '.self::disabledTools($userDisabled);
        $pending = false;
    }

    /**
     * The bot's `disabled` list with the user's own entries merged in: the user's order is kept and
     * the bot's tools are appended when not already present.
     *
     * @param list<string> $user
     */
    private static function disabledTools(array $user = []): string
    {
        $merged = $user;
        foreach (self::DISABLED_TOOLS as $tool) {
            if (!in_array($tool, $merged, true)) {
                $merged[] = $tool;
            }
        }

        return '['.implode(', ', array_map(self::string(...), $merged)).']';
    }

    /**
     * The raw value text of the assignment spanning lines $start..$end: everything from after the
     * `=` on the first line through the last line, joined with a newline so a multi-line array keeps
     * its shape.
     *
     * @param list<string> $lines
     */
    private static function valueText(array $lines, int $start, int $end): string
    {
        $equals = self::findEquals($lines[$start]);
        $value = substr($lines[$start], $equals + 1);
        for ($i = $start + 1; $i <= $end; ++$i) {
            $value .= "\n".$lines[$i];
        }

        return $value;
    }

    /**
     * Parses a `disabled` value as a TOML array of basic or literal strings. The value may span
     * lines and carry comments; anything else (a bare word, a number, an inline table) is an error:
     * a disabled list the bot cannot merge fails the run instead of being dropped or re-enabling a
     * tool.
     *
     * @return list<string>
     */
    private static function parseStringArray(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            throw new \RuntimeException('Unparsable Kimi config: the [tools] disabled value is empty.');
        }
        if ($value[0] !== '[') {
            throw new \RuntimeException('Unparsable Kimi config: a [tools] disabled value must be an array of strings.');
        }
        $length = strlen($value);
        $tools = [];
        $i = 1;
        while (true) {
            $i = self::skipValueSpaceAndComment($value, $i);
            if ($i >= $length) {
                throw new \RuntimeException('Unparsable Kimi config: a [tools] disabled array is not closed.');
            }
            if ($value[$i] === ']') {
                if (self::skipValueSpaceAndComment($value, $i + 1) < $length) {
                    throw new \RuntimeException('Unparsable Kimi config: text follows a [tools] disabled array.');
                }

                return $tools;
            }
            if ($value[$i] === ',') {
                ++$i;

                continue;
            }
            if ($value[$i] !== '"' && $value[$i] !== "'") {
                throw new \RuntimeException('Unparsable Kimi config: a [tools] disabled array may contain only strings.');
            }
            [$text, $i] = self::parseTomlString($value, $i);
            $tools[] = $text;
        }
    }

    /**
     * Advances $i past whitespace and `#` comments (to end of line) in a value.
     */
    private static function skipValueSpaceAndComment(string $value, int $i): int
    {
        $length = strlen($value);
        while ($i < $length) {
            $char = $value[$i];
            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                ++$i;

                continue;
            }
            if ($char === '#') {
                while ($i < $length && $value[$i] !== "\n") {
                    ++$i;
                }

                continue;
            }

            break;
        }

        return $i;
    }

    /**
     * Decodes one TOML string starting at $value[$i], which must be `"` or `'`. Multi-line strings
     * and unicode escapes are rejected: a tool name does not need them, and decoding `\u` would add
     * a charset dependency for nothing.
     *
     * @return array{0: string, 1: int} the decoded text and the index just past the closing quote
     */
    private static function parseTomlString(string $value, int $i): array
    {
        $length = strlen($value);
        $quote = $value[$i];
        ++$i;
        $text = '';
        while ($i < $length) {
            $char = $value[$i];
            if ($char === $quote) {
                return [$text, $i + 1];
            }
            if ($char === "\n" || $char === "\r") {
                throw new \RuntimeException('Unparsable Kimi config: an unterminated string in a [tools] disabled array.');
            }
            if ($quote === "'" || $char !== '\\') {
                $text .= $char;
                ++$i;

                continue;
            }
            ++$i;
            if ($i >= $length) {
                throw new \RuntimeException('Unparsable Kimi config: an unterminated string in a [tools] disabled array.');
            }
            $escape = $value[$i];
            if ($escape === '"' || $escape === '\\') {
                $text .= $escape;
            } elseif ($escape === 'b') {
                $text .= "\b";
            } elseif ($escape === 't') {
                $text .= "\t";
            } elseif ($escape === 'n') {
                $text .= "\n";
            } elseif ($escape === 'f') {
                $text .= "\f";
            } elseif ($escape === 'r') {
                $text .= "\r";
            } else {
                throw new \RuntimeException('Unparsable Kimi config: an unsupported escape in a [tools] disabled array.');
            }
            ++$i;
        }

        throw new \RuntimeException('Unparsable Kimi config: an unterminated string in a [tools] disabled array.');
    }

    private static function findEquals(string $line): ?int
    {
        return self::findOutsideQuotes($line, '=');
    }

    /**
     * The index of the first occurrence of $needle outside a single- or double-quoted string.
     */
    private static function findOutsideQuotes(string $line, string $needle): ?int
    {
        $quote = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];
            if ($quote !== '') {
                if ($char === '\\' && $quote === '"') {
                    ++$i;

                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '#') {
                return null;
            }
            if ($char === $needle) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A dotted TOML key path split on the dots outside quotes; each segment is unquoted and must be
     * a bare key or a quoted one.
     *
     * @return list<string>
     */
    private static function splitPath(string $path): array
    {
        $segments = [];
        $current = '';
        $quote = '';
        $length = strlen($path);
        for ($i = 0; $i < $length; ++$i) {
            $char = $path[$i];
            if ($quote !== '') {
                $current .= $char;
                if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $current .= $path[++$i];

                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;

                continue;
            }
            if ($char === '.') {
                $segments[] = self::segment(trim($current));
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if ($quote !== '') {
            throw new \RuntimeException('Unparsable Kimi config: an unterminated quoted key.');
        }
        $segments[] = self::segment(trim($current));

        return $segments;
    }

    /**
     * A key segment: a bare key (`A-Z`, `a-z`, `0-9`, `_`, `-`) or a quoted one, whose content may
     * contain any character (a quoted model alias contains a `/`, for example).
     */
    private static function segment(string $value): string
    {
        $first = $value[0] ?? '';
        if ($first === '"' || $first === "'") {
            if (strlen($value) < 2 || !str_ends_with($value, $first)) {
                throw new \RuntimeException('Unparsable Kimi config: an unterminated quoted key.');
            }
            $value = substr($value, 1, -1);
            if ($value === '') {
                throw new \RuntimeException('Unparsable Kimi config: an empty key segment.');
            }

            return $value;
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new \RuntimeException('Unparsable Kimi config: an invalid key segment.');
        }

        return $value;
    }

    /** A TOML basic string. */
    private static function string(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value,
        ).'"';
    }
}
