<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Token counters and cost exactly as Claude Code reports them (`modelUsage` summed over all
 * models, `total_cost_usd`). From a `result` event this is a cumulative session snapshot;
 * {@see self::minus()} turns two snapshots into the figure of one run. The cost is null when
 * Claude did not report it: unknown, never 0.
 */
final class UsageTotals
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $cacheCreationTokens,
        public readonly int $cacheReadTokens,
        public readonly int $outputTokens,
        public readonly ?float $costUsd = null,
    ) {
    }

    /**
     * The snapshot of a `result` event, or null when it carries no `modelUsage` totals.
     *
     * @param array<string, mixed> $event
     */
    public static function fromResultEvent(array $event): ?self
    {
        $modelUsage = $event['modelUsage'] ?? null;
        if (!is_array($modelUsage) || $modelUsage === []) {
            return null;
        }

        $sum = ['inputTokens' => 0, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0, 'outputTokens' => 0];
        foreach ($modelUsage as $usage) {
            if (!is_array($usage)) {
                continue;
            }
            foreach ($sum as $key => $_) {
                $sum[$key] += (int) ($usage[$key] ?? 0);
            }
        }

        $cost = $event['total_cost_usd'] ?? null;

        return new self(
            $sum['inputTokens'],
            $sum['cacheCreationInputTokens'],
            $sum['cacheReadInputTokens'],
            $sum['outputTokens'],
            is_int($cost) || is_float($cost) ? (float) $cost : null,
        );
    }

    /**
     * The figure of one run: this snapshot minus the previous one. The previous snapshot counts
     * as zero when the totals went down (a new session or a reset on Claude's side).
     */
    public function minus(?self $previous): self
    {
        if ($previous === null || $this->isBelow($previous)) {
            return $this;
        }

        return new self(
            $this->inputTokens - $previous->inputTokens,
            $this->cacheCreationTokens - $previous->cacheCreationTokens,
            $this->cacheReadTokens - $previous->cacheReadTokens,
            $this->outputTokens - $previous->outputTokens,
            $this->costUsd !== null && $previous->costUsd !== null ? $this->costUsd - $previous->costUsd : $this->costUsd,
        );
    }

    private function isBelow(self $previous): bool
    {
        return ($this->costUsd !== null && $previous->costUsd !== null && $this->costUsd < $previous->costUsd)
            || $this->inputTokens < $previous->inputTokens
            || $this->cacheCreationTokens < $previous->cacheCreationTokens
            || $this->cacheReadTokens < $previous->cacheReadTokens
            || $this->outputTokens < $previous->outputTokens;
    }

    /** `0.0043` below $1, `1.23` from $1. */
    public static function formatCost(float $cost): string
    {
        return number_format($cost, $cost < 1 ? 4 : 2, '.', '');
    }
}
