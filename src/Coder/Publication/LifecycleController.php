<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\PublicationRequest;
use GpsLab\WorkspaceAssistant\Coder\Store;

/** Remote terminal reconciliation and author cancellation for standalone coder jobs. */
final readonly class LifecycleController
{
    /** @var \Closure():int */
    private \Closure $clock;

    /** @param \Closure():int|null $clock */
    public function __construct(
        private Store $store,
        private IdentityRouter $clients,
        private PublicationController $publication,
        private CleanupScheduler $cleanup,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Return done/cancelled only after labels, branch, lease, cursor and cleanup are all handled. */
    public function poll(PublicationRequest $request): ?string
    {
        $job = $this->store->job($request->jobId);
        if ($job === null) {
            throw new \RuntimeException('The coder lifecycle job disappeared.');
        }
        if (in_array($job['state'], [Store::DONE, Store::CANCELLED], true)) {
            if ($job['cleanup_scheduled_at'] === null) {
                // A crash after terminal release but before scheduling remains recoverable.
                $this->cleanup->schedule($request->jobId, $request->worktree);
            }

            return $job['state'];
        }
        if ($job['cancellation_requested']) {
            return $this->finish($request, Store::CANCELLED, 'author cancellation');
        }
        $repository = (string) $job['repository'];
        $issueNumber = $job['issue_number'];
        if (!is_int($issueNumber)) {
            throw new \RuntimeException('A standalone lifecycle job requires an issue.');
        }
        $issue = $this->clients->polling()->issue($repository, $issueNumber);
        $number = $job['pull_request_number'];
        $pr = is_int($number)
            ? $this->clients->polling()->pullRequest($repository, $number)
            : $this->clients->polling()->findPullRequest($repository, $request->branch);
        // A merge may close its linked issue; merge evidence takes precedence over issue closure.
        if ($pr !== null && ($pr['merged'] ?? false) === true) {
            return $this->finish($request, Store::DONE, 'pull request merged');
        }
        if ($pr !== null && ($pr['state'] ?? 'open') === 'closed') {
            return $this->finish($request, Store::CANCELLED, 'pull request closed without merge');
        }
        if (($issue['state'] ?? 'open') === 'closed') {
            return $this->finish($request, Store::CANCELLED, 'issue closed');
        }

        return null;
    }

    public function cancel(PublicationRequest $request, string $reason = 'author cancellation'): string
    {
        if (!$this->store->requestCancellation($request->jobId, $request->workerId, $request->leaseToken, $reason, ($this->clock)())) {
            throw new \RuntimeException('The coder lease expired before author cancellation.');
        }

        return $this->finish($request, Store::CANCELLED, $reason);
    }

    private function finish(PublicationRequest $request, string $state, string $reason): string
    {
        $this->publication->setLifecycleLabel($request, PublicationController::DO_IT, false, true);
        $this->publication->setLifecycleLabel($request, PublicationController::IN_PROGRESS, false, true);
        $this->publication->deleteBranch($request, true);
        if (!$this->store->terminate($request->jobId, $request->workerId, $request->leaseToken, $state, $reason, ($this->clock)())) {
            throw new \RuntimeException('The coder lease was lost before terminal release.');
        }
        $this->cleanup->schedule($request->jobId, $request->worktree, ($this->clock)());

        return $state;
    }
}
