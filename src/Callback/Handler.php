<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

/** A handler of inline keyboard callbacks; the first one in the list that supports the data wins. */
interface Handler
{
    /** Whether the handler takes a callback with this `callback_data`. */
    public function supports(string $data): bool;

    /**
     * @param array<string, mixed> $message the message the keyboard is attached to
     */
    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void;
}
