<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Pure builders of every inline keyboard. They neither send nor decide what happens to a button
 * left out for exceeding Telegram's 64-byte `callback_data` limit: the {@see Keyboard} they return
 * tells whether that happened. Labels come translated from the caller.
 */
final class Keyboards
{
    /** Telegram's limit for inline button callback_data. */
    private const int CALLBACK_DATA_MAX_BYTES = 64;

    /**
     * /lang keyboard: one button per locale, three per row; the current language gets ' ✓'.
     * callback_data is 'lang:<code>'; a locale whose code does not fit gets no button.
     *
     * @param list<array{code: string, label: string}> $locales label is '<flag> <self-name>' (no flag when it has none)
     */
    public static function language(array $locales, string $current): Keyboard
    {
        $buttons = [];
        $skipped = false;
        foreach ($locales as ['code' => $code, 'label' => $label]) {
            if ($code === $current) {
                $label .= ' ✓';
            }
            $button = self::button($label, "lang:{$code}");
            if ($button === null) {
                $skipped = true;

                continue;
            }
            $buttons[] = $button;
        }

        return new Keyboard(['inline_keyboard' => array_chunk($buttons, 3)], $skipped);
    }

    /**
     * /mode keyboard: three buttons arch/review/pm, the active mode's label gets ' ✓'. thread_id is
     * baked into callback_data (the keyboard lives in the feed indefinitely).
     */
    public static function mode(string $currentMode, int $threadId): Keyboard
    {
        $labels = [
            SystemPrompt::MODE_ARCH => '🧠 arch',
            SystemPrompt::MODE_REVIEW => '🔍 review',
            SystemPrompt::MODE_PM => '📋 pm',
        ];

        $buttons = [];
        $skipped = false;
        foreach ($labels as $mode => $label) {
            if ($mode === $currentMode) {
                $label .= ' ✓';
            }
            $button = self::button($label, "mode:{$mode}:{$threadId}");
            if ($button === null) {
                $skipped = true;

                continue;
            }
            $buttons[] = $button;
        }

        return new Keyboard(['inline_keyboard' => [$buttons]], $skipped);
    }

    /**
     * The /epic menu: one row of actions; an action whose callback_data does not fit is left out.
     *
     * @param list<array{0: string, 1: string}> $actions [text, callback_data]
     */
    public static function epicMenu(array $actions): Keyboard
    {
        $buttons = [];
        $skipped = false;
        foreach ($actions as [$text, $data]) {
            $button = self::button($text, $data);
            if ($button === null) {
                $skipped = true;

                continue;
            }
            $buttons[] = $button;
        }

        return new Keyboard(['inline_keyboard' => [$buttons]], $skipped);
    }

    /**
     * The "pick a plan" list: one button per candidate (at most $max, in the given order) and a cancel
     * button. A candidate whose `pick` callback_data does not fit is not listed. With no candidate the
     * keyboard is empty (no cancel button either).
     *
     * @param list<string> $slugs
     */
    public static function epicCandidates(int $threadId, array $slugs, int $max, string $cancelLabel): Keyboard
    {
        $rows = [];
        $skipped = false;
        foreach ($slugs as $slug) {
            $button = self::button($slug, "epic:pick:{$threadId}:{$slug}");
            if ($button === null) {
                $skipped = true;

                continue;
            }
            if (count($rows) < $max) {
                $rows[] = [$button];
            }
        }
        if ($rows === []) {
            return new Keyboard(['inline_keyboard' => []], $skipped);
        }

        $rows[] = [['text' => $cancelLabel, 'callback_data' => "epic:cancel:{$threadId}"]];

        return new Keyboard(['inline_keyboard' => $rows], $skipped);
    }

    /** The start confirmation: start and cancel buttons. */
    public static function epicConfirmation(int $threadId, string $slug, string $startLabel, string $cancelLabel): Keyboard
    {
        $buttons = [];
        $skipped = false;
        foreach ([[$startLabel, "epic:go:{$threadId}:{$slug}"], [$cancelLabel, "epic:cancel:{$threadId}"]] as [$text, $data]) {
            $button = self::button($text, $data);
            if ($button === null) {
                $skipped = true;

                continue;
            }
            $buttons[] = $button;
        }

        return new Keyboard(['inline_keyboard' => [$buttons]], $skipped);
    }

    /** The commit/discard decision on a dirty tree. */
    public static function decision(string $commitLabel, string $discardLabel): Keyboard
    {
        return new Keyboard(['inline_keyboard' => [[
            ['text' => $commitLabel, 'callback_data' => 'commit'],
            ['text' => $discardLabel, 'callback_data' => 'discard'],
        ]]]);
    }

    /** Removes the keyboard from a message. */
    public static function remove(): Keyboard
    {
        return new Keyboard(['inline_keyboard' => []]);
    }

    /**
     * @return array{text: string, callback_data: string}|null null when callback_data exceeds the limit
     */
    private static function button(string $text, string $callbackData): ?array
    {
        if (strlen($callbackData) > self::CALLBACK_DATA_MAX_BYTES) {
            return null;
        }

        return ['text' => $text, 'callback_data' => $callbackData];
    }
}
