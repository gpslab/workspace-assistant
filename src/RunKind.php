<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/** Kinds of a Claude run (not to be confused with the queue item kinds). */
final class RunKind
{
    public const string KIND_CHAT = 'chat';
    public const string KIND_COMPACT_SUMMARIZE = 'compact_summarize';
    public const string KIND_COMPACT_RESEED = 'compact_reseed';
    public const string KIND_EPIC = 'epic';

    private function __construct()
    {
    }
}
