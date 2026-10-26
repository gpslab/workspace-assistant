<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Outcome of {@see TelegramClient::editMessageText()}. The method used to return void and
 * swallowed all errors; the queue needs to tell "the message/topic is gone"
 * (the item is removed, claude is not started) from a transient failure (retry).
 */
enum EditStatus
{
    /** The message was edited (or Telegram reported that the text did not change). */
    case Ok;

    /** The message or topic is gone — nothing to edit. */
    case Gone;

    /** Any other failure (network, rate limits): the outcome is unknown, a retry is acceptable. */
    case Failed;

    /**
     * Parsing the Bot API error description. "message is not modified" is not a failure:
     * the placeholder already contains the needed text.
     */
    public static function fromApiDescription(string $description): self
    {
        $d = strtolower($description);
        if (str_contains($d, 'message is not modified')) {
            return self::Ok;
        }

        foreach (['message to edit not found', 'message not found', 'message_thread_not_found', 'thread not found', 'topic_deleted', 'topic deleted', 'chat not found'] as $marker) {
            if (str_contains($d, $marker)) {
                return self::Gone;
            }
        }

        return self::Failed;
    }
}
