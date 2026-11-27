<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** A local-only hand-off. A later, separate stage may publish it. */
final readonly class PublicationRequest
{
    public function __construct(
        public int $jobId,
        public string $runId,
        public string $branch,
        public string $worktree,
        public string $head,
        public string $sessionId,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'kind' => 'publication_request', 'job_id' => $this->jobId, 'run_id' => $this->runId,
            'branch' => $this->branch, 'worktree' => $this->worktree,
            'head' => $this->head, 'session_id' => $this->sessionId,
        ];
    }
}
