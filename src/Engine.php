<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Names of the agent engines as they are shown in rendered text.
 *
 * The engine is a property of the instance ({@see Config::$engine}). Text that is rendered for the
 * model (the assembled system prompt, the deny reason of the PM gate) carries the `{engine}`
 * placeholder, filled by {@see SystemPrompt} and {@see PmGateHook}; the raw runbooks are read by the
 * model itself and stay engine-neutral.
 */
final class Engine
{
    /** @var array<string, string> */
    public const array LABELS = [
        Config::DEFAULT_ENGINE_FINGERPRINT => 'Claude Code',
        Config::ENGINE_OPENCODE => 'OpenCode',
    ];

    /** The display name of an engine; an unknown value is shown as it is. */
    public static function label(string $engine): string
    {
        return self::LABELS[$engine] ?? $engine;
    }
}
