<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The result of {@see KimiGatedPreconditions::check()}: ok, or the reasons a gated mode may not run
 * on Kimi. Each reason is an English text meant for `bot.log`; the chat texts of the refusal arrive
 * with the task that connects this predicate to the refusal.
 */
final class KimiGatedPreconditionResult
{
    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly array $reasons,
    ) {
    }

    public static function ok(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $reasons
     */
    public static function failed(array $reasons): self
    {
        return new self($reasons);
    }

    public function isOk(): bool
    {
        return $this->reasons === [];
    }
}
