<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * An inline keyboard built by {@see Keyboards}: the `reply_markup` and whether a button was left out
 * because its `callback_data` does not fit Telegram's 64-byte limit. What to do about a skipped button
 * is up to the caller.
 */
final readonly class Keyboard
{
    /**
     * @param array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} $markup
     */
    public function __construct(
        public array $markup,
        public bool $skipped = false,
    ) {
    }

    /** Whether the keyboard has no buttons. */
    public function isEmpty(): bool
    {
        foreach ($this->markup['inline_keyboard'] as $row) {
            if ($row !== []) {
                return false;
            }
        }

        return true;
    }
}
