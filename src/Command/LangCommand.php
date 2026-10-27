<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\ModeAndLang;

final readonly class LangCommand implements Command
{
    public function __construct(private ModeAndLang $modeAndLang)
    {
    }

    public function name(): string
    {
        return 'lang';
    }

    public function emoji(): string
    {
        return '🌐';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $this->modeAndLang->cmdLang($request->chatId, $request->threadId, $request->userId, $request->argument);
    }
}
