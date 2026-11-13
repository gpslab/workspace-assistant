<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\ModelSwitcher;

final readonly class ModelCommand implements Command
{
    public function __construct(private ModelSwitcher $switcher)
    {
    }

    public function name(): string
    {
        return 'model';
    }

    public function emoji(): string
    {
        return '🤖';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $this->switcher->cmdModel($request->chatId, $request->threadId, $request->argument);
    }
}
