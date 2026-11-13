<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Exception\SecretFoundException;

/**
 * What the bot does when the commit gate blocks a commit ({@see GitManager::commitAndPush()}): it
 * remembers the "blocked by secret" state, and tells the topic what was found (types and places,
 * never values) with the "Discard" button. The one place all three commit paths go through.
 */
final readonly class SecretBlocker
{
    /** Findings listed in one message or prompt; the rest is counted. */
    public const int MAX_LISTED = 15;

    public function __construct(
        private Database $db,
        private Delivery $delivery,
        private Locale $locale,
    ) {
    }

    /**
     * The findings as `- location (type)` lines, for the chat and for the prompt.
     *
     * @param list<OutgoingSecret> $findings
     */
    public static function lines(array $findings, Locale $locale): string
    {
        $lines = [];
        foreach (array_slice($findings, 0, self::MAX_LISTED) as $finding) {
            $lines[] = '- '.$finding->location().' ('.$finding->type.')';
        }
        if (count($findings) > self::MAX_LISTED) {
            $lines[] = $locale->t('secret.more', ['count' => count($findings) - self::MAX_LISTED]);
        }

        return implode("\n", $lines);
    }

    /** Sets the state and sends the message with the Discard button; the decision record belongs to that message. */
    public function block(int $chatId, int $threadId, SecretFoundException $e): void
    {
        $this->db->setSecretBlock($e->findings);
        Log::line('secrets: commit blocked, '.count($e->findings).' finding(s): '.implode(', ', array_unique(array_map(static fn (OutgoingSecret $f): string => $f->type, $e->findings))));

        $text = $this->locale->t('decision.secret_blocked', ['findings' => self::lines($e->findings, $this->locale)]);
        $markup = Keyboards::discardOnly($this->locale->t('decision.button_discard'))->markup;
        $this->db->clearDecision();
        $landed = $this->delivery->deliverMessage($chatId, $threadId, $text, $markup);
        $this->db->setDecision($chatId, $landed['thread_id'], $landed['message_id']);
    }
}
