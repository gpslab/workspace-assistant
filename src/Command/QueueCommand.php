<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Queue\QueueTexts;
use GpsLab\WorkspaceAssistant\TelegramClient;

/** /queue: queue items, their topics and the pause reason (if the queue is paused). */
final readonly class QueueCommand implements Command
{
    public function __construct(
        private TelegramClient $tg,
        private Locale $locale,
        private QueueControl $queue,
        private RunControl $run,
        private QueueTexts $texts,
    ) {
    }

    public function name(): string
    {
        return 'queue';
    }

    public function emoji(): string
    {
        return '📋';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $lines = [];
        $current = $this->run->currentRun();
        if ($current !== null) {
            $lines[] = $this->locale->t('queue.running', ['topic' => $this->texts->topicLabel($current['chat_id'], $current['thread_id'])]);
        }

        $waiting = $this->queue->waiting();
        foreach ($waiting as $i => $item) {
            $lines[] = sprintf('%d. %s — %s [%s]', $i + 1, $this->texts->topicLabel($item['chat_id'], $item['thread_id']), $this->texts->describeItem($item), $item['mode']);
        }

        if ($lines === []) {
            $this->tg->sendText($request->chatId, $this->locale->t('queue.empty'), null, $request->threadId);

            return;
        }

        $text = $this->locale->t('queue.header', ['count' => count($waiting)])."\n".implode("\n", $lines);
        $reason = $this->queue->pauseReason();
        if ($reason !== null) {
            $text .= "\n\n".$this->locale->t('queue.paused', ['reason' => $reason]);
        }

        $this->tg->sendText($request->chatId, $text, null, $request->threadId);
    }
}
