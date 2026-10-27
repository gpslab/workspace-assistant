<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

use GpsLab\WorkspaceAssistant\Epic\ChatSurface;

/** `epic:` keyboard of /epic. Stateless: the topic and the epic slug are carried in the data. */
final readonly class EpicHandler implements Handler
{
    public function __construct(private ChatSurface $epicChat)
    {
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'epic:');
    }

    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        $this->epicChat->handleEpicCallback($callbackId, $chatId, $message, $data);
    }
}
