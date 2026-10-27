<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

/**
 * `git status` failed (non-zero exit, a timeout included), so the state of the working tree is unknown:
 * neither clean nor dirty. The message is git's stderr, or "timeout".
 */
final class GitStatusException extends \RuntimeException
{
}
