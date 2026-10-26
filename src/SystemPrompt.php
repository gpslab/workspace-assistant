<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Mode system prompts: the rules Claude follows in this project, per mode.
 *
 * A prompt is split in two. The CONTRACT (the identity line, delivery and
 * self-preservation rules, the response-size ceiling, and the parts of the
 * review rules the bot parses or enforces) is business logic. Its texts live in
 * `prompts/*.md.dist`, under git (history, review). A local `prompts/<name>.md`
 * overrides any of them, so the contract CAN be replaced by a file, but never
 * silently: every run reports each local override of a contract name in the reply
 * (see {@see PromptFiles::CONTRACT}). The PERSONA (the shared `common` block and the
 * per-mode role block) comes from the first existing of: the instance's `PROMPTS_DIR`
 * files (`common.md`, `arch.md`, `review.md`, `pm.md`), a local `prompts/persona_common.md` /
 * `prompts/role_<mode>.md`, and the shipped `.dist` of the latter. A local persona file
 * is the normal way to configure an instance and is not reported.
 * Neither goes to `.env`, which is for environment settings.
 *
 * Prompt files are read on every call: an edit applies from the next run.
 *
 * The text is passed by `ClaudeRunner` via `--append-system-prompt`, in order:
 * contract (+ common_limits outside arch, + review contract in review), common
 * persona, mode role persona. An optional instance addition
 * (`CLAUDE_APPEND_SYSTEM_PROMPT` in `.env`) is appended AFTER that text.
 */
final class SystemPrompt
{
    public const string MODE_ARCH = 'arch';
    public const string MODE_REVIEW = 'review';
    public const string MODE_PM = 'pm';

    /** @var list<string> */
    public const array MODES = [self::MODE_ARCH, self::MODE_REVIEW, self::MODE_PM];

    /**
     * Mode for a new session (a row not yet in `sessions`) and the fallback for an
     * unknown value in the DB. Existing rows are not migrated: their
     * `arch` may have been chosen explicitly, and that cannot be told apart from the old default.
     */
    public const string DEFAULT_MODE = self::MODE_PM;

    /**
     * Final system prompt for a mode: the contract (`common`, `common_limits` outside
     * arch, `contract_review` in review), then the common persona, then the mode's
     * role persona. An unknown $mode is silently treated as MODE_ARCH — the DB
     * value is validated by {@see Database::getMode()} before this is called.
     *
     * @param string|null $promptsDir absolute path to the persona files, or null to use the shipped personas
     *
     * @throws \RuntimeException when a shipped `.dist` file is missing
     */
    public static function forMode(string $mode, ?string $promptsDir = null): string
    {
        return self::build($mode, $promptsDir)['text'];
    }

    /**
     * Same as {@see forMode()}, plus warnings: persona files that are missing or unreadable
     * while a prompts dir is set (the text of the named `prompts/` file is used then), prompt
     * files that are empty or have wrong placeholders, and every local override of a contract name.
     * Files are read on every call, so an edit takes effect on the next run.
     *
     * @param string|null $displayDir directory name shown in warnings instead of the absolute path
     *
     * @return array{text: string, warnings: list<string>}
     *
     * @throws \RuntimeException when a shipped `.dist` file is missing
     */
    public static function build(string $mode, ?string $promptsDir = null, ?string $displayDir = null): array
    {
        $mode = self::isValidMode($mode) ? $mode : self::MODE_ARCH;

        $warnings = PromptFiles::contractWarnings();
        $common = self::persona($promptsDir, $displayDir, 'common', 'persona_common', $warnings);
        $role = self::persona($promptsDir, $displayDir, $mode, 'role_'.$mode, $warnings);

        $parts = [PromptFiles::load('common', $warnings)];
        if ($mode !== self::MODE_ARCH) {
            $parts[] = PromptFiles::load('common_limits', $warnings);
        }
        if ($mode === self::MODE_REVIEW) {
            $parts[] = PromptFiles::load('contract_review', $warnings);
        }
        array_push($parts, $common, $role);
        if ($mode === self::MODE_PM) {
            array_push($warnings, ...PmRunbook::config($promptsDir)['warnings']);
        }

        return [
            'text' => implode("\n\n", $parts),
            'warnings' => $warnings,
        ];
    }

    /**
     * A persona text: `<PROMPTS_DIR>/<$name>.md`, else the `prompts/<$fallback>` chain of {@see PromptFiles}.
     *
     * @param list<string> $warnings
     */
    private static function persona(?string $dir, ?string $displayDir, string $name, string $fallback, array &$warnings): string
    {
        if ($dir !== null && $dir !== '') {
            $path = rtrim($dir, '/').'/'.$name.'.md';
            $text = is_file($path) ? @file_get_contents($path) : false;
            $text = $text === false ? false : trim($text);
            if ($text !== false && $text !== '') {
                return $text;
            }

            $shown = rtrim($displayDir ?? $dir, '/').'/'.$name.'.md';
            $source = PromptFiles::DIR.'/'.basename(PromptFiles::resolvedPath($fallback));
            $warnings[] = "Prompt file {$shown} is missing, empty or unreadable — {$source} was used.";
        }

        return PromptFiles::load($fallback, $warnings);
    }

    /**
     * The chat-language line: always the last part of the system prompt, so that it overrides
     * a persona or CLAUDE_APPEND_SYSTEM_PROMPT. It is part of the prompt contract, not of a persona file.
     *
     * @param string       $language English name of the language (the `_language` field of the locale file)
     * @param list<string> $warnings
     *
     * @throws \RuntimeException when the shipped `.dist` file is missing
     */
    public static function languageLine(string $language, array &$warnings = []): string
    {
        return PromptFiles::render('language_line', ['language' => $language], $warnings);
    }

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }
}
