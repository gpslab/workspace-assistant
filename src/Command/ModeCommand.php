<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\ModeAndLang;

final readonly class ModeCommand implements Command
{
    public function __construct(private ModeAndLang $modeAndLang)
    {
    }

    public function name(): string
    {
        return 'mode';
    }

    public function emoji(): string
    {
        return '🎭';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $this->modeAndLang->cmdMode($request->chatId, $request->threadId, $request->argument);
    }
}
