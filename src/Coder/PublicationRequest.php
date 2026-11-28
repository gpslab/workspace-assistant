<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** A local-only hand-off. Only the controller may turn it into forge writes. */
final readonly class PublicationRequest
{
    public function __construct(
        public int $jobId,
        public string $runId,
        public string $branch,
        public string $worktree,
        public string $head,
        public string $sessionId,
        public int $workerId,
        public string $leaseToken,
        public int $leaseGeneration,
    ) {
    }

    /** The persisted diagnostic form deliberately excludes the unguessable lease token.
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'kind' => 'publication_request', 'job_id' => $this->jobId, 'run_id' => $this->runId,
            'branch' => $this->branch, 'worktree' => $this->worktree,
            'head' => $this->head, 'session_id' => $this->sessionId,
            'worker_id' => $this->workerId, 'lease_generation' => $this->leaseGeneration,
        ];
    }
}
