<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** What the commands need from the run owned by `JobRunner`. */
interface RunControl
{
    /**
     * The topic of the running job, or null when nothing runs.
     *
     * @return array{chat_id:int, thread_id:int}|null
     */
    public function currentRun(): ?array;

    /** Whether the topic has an active job. */
    public function topicHasActiveJob(int $chatId, int $threadId): bool;

    /**
     * Cancels the topic's own work: kills its active job (never another topic's) and removes all its queue
     * items; pauses the topic's running epic and clears the Claude limit pause when something was removed.
     */
    public function cancelTopic(int $chatId, int $threadId): CancelResult;
}
