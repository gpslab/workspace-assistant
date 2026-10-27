<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Database;

/**
 * /new: if the topic has an active or pending item — it joins the queue as a
 * barrier (a request sent before /new runs in the old session); if the topic
 * is idle — an instant reset.
 */
final readonly class NewCommand implements Command
{
    public function __construct(
        private Database $db,
        private QueueControl $queue,
        private LocaleResolver $locales,
    ) {
    }

    public function name(): string
    {
        return 'new';
    }

    public function emoji(): string
    {
        return '🆕';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        if ($this->queue->topicBusy($request->chatId, $request->threadId)) {
            $this->queue->enqueueNew(
                $request->chatId,
                $request->threadId,
                $request->messageId,
                $this->db->getMode($request->chatId, $request->threadId),
                $this->locales->userLocale($request->userId),
            );

            return;
        }

        $this->queue->resetTopicSession($request->chatId, $request->threadId);
    }
}
