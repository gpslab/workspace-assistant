<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

final readonly class LocalResult
{
    public const string PUBLICATION_REQUESTED = 'publication_requested';
    public const string CANCELLED = 'cancelled';
    public const string FAILED = 'failed';
    public const string LEASE_LOST = 'lease_lost';

    /** @param array<string, mixed> $details */
    public function __construct(
        public string $status,
        public int $jobId,
        public string $runId,
        public string $branch,
        public string $worktree,
        public ?string $head,
        public ?string $sessionId,
        public array $details = [],
    ) {
    }

    public function publicationRequest(): ?PublicationRequest
    {
        if ($this->status !== self::PUBLICATION_REQUESTED || $this->head === null || $this->sessionId === null) {
            return null;
        }

        return new PublicationRequest($this->jobId, $this->runId, $this->branch, $this->worktree, $this->head, $this->sessionId);
    }
}
