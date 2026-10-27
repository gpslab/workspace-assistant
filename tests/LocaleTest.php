<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Locale::class)]
final class LocaleTest extends TestCase
{
    private string $root;
    private string $builtin;
    private string $instance;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tglocale_'.uniqid('', true);
        $this->builtin = $this->root.'/builtin';
        $this->instance = $this->root.'/instance';
        mkdir($this->builtin, 0777, true);
        mkdir($this->instance, 0777, true);

        $this->write($this->builtin, 'en', ['_language' => 'English', 'hello' => 'Hello, {name}!', 'only_en' => 'Only English', 'plain' => 'Plain', 'pair' => '{a}/{b}']);
        $this->write($this->builtin, 'de', ['_language' => 'German', 'hello' => 'Hallo, {name}!', 'plain' => 'Schlicht']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->builtin, $this->instance] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    /**
     * @param array<string, string> $texts
     */
    private function write(string $dir, string $code, array $texts): void
    {
        file_put_contents("{$dir}/{$code}.json", json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testSubstitutesNamedPlaceholders(): void
    {
        $locale = new Locale('de', null, $this->builtin);

        self::assertSame('Hallo, Anna!', $locale->t('hello', ['name' => 'Anna']));
        self::assertSame('de', $locale->code());
        self::assertSame('German', $locale->languageName());
    }

    public function testSubstitutedValuesAreNotExpandedAgain(): void
    {
        $locale = new Locale('en', null, $this->builtin);

        self::assertSame('Hello, {name}!', $locale->t('hello', ['name' => '{name}']));
        self::assertSame('{b}/X', $locale->t('pair', ['a' => '{b}', 'b' => 'X']));
    }

    public function testMissingKeyFallsBackToEnglishAndUnknownKeyIsReturnedAsIs(): void
    {
        $locale = new Locale('de', null, $this->builtin);

        self::assertSame('Only English', $locale->t('only_en'));
        self::assertSame('no.such.key', $locale->t('no.such.key'));
        self::assertTrue($locale->has('only_en'));
        self::assertFalse($locale->has('no.such.key'));
    }

    public function testUnknownLocaleFallsBackToEnglishWithWarning(): void
    {
        $locale = new Locale('xx', null, $this->builtin);

        self::assertSame('en', $locale->code());
        self::assertSame('Plain', $locale->t('plain'));
        self::assertCount(1, $locale->warnings());
        self::assertStringContainsString('BOT_LOCALE=xx', $locale->warnings()[0]);
    }

    public function testInstanceDirAddsNewLocaleSelectableByCode(): void
    {
        $this->write($this->instance, 'tlh', ['_language' => 'Klingon', 'hello' => 'nuqneH, {name}!']);

        $locale = new Locale('tlh', $this->instance, $this->builtin);

        self::assertSame('tlh', $locale->code());
        self::assertSame('nuqneH, Anna!', $locale->t('hello', ['name' => 'Anna']));
        self::assertSame('Only English', $locale->t('only_en'));
        self::assertSame('Klingon', $locale->languageName());
        self::assertSame(['de', 'en', 'tlh'], $locale->codes());
        self::assertSame([], $locale->warnings());
    }

    public function testCommandTextWithEmojiProducesWarning(): void
    {
        $this->write($this->builtin, 'en', ['_language' => 'English', 'hello' => 'Hello, {name}!', 'command.new' => 'New']);
        $this->write($this->instance, 'de', ['command.new' => '🆕 Neu', 'hello' => 'Hallo ❓, {name}!']);

        $warnings = (new Locale('de', $this->instance, $this->builtin))->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('command.new contains an emoji', $warnings[0]);
    }

    public function testInstanceFileOverridesBuiltinLocaleByKeys(): void
    {
        $this->write($this->instance, 'de', ['hello' => 'Servus, {name}!']);

        $locale = new Locale('de', $this->instance, $this->builtin);

        self::assertSame('Servus, Anna!', $locale->t('hello', ['name' => 'Anna']));
        self::assertSame('Schlicht', $locale->t('plain'), 'a key missing in the partial file comes from the built-in locale');
        self::assertSame('Only English', $locale->t('only_en'));
        self::assertSame('German', $locale->languageName());
        self::assertSame(['de', 'en'], $locale->codes());
    }

    public function testWarnsAboutExtraKeysAndPlaceholderMismatchInInstanceFile(): void
    {
        $this->write($this->instance, 'de', ['hello' => 'Hallo, {who}!', 'extra' => 'Extra']);

        $locale = new Locale('de', $this->instance, $this->builtin);

        $warnings = implode("\n", $locale->warnings());
        self::assertStringContainsString('key extra is not in the en reference', $warnings);
        self::assertStringContainsString('key hello has placeholders that differ', $warnings);
    }

    public function testInvalidInstanceJsonIsSkippedWithWarning(): void
    {
        file_put_contents($this->instance.'/fr.json', '{broken');

        $locale = new Locale('fr', $this->instance, $this->builtin);

        self::assertSame('en', $locale->code());
        $warnings = implode("\n", $locale->warnings());
        self::assertStringContainsString('fr.json skipped: invalid JSON', $warnings);
        self::assertStringContainsString('BOT_LOCALE=fr', $warnings);
    }

    public function testMissingInstanceDirIsIgnoredWithWarning(): void
    {
        $locale = new Locale('en', $this->root.'/nope', $this->builtin);

        self::assertSame('en', $locale->code());
        self::assertStringContainsString('is not a directory', $locale->warnings()[0]);
    }

    public function testWithSelectsAnotherLocaleOfTheSameCatalog(): void
    {
        $locale = new Locale('en', null, $this->builtin);

        self::assertSame('Schlicht', $locale->with('de')->t('plain'));
        self::assertSame('Plain', $locale->with('unknown')->t('plain'));
        self::assertSame('Plain', $locale->t('plain'));
    }

    public function testPlaceholdersAreSortedAndUnique(): void
    {
        self::assertSame(['a', 'b'], Locale::placeholders('{b} and {a} and {b}'));
        self::assertSame([], Locale::placeholders('no placeholders { here }'));
    }
}
