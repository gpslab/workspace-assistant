<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/**
 * Compact the session instead of a full reset: ask Claude to write a summary of the current session,
 * then open a new session with that summary in the first message (a two-phase task, see `Bot`).
 * /compact is a claude run: it takes the queue like a regular item; "is there anything to compact"
 * is checked when the item starts.
 */
final readonly class CompactCommand implements Command
{
    public function __construct(
        private QueueControl $queue,
        private LocaleResolver $locales,
    ) {
    }

    public function name(): string
    {
        return 'compact';
    }

    public function emoji(): string
    {
        return '🗜️';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $this->queue->enqueueCompact($request->chatId, $request->threadId, $request->messageId, $this->locales->userLocale($request->userId));
    }
}
