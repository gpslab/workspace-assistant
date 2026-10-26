<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

/**
 * Red `master` CI of a repository on which the bot restarted the failed jobs.
 * Stored in kv per epic and repository. `kind` lifecycle: rerun (restart
 * done, result unknown) → flaky (green after the restart) | broken (red
 * after the restart, a confirmed breakage).
 */
final class MasterRecord
{
    public const string RERUN = 'rerun';
    public const string FLAKY = 'flaky';
    public const string BROKEN = 'broken';

    /**
     * @param list<array{id: int, url: string, workflow: string}> $runs      failed CI runs on the SHA
     * @param list<string>                                        $tasksBase epic tasks at the moment the breakage was confirmed
     * @param int                                                 $ticksBase number of enqueued epic ticks at the moment of confirmation
     * @param int                                                 $redSince  moment the breakage was confirmed (0 — not confirmed)
     * @param bool                                                $prompted  the flakiness has already made it into a tick prompt
     */
    public function __construct(
        public string $repo,
        public string $sha,
        public array $runs,
        public int $at,
        public string $kind = self::RERUN,
        public bool $pendingSeen = false,
        public bool $prompted = false,
        public int $redSince = 0,
        public int $ticksBase = 0,
        public array $tasksBase = [],
        public bool $fixFound = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{id: int, url: string, workflow: string}> $runs */
        $runs = is_array($data['runs'] ?? null) ? array_values($data['runs']) : [];
        /** @var list<string> $tasksBase */
        $tasksBase = is_array($data['tasksBase'] ?? null) ? array_values($data['tasksBase']) : [];

        return new self(
            (string) ($data['repo'] ?? ''),
            (string) ($data['sha'] ?? ''),
            $runs,
            (int) ($data['at'] ?? 0),
            (string) ($data['kind'] ?? self::RERUN),
            (bool) ($data['pendingSeen'] ?? false),
            (bool) ($data['prompted'] ?? false),
            (int) ($data['redSince'] ?? 0),
            (int) ($data['ticksBase'] ?? 0),
            $tasksBase,
            (bool) ($data['fixFound'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** The restart result can be considered final: CI managed to go PENDING or the grace period passed. */
    public function settled(int $now, int $grace): bool
    {
        return $this->pendingSeen || $now - $this->at >= $grace;
    }
}
