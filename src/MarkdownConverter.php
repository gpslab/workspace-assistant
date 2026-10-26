<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Converts Markdown to the Telegram HTML subset (parse_mode=HTML).
 *
 * Algorithm: first protect code blocks (escaping the HTML inside them),
 * then escape all the remaining text, then apply the markup tags.
 *
 * Supported: bold, italic, strikethrough, inline code, code blocks,
 * links, headings. Other constructs are plain text. Markdown tables
 * are not handled by this method: they are intercepted earlier, in
 * TelegramClient::sendText() (see hasTable()), and sent as a file.
 */
final class MarkdownConverter
{
    private const string TABLE_PATTERN = '/^(\|[^\n]*(?:\n\|[^\n]*)+)/m';

    /**
     * Whether the text contains a markdown table (≥2 consecutive lines starting with |).
     * The same criterion that convert() uses for wrapping in <pre>.
     */
    public function hasTable(string $md): bool
    {
        return preg_match(self::TABLE_PATTERN, $md) === 1;
    }

    public function convert(string $md): string
    {
        /** @var array<string, string> $protected */
        $protected = [];

        // Code blocks (``` ... ```)
        $md = preg_replace_callback(
            '/^```(\w*)\r?\n(.*?)^```[ \t]*$/ms',
            function (array $m) use (&$protected): string {
                // ENT_NOQUOTES: quotes need no escaping inside <pre>/<code>
                // (Telegram requires only &lt; &gt; &amp;), and &#039; clutters the output.
                $code = htmlspecialchars($m[2], ENT_NOQUOTES, 'UTF-8');
                $html = $m[1] !== ''
                    ? "<pre><code class=\"language-{$m[1]}\">{$code}</code></pre>"
                    : "<pre>{$code}</pre>";
                $k = "\x02".count($protected)."\x03";
                $protected[$k] = $html;

                return $k;
            },
            $md
        ) ?? $md;

        // Inline code (`...`)
        $md = preg_replace_callback(
            '/`([^`\n]+)`/',
            function (array $m) use (&$protected): string {
                $k = "\x02".count($protected)."\x03";
                $protected[$k] = '<code>'.htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8').'</code>';

                return $k;
            },
            $md
        ) ?? $md;

        // Links [text](url) go before bold/italic: `_` and `__` in the text or URL
        // (e.g. an epic slug in the path) would otherwise turn into tags inside <a> and
        // href, and Telegram rejects the whole message ("can't parse entities").
        $md = preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/',
            function (array $m) use (&$protected): string {
                $k = "\x02".count($protected)."\x03";
                // The link text may already contain inline-code placeholders
                // ([`file.php`](url)): the final strtr is single-pass and will not
                // expand them inside the substituted <a>, so expand them here and
                // escape the rest of the text. Bold/italic are not applied
                // inside the link text.
                $parts = preg_split("/(\x02\\d+\x03)/", $m[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$m[1]];
                $text = '';
                foreach ($parts as $part) {
                    $text .= $protected[$part] ?? htmlspecialchars($part, ENT_NOQUOTES, 'UTF-8');
                }
                $protected[$k] = '<a href="'.htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8').'">'.$text.'</a>';

                return $k;
            },
            $md
        ) ?? $md;

        // HTML-escape the remaining text
        $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

        // Bold: **text** and __text__
        $md = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $md) ?? $md;
        $md = preg_replace('/__(.+?)__/s', '<b>$1</b>', $md) ?? $md;
        // Italic: *text* and _text_ (not inside words)
        $md = preg_replace('/\*([^*\n]+)\*/', '<i>$1</i>', $md) ?? $md;
        $md = preg_replace('/(?<!\w)_([^_\n]+)_(?!\w)/', '<i>$1</i>', $md) ?? $md;
        // Strikethrough: ~~text~~
        $md = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $md) ?? $md;
        // Headings → bold
        $md = preg_replace('/^#{1,6} +(.+)$/m', '<b>$1</b>', $md) ?? $md;

        return strtr($md, $protected);
    }
}
