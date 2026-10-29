<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\GhClient;

/**
 * The epic orchestrator's access to GitHub. Implemented by {@see GhClient}; replaced in tests.
 * Network and `gh` errors are \RuntimeException (the epic poll is skipped this time).
 */
interface GitHub
{
    /** Snapshot of the task `owner/repo#N` (or `repo#N` of the default owner); $marker is the epic marker `<!-- epic:<slug> -->`;
     * $coverageHead is the task's `coverage_head`, the base of the "only master merge" check. */
    public function taskState(string $task, string $marker, ?string $coverageHead = null): TaskState;

    /**
     * CI result of a repository's default branch (short name): state — SUCCESS | FAILURE | PENDING | NONE,
     * oid — the SHA of the branch head, branch — the default branch name.
     *
     * @return array{state: string, oid: string, branch: string}
     */
    public function masterCi(string $repo): array;

    /**
     * Failed CI runs on the default branch SHA (one per workflow, the latest).
     *
     * @return list<array{id: int, url: string, workflow: string}>
     */
    public function failedRuns(string $repo, string $branch, string $sha): array;

    /** `gh run rerun <id> --failed`: restart the failed jobs of a run. */
    public function rerunFailed(string $repo, int $id): void;

    public function addLabel(string $task, string $label): void;

    /**
     * Everything `claude[bot]` left on the PR `repo#N` (REST): inline comments, review bodies, issue
     * comments; ids C1…CN in a stable order. Findings by other authors are not included.
     *
     * @return list<array{id: string, source: string, path: ?string, line: ?int, body: string}>
     */
    public function coldFindings(string $pr): array;

    /** The head commit (`headRefOid`) of the PR `repo#N` right now. */
    public function prHeadOid(string $pr): string;

    /**
     * Open PRs of the GITHUB_OWNERS owners with the given numbers: one GraphQL query for all numbers.
     * "No PR" (including NOT_FOUND, an issue number) is normal; a failure is \RuntimeException.
     *
     * @param list<int> $numbers
     *
     * @return array<int, list<string>> number => repositories (`repo` for the default owner, otherwise `owner/repo`) with an open PR
     */
    public function openPrRepos(array $numbers): array;

    /** `gh pr ready` on the task's PR. */
    public function markReady(string $task, int $pr): void;

    /** `gh pr merge --merge` on the task's PR (strategy — merge commit). */
    public function merge(string $task, int $pr): void;

    /** `gh pr update-branch` on the task's PR. */
    public function updateBranch(string $task, int $pr): void;

    /** A comment on the task's PR. */
    public function commentPr(string $task, int $pr, string $body): void;

    /** The text of `CHANGELOG.md` on the repository's default branch; null — there is no file. */
    public function changelog(string $repo): ?string;

    public function tagExists(string $repo, string $version): bool;

    /** `gh release create <version> --generate-notes` in the repository. */
    public function createRelease(string $repo, string $version): void;
}
