<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * A secret found by the commit gate in an outgoing change. Carries the type and the place, never
 * the value or any part of it.
 */
final readonly class OutgoingSecret
{
    /**
     * @param string      $type   `known:<KEY_NAME>` or the name of a shape pattern
     * @param int         $line   1-based line in the new version of the file
     * @param string|null $commit abbreviated hash for an unpushed commit, null for the staged diff
     */
    public function __construct(
        public string $type,
        public string $path,
        public int $line,
        public ?string $commit = null,
    ) {
    }

    /** `file:line` for the staged diff, `commit:file:line` for an unpushed commit. */
    public function location(): string
    {
        return ($this->commit !== null ? $this->commit.':' : '').$this->path.':'.$this->line;
    }

    /**
     * @return array{type: string, path: string, line: int, commit: ?string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'path' => $this->path, 'line' => $this->line, 'commit' => $this->commit];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['path'] ?? null) ? $data['path'] : '',
            is_int($data['line'] ?? null) ? $data['line'] : 0,
            is_string($data['commit'] ?? null) ? $data['commit'] : null,
        );
    }
}
