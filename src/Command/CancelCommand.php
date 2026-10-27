<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Callback\DirtyTreeDecision;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * /cancel from topic X: kills X's active job and removes ALL pending
 * items of X (otherwise the topic's next request would start in the context the
 * person just rejected). Other topics and chats are
 * never affected. There is no auto-commit/auto-rollback: with AUTO_COMMIT=1 the decision on a dirty
 * tree is made by the person with buttons (with 0 there is none).
 */
final readonly class CancelCommand implements Command
{
    public function __construct(
        private Database $db,
        private TelegramClient $tg,
        private Delivery $delivery,
        private Locale $locale,
        private RunControl $run,
        private QueueTexts $queueTexts,
        private DirtyTreeDecision $decision,
    ) {
    }

    public function name(): string
    {
        return 'cancel';
    }

    public function emoji(): string
    {
        return '⛔';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $chatId = $request->chatId;
        $threadId = $request->threadId;
        $result = $this->run->cancelTopic($chatId, $threadId);
        $killed = $result->killed;
        $removed = $result->removed;
        $pausedEpic = $result->pausedEpic;

        if (!$killed && $removed === []) {
            $this->tg->sendText(
                $chatId,
                $pausedEpic !== null
                    ? $this->locale->t('cancel.epic_paused_queue_empty', ['slug' => $pausedEpic])
                    : ($result->otherSessionBusy
                        ? $this->locale->t('cancel.other_session_busy')
                        : $this->locale->t('cancel.nothing')),
                null,
                $threadId,
            );

            return;
        }

        $lines = [];
        foreach ($removed as $item) {
            $this->tg->editMessageText($item['chat_id'], $item['placeholder_id'], $this->locale->t('cancel.item_removed'));
            $lines[] = '• '.$this->queueTexts->describeItem($item);
        }

        $text = $killed ? $this->locale->t('cancel.process_stopped') : '';
        if ($result->limitReset) {
            $text .= ($text !== '' ? "\n" : '').$this->locale->t('cancel.limit_pause_reset');
        }
        if ($lines !== []) {
            $text .= ($text !== '' ? "\n" : '').$this->locale->t('cancel.removed_from_queue', ['count' => count($lines)])."\n".implode("\n", $lines);
        }
        if ($pausedEpic !== null) {
            $text .= ($text !== '' ? "\n" : '').$this->locale->t('cancel.epic_paused', ['slug' => $pausedEpic]);
        }
        $this->delivery->deliver($chatId, $threadId, $text);

        if ($killed) {
            $this->db->clearDecision();
            $this->decision->offerDecisionIfDirty($chatId, $threadId);
        }
    }
}
