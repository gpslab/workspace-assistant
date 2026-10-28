<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** What the queue commands need from the queue owned by `QueueScheduler`. */
interface QueueControl
{
    /** The topic has an active or pending item. */
    public function topicBusy(int $chatId, int $threadId): bool;

    /** Puts a `/new` barrier item into the queue: requests sent before it run in the old session. */
    public function enqueueNew(int $chatId, int $threadId, int $messageId, string $mode, string $localeCode): void;

    /** Puts a `/compact` item into the queue. */
    public function enqueueCompact(int $chatId, int $threadId, int $messageId, string $localeCode): void;

    /** Drops the session of the topic and its transcript, and tells the topic about it. */
    public function resetTopicSession(int $chatId, int $threadId): void;

    /**
     * Pending items (not started) in FIFO order.
     *
     * @return list<array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}>
     */
    public function waiting(): array;

    /** The queue pause reason or null. */
    public function pauseReason(): ?string;
}
