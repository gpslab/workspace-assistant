<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * How a Claude run ended, as {@see JobRunner::poll()} hands it to `Bot` for routing: the run is already
 * dropped from {@see RunState} and its side messages are delivered. A failed run has `$failure` and no `$result`.
 */
final class RunOutcome
{
    /**
     * @param string                             $kind           one of the {@see RunKind} constants
     * @param ClaudeResult|null                  $result         null when the run failed
     * @param int                                $queueId        queue item of the run, 0 if it has none
     * @param bool                               $requeued       the item was put back at the head of the queue
     * @param string|null                        $failure        why the run failed (for the failure handling), null on success
     * @param string|null                        $notice         message for the chat to deliver before the failure is handled
     * @param array{run:string, pr:?string}|null $epicRun
     * @param list<string>                       $promptWarnings
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $chatId,
        public readonly int $threadId,
        public readonly int $queueId,
        public readonly ?ClaudeResult $result,
        public readonly bool $requeued,
        public readonly ?string $failure,
        public readonly ?string $notice,
        public readonly ?string $compactOld,
        public readonly ?string $promptHash,
        public readonly string $locale,
        public readonly ?string $epicSlug,
        public readonly ?array $epicRun,
        public readonly string $prompt,
        public readonly string $mode,
        public readonly array $promptWarnings,
    ) {
    }
}
