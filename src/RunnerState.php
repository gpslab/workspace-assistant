<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * State of an asynchronous Claude run.
 */
enum RunnerState: string
{
    case Running = 'running';
    case Done = 'done';
    case Timeout = 'timeout';
}
