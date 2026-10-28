<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

/** What the commands need from the polling loop owned by `Bot`. */
interface LoopControl
{
    /** Asks the polling loop to end after the current iteration. */
    public function requestStop(): void;
}
