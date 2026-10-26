<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Parity of the built-in catalog (`resources/locales`): `en` is the complete reference and
 * fallback; every other locale is complete and agrees with it. Locales are discovered from
 * the directory, so a new locale file needs no test change.
 */
#[CoversNothing]
final class LocaleCatalogTest extends TestCase
{
    private const string DIR = __DIR__.'/../resources/locales';

    /**
     * @return array<string, array<string, string>>
     */
    private static function catalogs(): array
    {
        $warnings = [];
        $catalogs = Locale::readDir(self::DIR, $warnings);
        self::assertSame([], $warnings);

        return $catalogs;
    }

    /** Source of every class in `src/` and `src/Epic/` as one string. */
    private static function sources(): string
    {
        $source = '';
        foreach (array_merge(glob(__DIR__.'/../src/*.php') ?: [], glob(__DIR__.'/../src/Epic/*.php') ?: []) as $file) {
            $source .= (string) file_get_contents($file)."\n";
        }

        return $source;
    }

    /**
     * Keys the code uses: literal `->t('key')` calls of all sources plus the `command.<name>` keys.
     *
     * @return list<string>
     */
    private static function usedKeys(): array
    {
        preg_match_all("/->t\\(\\s*'([a-z0-9_.]+)'/", self::sources(), $m);
        $keys = $m[1];

        $commands = (new \ReflectionClassConstant(Bot::class, 'BOT_COMMANDS'))->getValue();
        self::assertIsArray($commands);
        foreach ($commands as $command) {
            $keys[] = 'command.'.$command;
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public function testEnglishReferenceExistsAndEveryLocaleNamesItsLanguage(): void
    {
        $catalogs = self::catalogs();

        self::assertArrayHasKey('en', $catalogs);
        foreach ($catalogs as $code => $texts) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (string) $code);
            self::assertArrayHasKey(Locale::LANGUAGE_FIELD, $texts, "locale {$code} has no language name");
            self::assertNotSame('', trim($texts[Locale::LANGUAGE_FIELD]), "locale {$code} has an empty language name");
        }
        self::assertSame('English', $catalogs['en'][Locale::LANGUAGE_FIELD]);
    }

    public function testEveryLocaleHasAFlagAndASelfName(): void
    {
        $catalogs = self::catalogs();

        foreach ($catalogs as $code => $texts) {
            foreach ([Locale::FLAG_FIELD, Locale::NATIVE_FIELD] as $field) {
                self::assertArrayHasKey($field, $texts, "locale {$code} has no {$field}");
                self::assertNotSame('', trim($texts[$field]), "locale {$code} has an empty {$field}");
            }
        }
        self::assertSame('🇬🇧', $catalogs['en'][Locale::FLAG_FIELD]);
    }

    public function testCodeCallsAreStaticallyFindable(): void
    {
        preg_match_all('/->t\(\s*(?!\')[^\n]*/', self::sources(), $m);

        // The wrapper Bot::t() and the command list are the only calls with a computed key.
        foreach ($m[0] as $call) {
            self::assertMatchesRegularExpression('/\$key|"command\.\{\$command\}"/', $call, "non-literal catalog key: {$call}");
        }
    }

    public function testEnglishHasEveryKeyUsedByTheCodeAndNothingElse(): void
    {
        $en = array_keys(self::catalogs()['en']);
        $en = array_values(array_diff($en, Locale::SERVICE_FIELDS));
        sort($en);
        $used = self::usedKeys();

        self::assertSame([], array_values(array_diff($used, $en)), 'keys used by the code but missing in en');
        self::assertSame([], array_values(array_diff($en, $used)), 'keys in en that no code uses');
    }

    public function testEveryLocaleHasExactlyTheKeysOfEnglish(): void
    {
        $catalogs = self::catalogs();
        $reference = array_keys($catalogs['en']);
        sort($reference);

        foreach ($catalogs as $code => $texts) {
            $keys = array_keys($texts);
            sort($keys);
            self::assertSame([], array_values(array_diff($keys, $reference)), "locale {$code} has keys that en does not have");
            self::assertSame([], array_values(array_diff($reference, $keys)), "built-in locale {$code} is incomplete");
        }
    }

    public function testPlaceholdersMatchEnglish(): void
    {
        $catalogs = self::catalogs();

        foreach ($catalogs as $code => $texts) {
            foreach ($texts as $key => $text) {
                if (in_array($key, Locale::SERVICE_FIELDS, true) || !isset($catalogs['en'][$key])) {
                    continue;
                }
                self::assertSame(
                    Locale::placeholders($catalogs['en'][$key]),
                    Locale::placeholders($text),
                    "placeholders of {$code}:{$key} differ from en",
                );
            }
        }
    }
}
