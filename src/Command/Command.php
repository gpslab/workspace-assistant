<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** A bot command (`/name`): its menu entry and its handler. */
interface Command
{
    /** Command name without the slash; the `command.<name>` catalog key is its menu description. */
    public function name(): string;

    /** Emoji prefixed to the menu description; the same in every locale. */
    public function emoji(): string;

    /**
     * Other names the command answers to (without the slash); they are not in the menu.
     *
     * @return list<string>
     */
    public function aliases(): array;

    public function execute(CommandRequest $request): void;
}
