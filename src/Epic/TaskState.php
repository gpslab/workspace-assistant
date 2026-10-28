<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

/**
 * GitHub snapshot of one epic task (an issue and its PR). Pure data for
 * {@see Orchestrator}: the fingerprint and decisions are built from it alone.
 */
final class TaskState
{
    public const string NO_PR = 'none';
    public const string DRAFT = 'draft';
    public const string OPEN = 'open';
    public const string MERGED = 'merged';
    public const string CLOSED = 'closed';

    public const string CHECKS_NONE = 'none';
    public const string CHECKS_PENDING = 'pending';
    public const string CHECKS_FAILED = 'failed';
    public const string CHECKS_GREEN = 'green';

    public const string MERGEABLE_YES = 'true';
    public const string MERGEABLE_NO = 'false';
    /** GitHub is still computing (`null`/UNKNOWN in the API) — this is not "no conflicts". */
    public const string MERGEABLE_UNKNOWN = 'unknown';

    /**
     * @param list<string> $labels
     * @param string       $prState                NO_PR | DRAFT | OPEN | MERGED | CLOSED
     * @param string       $checks                 CHECKS_*
     * @param string       $mergeable              MERGEABLE_*
     * @param int          $comments               issue + PR comments
     * @param int|null     $lastActivityAt         unix time of the last commit, comment or draft removal
     * @param int|null     $lastEpicComment        unix time of the last issue comment with the epic marker
     * @param bool         $epicReviewedHead       the current head has an epic review with the marker
     * @param bool|null    $coldReviewDone         the cold reviewer has finished; null — the repository has no claude-review.yml
     * @param int          $foreignComments        issue and PR comments without the epic marker (people, openronin)
     * @param bool         $issueOpen              the issue itself is open
     * @param string|null  $epicVerdict            verdict of the last epic review with the marker: APPROVED | CHANGES_REQUESTED | other; null — no epic review
     * @param string|null  $epicReviewHead         SHA the last epic review was left on
     * @param bool         $changedAfterReview     there are comments or foreign reviews after the last epic review (commits are separate: {@see $headOnlyMasterMerge})
     * @param bool         $headOnlyMasterMerge    the head differs from the task's `coverage_head` only by a conflict-free merge of master (false when they are equal or `coverage_head` is unknown)
     * @param bool         $behindMaster           the PR is behind master
     * @param int          $epicChangesRequested   rounds of epic REQUEST_CHANGES with the marker, counted per reviewed commit (several on one commit are one round; manual ones do not count)
     * @param list<int>    $epicChangesRequestedAt unix time each such round was submitted, one per counted commit: the earliest review on it (0 — time unknown)
     * @param bool         $executorLastWord       the last comment of a trusted openronin executor (marker + `OPENRONIN_LOGINS`) is strictly newer than every other commit, comment and review on the PR and issue
     * @param bool         $coldSummary            the cold reviewer left its summary comment on the PR after it left draft
     */
    public function __construct(
        public readonly string $task,
        public readonly array $labels = [],
        public readonly string $prState = self::NO_PR,
        public readonly ?int $prNumber = null,
        public readonly ?string $head = null,
        public readonly string $checks = self::CHECKS_NONE,
        public readonly string $mergeable = self::MERGEABLE_UNKNOWN,
        public readonly int $comments = 0,
        public readonly int $reviews = 0,
        public readonly ?int $lastActivityAt = null,
        public readonly ?int $lastEpicComment = null,
        public readonly bool $epicReviewedHead = false,
        public readonly ?bool $coldReviewDone = null,
        public readonly int $foreignComments = 0,
        public readonly bool $issueOpen = true,
        public readonly ?string $epicVerdict = null,
        public readonly ?string $epicReviewHead = null,
        public readonly bool $changedAfterReview = false,
        public readonly bool $headOnlyMasterMerge = false,
        public readonly bool $behindMaster = false,
        public readonly int $epicChangesRequested = 0,
        public readonly array $epicChangesRequestedAt = [],
        public readonly bool $executorLastWord = false,
        public readonly bool $coldSummary = false,
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self(...[...get_object_vars($this), 'labels' => [...$this->labels, $label]]);
    }

    public function withMergeable(string $mergeable): self
    {
        return new self(...[...get_object_vars($this), 'mergeable' => $mergeable]);
    }

    public function withPrState(string $prState): self
    {
        return new self(...[...get_object_vars($this), 'prState' => $prState]);
    }

    /**
     * The head is accepted by the review part of the merge gate: the last epic review is APPROVED, nothing
     * came after it, and the head is the bot's `coverage_head` or only a master merge on top of it.
     * The anchor is `coverage_head`, not `epicReviewHead`: GitHub moves APPROVED to the new head.
     * `epicReviewHead` is still a required check: APPROVED must sit either on `coverage_head` (and the head is
     * `coverage_head` or only a master merge on top of it) or on the current head (and the head is only a master
     * merge on top of `coverage_head`). An approval never published on this head does not open the merge.
     */
    public function headAccepted(string $coverageHead): bool
    {
        return $this->epicVerdict === 'APPROVED'
            && $coverageHead !== ''
            && $this->head !== null
            && !$this->changedAfterReview
            && (
                ($this->epicReviewHead === $coverageHead && ($this->head === $coverageHead || $this->headOnlyMasterMerge))
                || ($this->epicReviewHead === $this->head && $this->headOnlyMasterMerge)
            );
    }

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprintData(): array
    {
        $labels = $this->labels;
        sort($labels);

        return [
            'labels' => $labels,
            'pr' => $this->prState,
            'head' => $this->head,
            'checks' => $this->checks,
            'mergeable' => $this->mergeable,
            'comments' => $this->comments,
            'reviews' => $this->reviews,
        ];
    }

    /**
     * Attention fingerprint: what needs Claude's judgement. Other changes
     * (CI pending → pass, draft, head, mergeable, in-progress) are only progress.
     *
     * @return array<string, mixed>
     */
    public function attentionData(): array
    {
        return [
            'answer' => $this->hasLabel(Orchestrator::LABEL_AWAITING_ANSWER),
            'action' => $this->hasLabel(Orchestrator::LABEL_AWAITING_ACTION),
        ] + $this->externalData();
    }

    /**
     * The part of the attention fingerprint that an epic run does not change itself: red PR CI,
     * third-party comments, closing without a merge. If it changed during a run, it is an
     * external event.
     *
     * @return array<string, mixed>
     */
    public function externalData(): array
    {
        return [
            'ci_red' => $this->checks === self::CHECKS_FAILED,
            'foreign' => $this->foreignComments,
            'closed' => $this->prState === self::CLOSED || (!$this->issueOpen && $this->prState !== self::MERGED),
        ];
    }
}
