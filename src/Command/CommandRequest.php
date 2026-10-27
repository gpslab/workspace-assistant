<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** What a command is called with: where the message came from and the text after the command. */
final readonly class CommandRequest
{
    public function __construct(
        public int $chatId,
        public int $threadId,
        public string $argument = '',
        public int $messageId = 0,
        public int $userId = 0,
    ) {
    }
}
