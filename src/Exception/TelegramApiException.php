<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

/**
 * Telegram accepted the request and replied with a refusal (`ok: false`) or
 * returned an unreadable body.
 *
 * Key property: the outcome is KNOWN, the message was definitely not created.
 * Only on such an error is it safe to retry the send elsewhere (fallback to
 * the chat root when the topic is closed/deleted): there will be no duplicate.
 */
final class TelegramApiException extends TelegramException
{
    public function __construct(
        public readonly string $method,
        public readonly string $description,
    ) {
        parent::__construct("Telegram API error in {$method}: {$description}");
    }
}
