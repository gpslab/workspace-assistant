<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** Temporary adapter: a command not moved out of `Bot` yet, registered in its menu position. */
final readonly class CallbackCommand implements Command
{
    /**
     * @param \Closure(CommandRequest): void $handler
     */
    public function __construct(
        private string $name,
        private string $emoji,
        private \Closure $handler,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function emoji(): string
    {
        return $this->emoji;
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        ($this->handler)($request);
    }
}
