<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Locale;

/**
 * Messages to an epic's topic and the single transition of an epic to `blocked`.
 */
final class EpicNotifier
{
    public function __construct(
        private readonly Database $db,
        private readonly Delivery $delivery,
        private readonly Card $card,
        private readonly Locale $locale,
    ) {
    }

    /**
     * Single transition of an epic to `blocked`: status, reason and exactly one
     * message to the epic's topic. All stops go through it (run signal, task
     * and merge-gate stops); callers send no stop messages of their own.
     */
    public function blockEpic(string $slug, string $reason): void
    {
        $epic = $this->db->epicGet($slug);
        if ($epic === null) {
            return;
        }
        $this->db->epicBlock($slug, $reason);
        $this->card->update($slug);
        $this->delivery->deliver($epic['chat_id'], $epic['thread_id'], $this->locale->t('epic.blocked', ['reason' => $reason]));
    }

    /** Message to the epic topic (slug, text); nothing when the epic is gone. */
    public function notifyEpic(string $slug, string $text): void
    {
        $epic = $this->db->epicGet($slug);
        if ($epic !== null) {
            $this->delivery->deliver($epic['chat_id'], $epic['thread_id'], "⚠️ {$text}");
        }
    }
}
