<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\MarkdownConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownConverter::class)]
final class MarkdownConverterTest extends TestCase
{
    private MarkdownConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new MarkdownConverter();
    }

    // --- plain text & HTML escaping ---

    public function testPlainTextPassesThrough(): void
    {
        self::assertSame('Hello world', $this->converter->convert('Hello world'));
    }

    public function testHtmlSpecialCharsAreEscaped(): void
    {
        self::assertSame('a &amp; b &lt;tag&gt;', $this->converter->convert('a & b <tag>'));
    }

    // --- bold ---

    public function testDoubleAsteriskBold(): void
    {
        self::assertSame('<b>bold</b>', $this->converter->convert('**bold**'));
    }

    public function testDoubleUnderscoreBold(): void
    {
        self::assertSame('<b>bold</b>', $this->converter->convert('__bold__'));
    }

    public function testBoldInSentence(): void
    {
        self::assertSame('Text <b>bold</b> end', $this->converter->convert('Text **bold** end'));
    }

    // --- italic ---

    public function testSingleAsteriskItalic(): void
    {
        self::assertSame('<i>italic</i>', $this->converter->convert('*italic*'));
    }

    public function testSingleUnderscoreItalic(): void
    {
        self::assertSame('<i>italic</i>', $this->converter->convert('_italic_'));
    }

    public function testUnderscoreInsideWordIsNotItalic(): void
    {
        $result = $this->converter->convert('snake_case_name');
        self::assertSame('snake_case_name', $result);
    }

    // --- strikethrough ---

    public function testDoubleStrikethrough(): void
    {
        self::assertSame('<s>strike</s>', $this->converter->convert('~~strike~~'));
    }

    // --- inline code ---

    public function testInlineCode(): void
    {
        self::assertSame('<code>$var</code>', $this->converter->convert('`$var`'));
    }

    public function testHtmlInInlineCodeIsEscaped(): void
    {
        self::assertSame('<code>&lt;div&gt;</code>', $this->converter->convert('`<div>`'));
    }

    public function testInlineCodeNotProcessedForMarkdown(): void
    {
        self::assertSame('<code>**not bold**</code>', $this->converter->convert('`**not bold**`'));
    }

    // --- fenced code blocks ---

    public function testFencedCodeBlockWithoutLanguage(): void
    {
        $md = "```\necho 'hi';\n```";
        self::assertSame("<pre>echo 'hi';\n</pre>", $this->converter->convert($md));
    }

    public function testFencedCodeBlockWithLanguage(): void
    {
        $md = "```php\n\$x = 1;\n```";
        self::assertSame('<pre><code class="language-php">$x = 1;'."\n".'</code></pre>', $this->converter->convert($md));
    }

    public function testHtmlInCodeBlockIsEscaped(): void
    {
        $md = "```\n<b>not bold</b>\n```";
        self::assertSame("<pre>&lt;b&gt;not bold&lt;/b&gt;\n</pre>", $this->converter->convert($md));
    }

    public function testMarkdownInsideCodeBlockIsNotProcessed(): void
    {
        $md = "```\n**not bold**\n```";
        self::assertSame("<pre>**not bold**\n</pre>", $this->converter->convert($md));
    }

    // --- tables ---

    public function testTableIsNotWrappedInPre(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        $result = $this->converter->convert($md);
        self::assertStringNotContainsString('<pre>', $result);
    }

    public function testHtmlInTableIsEscaped(): void
    {
        $md = "| Col |\n|-----|\n| <b> |";
        self::assertStringContainsString('&lt;b&gt;', $this->converter->convert($md));
    }

    public function testSinglePipeLineIsNotTable(): void
    {
        $result = $this->converter->convert('| one line only |');
        self::assertStringNotContainsString('<pre>', $result);
    }

    // --- hasTable() ---

    public function testHasTableDetectsTable(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        self::assertTrue($this->converter->hasTable($md));
    }

    public function testHasTableIgnoresSinglePipeLine(): void
    {
        self::assertFalse($this->converter->hasTable('| one line only |'));
    }

    public function testHasTableIgnoresPlainText(): void
    {
        self::assertFalse($this->converter->hasTable('a | b in prose, not a table'));
    }

    public function testHasTableDetectsTableAmongOtherText(): void
    {
        $md = "Some intro text.\n\n| A | B |\n| 1 | 2 |\n\nSome trailing text.";
        self::assertTrue($this->converter->hasTable($md));
    }

    // --- links ---

    public function testLinkWithUnderscoresIsNotFormatted(): void
    {
        self::assertSame(
            'Plan: <a href="https://github.com/o/r/blob/master/context/epics/my__epic_x_.md">context/epics/my__epic_x_.md</a>',
            $this->converter->convert('Plan: [context/epics/my__epic_x_.md](https://github.com/o/r/blob/master/context/epics/my__epic_x_.md)'),
        );
    }

    public function testInlineCodeInsideLinkText(): void
    {
        self::assertSame('<a href="https://x.y"><code>a_b</code></a>', $this->converter->convert('[`a_b`](https://x.y)'));
        self::assertSame(
            // Deliberately Cyrillic: converting non-ASCII text
            'см. <a href="https://x.y">файл <code>Bot.php</code> &lt;1&gt;</a> и <code>z</code>',
            $this->converter->convert('см. [файл `Bot.php` <1>](https://x.y) и `z`'),
        );
    }

    public function testLinkTextAndUrlAreEscaped(): void
    {
        self::assertSame(
            '<a href="https://x.y/?a=1&amp;b=&quot;2&quot;">a &lt;b&gt;</a>',
            $this->converter->convert('[a <b>](https://x.y/?a=1&b="2")'),
        );
    }

    public function testLink(): void
    {
        self::assertSame('<a href="https://example.com">text</a>', $this->converter->convert('[text](https://example.com)'));
    }

    // --- headers ---

    /**
     * @return array<string, array{string, string}>
     */
    public static function headerProvider(): array
    {
        return [
            'h1' => ['# Title', '<b>Title</b>'],
            'h2' => ['## Title', '<b>Title</b>'],
            'h6' => ['###### Title', '<b>Title</b>'],
        ];
    }

    #[DataProvider('headerProvider')]
    public function testHeadersBecomeBold(string $input, string $expected): void
    {
        self::assertSame($expected, $this->converter->convert($input));
    }

    // --- combinations ---

    public function testBoldAndItalicInSameLine(): void
    {
        $result = $this->converter->convert('**bold** and *italic*');
        self::assertSame('<b>bold</b> and <i>italic</i>', $result);
    }

    public function testCodeBlockFollowedByText(): void
    {
        $md = "```\ncode\n```\n\nsome **bold** text";
        $result = $this->converter->convert($md);
        self::assertStringContainsString('<pre>code', $result);
        self::assertStringContainsString('<b>bold</b>', $result);
    }

    public function testInlineCodeNotAffectedByBoldAround(): void
    {
        $result = $this->converter->convert('**bold** `code` **bold**');
        self::assertSame('<b>bold</b> <code>code</code> <b>bold</b>', $result);
    }
}
