<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Locale;

/**
 * Epic PR merge gate: a pure decision from GitHub facts, no model and no network.
 * The order of checks is the same as in enqueueing: epic review, head, checks, mergeable, behind.
 */
final class MergeGate
{
    public const string MERGE = 'merge';
    public const string UPDATE_BRANCH = 'update-branch';
    public const string WAIT = 'wait';

    /**
     * @param string $coverageHead the task's `coverage_head`: the head on which all cold reviewer findings were
     *                             closed with a verdict; the approval anchor (GitHub moves APPROVED to a new head)
     *
     * @return array{action:string, reason:string} action — MERGE | UPDATE_BRANCH | WAIT; reason — why not a merge
     */
    public static function decide(TaskState $s, string $coverageHead, Locale $locale = new Locale()): array
    {
        if ($s->prState !== TaskState::OPEN || $s->prNumber === null) {
            return self::wait($locale->t('merge.wait.not_open'));
        }
        if ($s->hasLabel(Orchestrator::LABEL_AWAITING_ANSWER) || $s->hasLabel(Orchestrator::LABEL_IN_PROGRESS)) {
            return self::wait($locale->t('merge.wait.openronin_label'));
        }
        if ($s->epicVerdict !== 'APPROVED') {
            return self::wait($locale->t('merge.wait.not_approved'));
        }
        if ($coverageHead === '') {
            return self::wait($locale->t('merge.wait.no_coverage'));
        }
        if ($s->changedAfterReview) {
            return self::wait($locale->t('merge.wait.changed_after_review'));
        }
        if (!$s->headAccepted($coverageHead)) {
            return self::wait($locale->t('merge.wait.head_mismatch'));
        }
        if ($s->checks !== TaskState::CHECKS_GREEN) {
            return self::wait($locale->t('merge.wait.checks'));
        }
        if ($s->mergeable !== TaskState::MERGEABLE_YES) {
            return self::wait($locale->t('merge.wait.mergeable'));
        }
        if ($s->behindMaster) {
            return ['action' => self::UPDATE_BRANCH, 'reason' => $locale->t('merge.behind_master')];
        }

        return ['action' => self::MERGE, 'reason' => ''];
    }

    /**
     * @return array{action:string, reason:string}
     */
    private static function wait(string $reason): array
    {
        return ['action' => self::WAIT, 'reason' => $reason];
    }
}
