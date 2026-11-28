<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Env;

/** Exclusive repository-to-executor routing. Unlisted repositories are never coder-routed. */
final readonly class Routing
{
    public const string OPENRONIN = 'openronin';
    public const string CODER = 'coder';

    /** @var array<string, string> lowercase owner/repository => executor */
    private array $executors;

    /** @param array<string, string> $executors owner/repository => openronin|coder */
    public function __construct(array $executors = [])
    {
        $normalized = [];
        foreach ($executors as $repository => $executor) {
            $repository = self::validateRepository($repository);
            if (!in_array($executor, [self::OPENRONIN, self::CODER], true)) {
                throw new \InvalidArgumentException("Unknown executor '{$executor}' for {$repository}: expected openronin or coder.");
            }
            $key = strtolower($repository);
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException("Repository {$repository} has more than one executor assignment.");
            }
            $normalized[$key] = $executor;
        }
        ksort($normalized);
        $this->executors = $normalized;
    }

    public static function fromEnv(Env $env): self
    {
        $assignments = [];
        $seen = [];
        foreach ($env->list('REPOSITORY_EXECUTORS') as $assignment) {
            $parts = explode(':', $assignment);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException("Invalid REPOSITORY_EXECUTORS assignment '{$assignment}': expected owner/repository:openronin or owner/repository:coder.");
            }
            $repository = self::validateRepository(trim($parts[0]));
            $key = strtolower($repository);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Repository {$repository} has duplicate/conflicting executor assignments in REPOSITORY_EXECUTORS.");
            }
            $seen[$key] = true;
            $assignments[$repository] = trim($parts[1]);
        }

        return new self($assignments);
    }

    /** @return list<string> explicitly coder-routed repositories, lowercase and in stable order */
    public function coderRepositories(): array
    {
        return array_keys(array_filter($this->executors, static fn (string $executor): bool => $executor === self::CODER));
    }

    public function isEmpty(): bool
    {
        return $this->executors === [];
    }

    public function isCoderRouted(string $repository): bool
    {
        return ($this->executors[strtolower(trim($repository))] ?? null) === self::CODER;
    }

    private static function validateRepository(string $repository): string
    {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$~', $repository) !== 1) {
            throw new \InvalidArgumentException("Invalid routed repository '{$repository}': expected owner/repository.");
        }

        return $repository;
    }
}
