<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** What /cancel of a topic did, for the reply. */
final readonly class CancelResult
{
    /**
     * @param list<array{id:int, chat_id:int, thread_id:int, message_id:int, kind:string, mode:string, locale:?string, parts:list<array<string, mixed>>, placeholder_id:int, closed:bool, running:bool}> $removed queue items removed from the topic
     */
    public function __construct(
        public bool $killed,
        public array $removed,
        public ?string $pausedEpic,
        public bool $limitReset,
        public bool $otherSessionBusy,
    ) {
    }
}
