<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;

/** /stats: the mode, the queue position and the session counters of the topic. */
final readonly class StatsCommand implements Command
{
    public function __construct(
        private Database $db,
        private TelegramClient $tg,
        private Locale $locale,
        private QueueControl $queue,
        private RunControl $run,
    ) {
    }

    public function name(): string
    {
        return 'stats';
    }

    public function emoji(): string
    {
        return '📊';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $chatId = $request->chatId;
        $threadId = $request->threadId;

        // The mode line goes first regardless of the outcome below — a forgotten mode
        // silently trims replies, and /stats is often checked right after /new, when there is
        // no active session yet. One message in both cases.
        $modeLine = $this->locale->t('stats.mode', ['mode' => $this->db->getMode($chatId, $threadId)]);
        $position = $this->queuePosition($chatId, $threadId);
        $modeLine .= "\n".$this->locale->t('stats.queue', ['state' => $position === null
            ? $this->locale->t('stats.queue_none')
            : ($position === 1 && $this->run->topicHasActiveJob($chatId, $threadId)
                ? $this->locale->t('stats.queue_running')
                : $this->locale->t('queue.position', ['position' => $position]))]);

        $row = $this->db->getSession($chatId, $threadId);
        if ($row === null || ($row['session_id'] ?? null) === null) {
            $this->tg->sendText($chatId, $modeLine."\n".$this->locale->t('stats.no_session'), null, $threadId);

            return;
        }

        $sessionId = (string) $row['session_id'];
        $lines = [
            $modeLine,
            $this->locale->t('stats.session', ['id' => substr($sessionId, 0, 8)]),
            $this->locale->t('stats.messages', ['count' => (int) $row['message_count']]),
            $this->locale->t('stats.tokens_in', ['count' => number_format((int) $row['input_tokens'], 0, '.', ' ')]),
            $this->locale->t('stats.tokens_out', ['count' => number_format((int) $row['output_tokens'], 0, '.', ' ')]),
            $this->locale->t('stats.verbose', ['state' => ((int) $row['verbose']) === 1 ? $this->locale->t('common.on') : $this->locale->t('common.off')]),
        ];

        $this->tg->sendText($chatId, implode("\n", $lines), null, $threadId);
    }

    /** Topic's queue position: 1 — running now or first in the queue; null — the topic has no items. */
    private function queuePosition(int $chatId, int $threadId): ?int
    {
        if ($this->run->topicHasActiveJob($chatId, $threadId)) {
            return 1;
        }

        $offset = $this->run->currentRun() !== null ? 1 : 0;
        foreach ($this->queue->waiting() as $i => $item) {
            if ($item['chat_id'] === $chatId && $item['thread_id'] === $threadId) {
                return $i + 1 + $offset;
            }
        }

        return null;
    }
}
