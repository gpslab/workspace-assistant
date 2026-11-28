<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** Compile-time-style action routing: standalone coder writes can never select the reviewer client. */
final readonly class IdentityRouter
{
    public const string PUSH = 'coder_push';
    public const string DELETE_BRANCH = 'coder_delete_branch';
    public const string PR_CREATE = 'coder_pr_create';
    public const string PR_FIND = 'coder_pr_find';
    public const string PR_UPDATE = 'coder_pr_update';
    public const string READY = 'coder_ready_for_review';
    public const string LABEL = 'coder_lifecycle_label';
    public const string COMMENT = 'coder_comment';
    public const string REVIEW = 'reviewer_review';
    public const string EPIC = 'reviewer_epic_operation';

    public function __construct(
        public GithubClient $reviewer,
        public GithubClient $coder,
    ) {
    }

    public function forAction(string $action): GithubClient
    {
        return match ($action) {
            self::PUSH, self::DELETE_BRANCH, self::PR_CREATE, self::PR_FIND, self::PR_UPDATE,
            self::READY, self::LABEL, self::COMMENT => $this->coder,
            self::REVIEW, self::EPIC => $this->reviewer,
            default => throw new \InvalidArgumentException("No explicit GitHub identity is configured for '{$action}'."),
        };
    }

    /** Read-only polling selects a client explicitly and never changes global authorization. */
    public function polling(): GithubClient
    {
        return $this->coder;
    }
}
