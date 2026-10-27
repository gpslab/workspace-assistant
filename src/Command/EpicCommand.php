<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Epic\ChatSurface;

final readonly class EpicCommand implements Command
{
    public function __construct(private ChatSurface $epicChat)
    {
    }

    public function name(): string
    {
        return 'epic';
    }

    public function emoji(): string
    {
        return '🗺️';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $this->epicChat->cmdEpic($request->chatId, $request->threadId, $request->argument);
    }
}
