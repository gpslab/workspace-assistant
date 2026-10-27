<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Catalog of the bot's interface texts: one JSON file per locale
 * (`resources/locales/<code>.json`, the code is the file name).
 *
 * A file is a flat object "key => text" plus the service fields `_language` (the English name
 * of the language), `_flag` and `_native` (flag emoji and self-name for the /lang buttons). Placeholders in texts are named: `{name}`.
 *
 * The instance may add its own locales (or override built-in ones key by key) with files in
 * `LOCALES_DIR`. A text is looked up in the selected locale (instance file over built-in file),
 * then in the built-in `en` reference.
 */
final class Locale
{
    public const string DEFAULT = 'en';
    public const string LANGUAGE_FIELD = '_language';
    public const string FLAG_FIELD = '_flag';
    public const string NATIVE_FIELD = '_native';

    /** Service fields of a catalog: not interface texts, so they are not in the key and placeholder checks. */
    public const array SERVICE_FIELDS = [self::LANGUAGE_FIELD, self::FLAG_FIELD, self::NATIVE_FIELD];

    /** @var array<string, array<string, string>> locale code => own texts (built-in overlaid by the instance file) */
    private array $catalogs = [];

    /** @var list<string> */
    private array $warnings = [];

    private string $code;

    /**
     * @param string      $code        selected locale; an unknown code falls back to `en` with a warning
     * @param string|null $overrideDir absolute path to the instance locale files, or null
     * @param string|null $builtinDir  built-in locale directory (defaults to `resources/locales`)
     */
    public function __construct(string $code = self::DEFAULT, ?string $overrideDir = null, ?string $builtinDir = null)
    {
        $builtinDir ??= dirname(__DIR__).'/resources/locales';
        $builtin = self::readDir($builtinDir, $this->warnings);
        $this->catalogs = $builtin;

        if ($overrideDir !== null && $overrideDir !== '') {
            if (!is_dir($overrideDir)) {
                $this->warnings[] = "LOCALES_DIR {$overrideDir} is not a directory, instance locales are ignored";
            } else {
                foreach (self::readDir($overrideDir, $this->warnings) as $name => $texts) {
                    $this->catalogs[$name] = $texts + ($this->catalogs[$name] ?? []);
                    $this->checkOverride($name, $texts, $builtin[self::DEFAULT] ?? []);
                }
            }
        }

        if (!isset($this->catalogs[self::DEFAULT])) {
            throw new \RuntimeException('The built-in reference locale '.self::DEFAULT.' is missing in '.$builtinDir);
        }

        if (!isset($this->catalogs[$code])) {
            $this->warnings[] = "BOT_LOCALE={$code} has no locale file, using ".self::DEFAULT;
            $code = self::DEFAULT;
        }
        $this->code = $code;
    }

    /** The catalog of an instance: `BOT_LOCALE` selects the locale, `LOCALES_DIR` (relative to `REPO_PATH`) adds files. */
    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->botLocale,
            $config->localesDir !== '' ? $config->repoPath.'/'.$config->localesDir : null,
        );
    }

    public function code(): string
    {
        return $this->code;
    }

    /** The same catalog with another locale selected (no new warnings; unknown code means `en`). */
    public function with(string $code): self
    {
        $clone = clone $this;
        $clone->code = isset($this->catalogs[$code]) ? $code : self::DEFAULT;
        $clone->warnings = [];

        return $clone;
    }

    /**
     * Warnings found while loading (missing locale, broken files, extra keys, placeholder mismatch).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Codes of all available locales (built-in plus instance), sorted.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = array_keys($this->catalogs);
        sort($codes);

        return $codes;
    }

    /** English name of the language of a locale (the selected one by default). */
    public function languageName(?string $code = null): string
    {
        $code ??= $this->code;

        return $this->catalogs[$code][self::LANGUAGE_FIELD] ?? $this->catalogs[self::DEFAULT][self::LANGUAGE_FIELD] ?? $code;
    }

    /** Flag emoji of a locale from its own catalog (no fallback to `en`), or null when it has none. */
    public function flag(string $code): ?string
    {
        $flag = $this->catalogs[$code][self::FLAG_FIELD] ?? '';

        return $flag !== '' ? $flag : null;
    }

    /** Self-name of a locale from its own catalog (no fallback to `en`): `_native`, then `_language`, then the code. */
    public function nativeName(string $code): string
    {
        foreach ([self::NATIVE_FIELD, self::LANGUAGE_FIELD] as $field) {
            $name = $this->catalogs[$code][$field] ?? '';
            if ($name !== '') {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Text by key with named placeholders substituted; a key missing in the selected locale is
     * taken from `en`, a key missing everywhere is returned as is.
     *
     * @param array<string, scalar> $params placeholder name => value
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->catalogs[$this->code][$key] ?? $this->catalogs[self::DEFAULT][$key] ?? $key;
        if ($params === []) {
            return $text;
        }

        $map = [];
        foreach ($params as $name => $value) {
            $map['{'.$name.'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    /** Whether the text exists in the selected locale or in `en`. */
    public function has(string $key): bool
    {
        return isset($this->catalogs[$this->code][$key]) || isset($this->catalogs[self::DEFAULT][$key]);
    }

    /**
     * Sorted placeholder names used by a text.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * Reads all `<code>.json` files of a directory; a broken file is skipped with a warning.
     *
     * @param list<string> $warnings
     *
     * @return array<string, array<string, string>>
     */
    public static function readDir(string $dir, array &$warnings = []): array
    {
        $result = [];
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $code = basename($file, '.json');
            try {
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $warnings[] = "locale file {$file} skipped: invalid JSON ({$e->getMessage()})";

                continue;
            }
            if (!is_array($data)) {
                $warnings[] = "locale file {$file} skipped: the top level must be an object";

                continue;
            }
            $texts = [];
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $texts[(string) $key] = $value;
                } else {
                    $warnings[] = "locale file {$file}: key {$key} is not a string, ignored";
                }
            }
            $result[$code] = $texts;
        }

        return $result;
    }

    /**
     * Warns about keys of an instance file that `en` does not know and about placeholder sets
     * that differ from `en`.
     *
     * @param array<string, string> $texts
     * @param array<string, string> $reference built-in `en` texts
     */
    private function checkOverride(string $code, array $texts, array $reference): void
    {
        foreach ($texts as $key => $text) {
            if (in_array($key, self::SERVICE_FIELDS, true)) {
                continue;
            }
            if (!isset($reference[$key])) {
                $this->warnings[] = "locale {$code}: key {$key} is not in the en reference";

                continue;
            }
            if (self::placeholders($text) !== self::placeholders($reference[$key])) {
                $this->warnings[] = "locale {$code}: key {$key} has placeholders that differ from the en reference";
            }
            if (str_starts_with($key, 'command.') && preg_match('/\p{Extended_Pictographic}/u', $text) === 1) {
                $this->warnings[] = "locale {$code}: key {$key} contains an emoji, the bot adds the command emoji itself";
            }
        }
    }
}
