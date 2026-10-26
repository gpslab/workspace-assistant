<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Loader of the prompt texts shipped in the installation's `prompts/` directory.
 *
 * Every text is a file `<name>.md.dist` under git. An instance overrides one by creating
 * `prompts/<name>.md` (gitignored, like `.env` next to `.env.dist`). Files are read on every
 * call: an edit applies from the next run, without a restart.
 *
 * Loading: CRLF becomes LF and exactly one trailing "\n" is removed; nothing else is trimmed.
 * Separators between parts (and the line breaks at the edges of the compact texts) live in code.
 *
 * Placeholders are named `{name}`. A local file must use the same set as its `.dist`
 * (the check follows {@see Locale}); a placeholder the code does not fill is reported too.
 * The placeholders of a `.dist` change only by addition: the hook and the files are read from disk
 * at once, while the bot process picks up new code only on restart.
 */
final class PromptFiles
{
    public const string DIR = 'prompts';

    /**
     * Names whose local override is always reported in the reply: the rules the bot relies on
     * (the contract), including the texts only the Stop hook reads. Persona names are not here.
     *
     * @var list<string>
     */
    public const array CONTRACT = [
        'common',
        'common_limits',
        'contract_review',
        'language_line',
        'session_update',
        'review_status_reason',
        'review_status_reason_no_verdict',
        'review_status_reason_ids',
        'review_status_reason_steps',
        'compact_summarize_prompt',
        'compact_reseed_prefix',
        'compact_reseed_suffix',
        'epic_runbook',
        'pm_runbook',
    ];

    private const string PLACEHOLDER_RE = '/\{([a-z][a-z0-9_]*)\}/';

    private static ?string $baseDir = null;

    private static ?string $defaultDir = null;

    /** Change what `useDirectory(null)` restores (tests: a copy of the shipped `.dist` files only). */
    public static function useDefaultDirectory(?string $dir): void
    {
        self::$defaultDir = $dir;
    }

    /** Point the loader at another directory (tests); null restores the installation's `prompts/`. */
    public static function useDirectory(?string $dir): void
    {
        self::$baseDir = $dir;
    }

    public static function directory(): string
    {
        return self::$baseDir ?? self::$defaultDir ?? dirname(__DIR__).'/'.self::DIR;
    }

    public static function localPath(string $name): string
    {
        return self::directory().'/'.$name.'.md';
    }

    public static function distPath(string $name): string
    {
        return self::directory().'/'.$name.'.md.dist';
    }

    /** The file the text is actually taken from: the local one if it is usable, else `.dist`. */
    public static function resolvedPath(string $name): string
    {
        $local = is_file(self::localPath($name)) ? self::read(self::localPath($name)) : null;

        return $local !== null && trim($local) !== '' ? self::localPath($name) : self::distPath($name);
    }

    /**
     * The text of $name. A local file that is empty or unreadable gives way to `.dist` with a warning.
     *
     * @param list<string> $warnings
     *
     * @throws \RuntimeException when `.dist` is missing or unreadable (a broken installation)
     */
    public static function load(string $name, array &$warnings = []): string
    {
        $dist = self::loadDist($name);

        if (!is_file(self::localPath($name))) {
            return $dist;
        }
        $local = self::read(self::localPath($name));
        if ($local === null || trim($local) === '') {
            $warnings[] = 'Prompt file '.self::DIR.'/'.$name.'.md is empty or unreadable — '.self::DIR.'/'.$name.'.md.dist was used.';

            return $dist;
        }
        if (self::placeholders($local) !== self::placeholders($dist)) {
            $warnings[] = 'Prompt file '.self::DIR.'/'.$name.'.md has placeholders that differ from '.self::DIR.'/'.$name.'.md.dist.';
        }

        return $local;
    }

    /**
     * The shipped `.dist` text of $name, ignoring any local override.
     *
     * @throws \RuntimeException when `.dist` is missing, empty or unreadable
     */
    public static function loadDist(string $name): string
    {
        $dist = self::read(self::distPath($name));
        if ($dist === null || $dist === '') {
            throw new \RuntimeException('Prompt file '.self::DIR.'/'.$name.'.md.dist is missing, empty or unreadable — the installation is broken.');
        }

        return $dist;
    }

    /**
     * The text of $name with `{key}` placeholders filled from $values.
     *
     * @param array<string, string> $values
     * @param list<string>          $warnings
     */
    public static function render(string $name, array $values, array &$warnings = []): string
    {
        $text = self::load($name, $warnings);

        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }
        $left = array_diff(self::placeholders($text), array_keys($values));
        if ($left !== []) {
            $warnings[] = 'Prompt file '.self::DIR.'/'.$name.'.md has unfilled placeholders: {'.implode('}, {', $left).'}.';
        }

        return strtr($text, $replace);
    }

    /**
     * One warning per contract name that has a local override.
     *
     * @return list<string>
     */
    public static function contractWarnings(): array
    {
        $warnings = [];
        foreach (self::CONTRACT as $name) {
            if (is_file(self::localPath($name))) {
                $warnings[] = 'Contract prompt overridden locally: '.self::DIR.'/'.$name.'.md replaces '.self::DIR.'/'.$name.'.md.dist.';
            }
        }

        return $warnings;
    }

    /**
     * @return list<string> sorted unique placeholder names
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER_RE, $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    private static function read(string $path): ?string
    {
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            return null;
        }
        $text = str_replace("\r\n", "\n", $text);

        return str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;
    }
}
