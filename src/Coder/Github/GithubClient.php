<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** Explicitly authenticated GitHub operations used by the standalone controller. */
interface GithubClient
{
    public function selfLogin(): string;

    /** A non-mutating access probe, not a claim about every future write permission. */
    public function probeRepository(string $repository): void;

    /** @return array<string, mixed> */
    public function issue(string $repository, int $number): array;

    public function branchHead(string $repository, string $branch): ?string;

    /** @return array<string, mixed>|null */
    public function findPullRequest(string $repository, string $branch): ?array;

    /** @return array<string, mixed>|null */
    public function pullRequest(string $repository, int $number): ?array;

    /** @return list<array<string, mixed>> */
    public function comments(string $repository, int $issueNumber): array;

    public function push(string $repository, string $branch, string $worktree, string $expectedHead): void;

    public function deleteBranch(string $repository, string $branch, string $expectedHead): void;

    /** @return array<string, mixed> */
    public function createDraftPullRequest(string $repository, string $branch, string $base, string $title, string $body): array;

    /** @return array<string, mixed> */
    public function updateDraftPullRequest(string $repository, int $number, string $title, string $body): array;

    public function markReady(string $repository, int $number): void;

    public function addLabel(string $repository, int $issueNumber, string $label): void;

    public function removeLabel(string $repository, int $issueNumber, string $label): void;

    /** @return array<string, mixed> */
    public function comment(string $repository, int $issueNumber, string $body): array;
}
