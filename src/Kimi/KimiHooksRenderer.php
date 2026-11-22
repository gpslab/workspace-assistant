<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Renders the `[[hooks]]` tables of a Kimi run's `config.toml`.
 *
 * Kimi has no project-level hook file: its hooks live in the config, and they run in `-p`. The run
 * needs two events for the gate shim: `SessionStart` and `PreToolUse`. There is no `matcher`, so
 * every tool goes through the shim; only exit 2 blocks a call.
 *
 * The tables are rendered only for a shim that exists. A missing shim is an error, not a silently
 * absent hook: a run without the gate would look healthy while nothing enforced it.
 *
 * Pure except for the existence check: it returns text and writes nothing.
 */
final class KimiHooksRenderer
{
    /** Seconds the shim may run before Kimi stops waiting and lets the call run. */
    public const int TIMEOUT = 600;

    /** @var list<string> the events the bot's shim handles, in the order they are rendered */
    public const array EVENTS = ['SessionStart', 'PreToolUse'];

    public function __construct(private readonly string $shimPath)
    {
    }

    /**
     * @throws \RuntimeException when the shim file does not exist
     */
    public function render(): string
    {
        if (!is_file($this->shimPath)) {
            throw new \RuntimeException('The Kimi gate shim does not exist: '.$this->shimPath.'.');
        }

        $command = 'php '.$this->shimPath;
        $tables = [];
        foreach (self::EVENTS as $event) {
            $tables[] = "[[hooks]]\nevent = ".self::string($event)."\n"
                .'command = '.self::string($command)."\n"
                .'timeout = '.self::TIMEOUT;
        }

        return implode("\n\n", $tables)."\n";
    }

    /** A TOML basic string. */
    private static function string(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value,
        ).'"';
    }
}
