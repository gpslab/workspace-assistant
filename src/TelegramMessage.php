<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Helpers for reading an incoming Telegram message.
 */
final class TelegramMessage
{
    /**
     * The forum topic of an incoming message. A real topic is flagged is_topic_message
     * AND carries message_thread_id. The General topic and a 1:1 chat arrive without that flag
     * → session 0 (the chat's default session). Replies outside topics may also carry
     * message_thread_id, so we check precisely is_topic_message.
     *
     * @param array<string, mixed> $message
     */
    public static function threadIdOf(array $message): int
    {
        if (($message['is_topic_message'] ?? false) === true && isset($message['message_thread_id'])) {
            return (int) $message['message_thread_id'];
        }

        return 0;
    }
}
