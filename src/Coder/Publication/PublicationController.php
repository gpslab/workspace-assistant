<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\GithubClient;
use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\PublicationRequest;
use GpsLab\WorkspaceAssistant\Coder\Store;

/** The sole standalone coder write path. Workers supply only a leased local request. */
final class PublicationController
{
    public const string DO_IT = 'openronin:do-it';
    public const string IN_PROGRESS = 'openronin:in-progress';
    public const string BOT_MARKER = '<!-- openronin:bot -->';
    /** @var \Closure():int */
    private readonly \Closure $clock;

    /** @param \Closure():int|null $clock */
    public function __construct(
        private readonly Store $store,
        private readonly IdentityRouter $clients,
        private readonly DiffGuard $diff,
        private readonly Config $config = new Config(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return array{pull_request:array<string, mixed>, response:array<string, mixed>} */
    public function publish(PublicationRequest $request, string $title, string $body): array
    {
        $this->gate($request);
        $prefix = $this->prefix($request);
        $this->operation($request, IdentityRouter::PUSH, $prefix.':push', ['head' => $request->head],
            function () use ($request): ?array {
                $remote = $this->github(IdentityRouter::PR_FIND)->branchHead($this->repository($request), $request->branch);
                if ($remote !== null && !hash_equals(strtolower($request->head), $remote)) {
                    throw new \RuntimeException('Coder push rejected: the remote deterministic branch has an unexpected head.');
                }

                return $remote === null ? null : ['head' => $remote];
            },
            function () use ($request): array {
                $this->github(IdentityRouter::PUSH)->push($this->repository($request), $request->branch, $request->worktree, $request->head);

                return ['head' => $request->head];
            },
        );
        $pr = $this->operation($request, IdentityRouter::PR_CREATE, $prefix.':draft-pr', ['title' => $title, 'body' => $body],
            function () use ($request): ?array {
                $existing = $this->github(IdentityRouter::PR_FIND)->findPullRequest($this->repository($request), $request->branch);

                return $existing === null || ($existing['state'] ?? 'open') !== 'open' ? null : $this->associate($request, $existing);
            },
            function () use ($request, $title, $body): array {
                $created = $this->github(IdentityRouter::PR_CREATE)->createDraftPullRequest(
                    $this->repository($request), $request->branch, $this->config->baseBranch, $title, $body,
                );

                return $this->associate($request, $created);
            },
        );
        $number = self::prNumber($pr);
        $pr = $this->operation($request, IdentityRouter::PR_UPDATE, $prefix.':draft-update', ['number' => $number, 'title' => $title, 'body' => $body],
            function () use ($request, $number, $title, $body): ?array {
                $current = $this->github(IdentityRouter::PR_FIND)->pullRequest($this->repository($request), $number);
                if ($current === null) {
                    throw new \RuntimeException('The associated coder pull request disappeared during reconciliation.');
                }

                return ($current['draft'] ?? false) === true
                    && (($current['title'] ?? null) !== $title || ($current['body'] ?? null) !== $body) ? null : $current;
            },
            fn (): array => $this->github(IdentityRouter::PR_UPDATE)->updateDraftPullRequest($this->repository($request), $number, $title, $body),
        );
        $this->setLifecycleLabel($request, self::IN_PROGRESS, true);
        $this->setLifecycleLabel($request, self::DO_IT, false);
        $response = $this->respond($request, "Draft pull request #{$number} is ready for review orchestration.");
        if (!$this->store->transition($request->jobId, Store::WAITING_CI_REVIEW, $request->workerId, $request->leaseToken, ($this->clock)())) {
            throw new \RuntimeException('The coder lease was lost after publication; reconciliation is required before continuing.');
        }

        return ['pull_request' => $pr, 'response' => $response];
    }

    public function markReady(PublicationRequest $request): void
    {
        $number = $this->associatedPr($request);
        $this->operation($request, IdentityRouter::READY, $this->prefix($request).':ready', ['number' => $number],
            function () use ($request, $number): ?array {
                $pr = $this->github(IdentityRouter::PR_FIND)->pullRequest($this->repository($request), $number);
                if ($pr === null) {
                    throw new \RuntimeException('The coder pull request disappeared before readiness reconciliation.');
                }

                return ($pr['draft'] ?? true) ? null : ['number' => $number, 'ready' => true];
            },
            function () use ($request, $number): array {
                $this->github(IdentityRouter::READY)->markReady($this->repository($request), $number);

                return ['number' => $number, 'ready' => true];
            },
        );
    }

    public function deleteBranch(PublicationRequest $request, bool $terminalCleanup = false): void
    {
        $this->operation($request, IdentityRouter::DELETE_BRANCH, $this->prefix($request).':delete-branch', ['branch' => $request->branch],
            function () use ($request): ?array {
                $remote = $this->github(IdentityRouter::PR_FIND)->branchHead($this->repository($request), $request->branch);
                if ($remote !== null && !hash_equals(strtolower($request->head), $remote)) {
                    throw new \RuntimeException('Coder branch deletion rejected: the remote head is unexpected.');
                }

                return $remote === null ? ['deleted' => true] : null;
            },
            function () use ($request): array {
                $this->github(IdentityRouter::DELETE_BRANCH)->deleteBranch($this->repository($request), $request->branch, $request->head);

                return ['deleted' => true];
            },
            $terminalCleanup,
        );
    }

    /** Add or remove a standalone lifecycle label through the coder identity. */
    public function setLifecycleLabel(PublicationRequest $request, string $label, bool $present, bool $terminalCleanup = false): void
    {
        if (!in_array($label, [self::DO_IT, self::IN_PROGRESS], true)) {
            throw new \InvalidArgumentException('Only standalone coder lifecycle labels are controller-managed.');
        }
        $issue = $this->issue($request);
        $action = $present ? 'add' : 'remove';
        $phase = $terminalCleanup ? 'terminal-label' : 'label';
        $this->operation($request, IdentityRouter::LABEL, $this->prefix($request).":{$phase}:{$action}:{$label}",
            ['issue' => $issue, 'label' => $label, 'present' => $present],
            function () use ($request, $issue, $label, $present): ?array {
                $labels = $this->github(IdentityRouter::PR_FIND)->issue($this->repository($request), $issue)['_labels'] ?? [];
                $found = in_array($label, $labels, true);

                return $found === $present ? ['label' => $label, 'present' => $present] : null;
            },
            function () use ($request, $issue, $label, $present): array {
                $github = $this->github(IdentityRouter::LABEL);
                $present ? $github->addLabel($this->repository($request), $issue, $label)
                    : $github->removeLabel($this->repository($request), $issue, $label);

                return ['label' => $label, 'present' => $present];
            },
            $terminalCleanup,
        );
    }

    /** Publish one marked, idempotent coder response.
     * @return array<string, mixed>
     */
    public function respond(PublicationRequest $request, string $message): array
    {
        $issue = $this->issue($request);
        $key = $this->prefix($request).':response:'.hash('sha256', $message);
        $marker = '<!-- openronin:response:'.hash('sha256', $key).' -->';
        $body = self::BOT_MARKER."\n".$marker."\n".$message;

        return $this->operation($request, IdentityRouter::COMMENT, $key, ['issue' => $issue, 'marker' => $marker],
            function () use ($request, $issue, $marker): ?array {
                foreach ($this->github(IdentityRouter::PR_FIND)->comments($this->repository($request), $issue) as $comment) {
                    if (str_contains((string) ($comment['body'] ?? ''), $marker)) {
                        return $comment;
                    }
                }

                return null;
            },
            fn (): array => $this->github(IdentityRouter::COMMENT)->comment($this->repository($request), $issue, $body),
        );
    }

    /** @param array<string, mixed> $payload
     * @param \Closure():?array<string, mixed> $reconcile
     * @param \Closure():array<string, mixed>  $write
     *
     * @return array<string, mixed>
     */
    private function operation(
        PublicationRequest $request,
        string $action,
        string $key,
        array $payload,
        \Closure $reconcile,
        \Closure $write,
        bool $terminalCleanup = false,
    ): array {
        $repository = $this->repository($request);
        $record = $this->store->beginPublication($repository, $key, $request->jobId, $action, $payload, ($this->clock)());
        if ($record['job_id'] !== $request->jobId || $record['action'] !== $action || $record['payload'] !== $payload) {
            throw new \RuntimeException('A persisted coder publication key belongs to another action or payload.');
        }
        if ($record['state'] === 'confirmed') {
            return $record['result'] ?? [];
        }

        // This read always precedes a first write and every pending/uncertain retry.
        $existing = $reconcile();
        if ($existing !== null) {
            $this->store->confirmPublication($repository, $key, $existing, ($this->clock)());

            return $existing;
        }
        $this->gate($request, $terminalCleanup);
        try {
            $result = $write();
        } catch (\Throwable $e) {
            $this->store->markPublicationUncertain($repository, $key, ($this->clock)());
            throw new \RuntimeException("Coder {$action} outcome is uncertain; GitHub reconciliation is required before retry.", 0, $e);
        }
        $this->store->confirmPublication($repository, $key, $result, ($this->clock)());

        return $result;
    }

    /** Recheck lease and request identity; ordinary writes also recheck stored/worktree heads and the complete diff. */
    private function gate(PublicationRequest $request, bool $terminalCleanup = false): void
    {
        $job = $this->store->assertPublicationLease(
            $request->jobId, $request->workerId, $request->leaseToken, $request->head, ($this->clock)(), $terminalCleanup,
        );
        if ($job['branch'] !== $request->branch || $job['worktree'] !== $request->worktree
            || ($job['diagnostics']['local_result']['lease_generation'] ?? null) !== $request->leaseGeneration) {
            throw new \RuntimeException('Coder publication rejected: the local request identity is stale.');
        }
        if ($terminalCleanup) {
            return;
        }
        $baseHead = $job['diagnostics']['base_head'] ?? null;
        if (!is_string($baseHead)) {
            throw new \RuntimeException('Coder publication rejected: no validated base head was persisted.');
        }
        $this->diff->validate($request->worktree, $baseHead, $request->head);
    }

    /** @param array<string, mixed> $pr
     * @return array<string, mixed>
     */
    private function associate(PublicationRequest $request, array $pr): array
    {
        $number = self::prNumber($pr);
        if (!$this->store->bindPullRequest(
            $request->jobId, $request->workerId, $request->leaseToken, $request->head, $number, ($this->clock)(),
        )) {
            throw new \RuntimeException('The existing pull request could not be associated without a valid coder lease.');
        }

        return $pr;
    }

    private function associatedPr(PublicationRequest $request): int
    {
        $number = $this->store->job($request->jobId)['pull_request_number'] ?? null;

        return is_int($number) ? $number : throw new \RuntimeException('The coder job has no associated pull request.');
    }

    private function repository(PublicationRequest $request): string
    {
        $repository = $this->store->job($request->jobId)['repository'] ?? null;

        return is_string($repository) ? $repository : throw new \RuntimeException('The coder publication job disappeared.');
    }

    private function issue(PublicationRequest $request): int
    {
        $number = $this->store->job($request->jobId)['issue_number'] ?? null;

        return is_int($number) ? $number : throw new \RuntimeException('A standalone coder publication requires an issue.');
    }

    private function github(string $action): GithubClient
    {
        return $this->clients->forAction($action);
    }

    private function prefix(PublicationRequest $request): string
    {
        return "coder-publication:{$request->jobId}:{$request->head}";
    }

    /** @param array<string, mixed> $pr */
    private static function prNumber(array $pr): int
    {
        $number = $pr['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : throw new \RuntimeException('GitHub returned no valid pull request number.');
    }
}
