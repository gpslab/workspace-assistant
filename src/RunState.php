<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * The state of the run in progress: the process, its context and the snapshots taken when it started.
 * One instance is shared by `Bot` and the classes it delegates to.
 */
final class RunState
{
    public ?ClaudeRunner $activeJob = null;

    /**
     * The run mode (`mode`) is fixed when the item is queued and
     * read ONLY from here (launchJob/ReviewStatusHook/thinkingText/
     * overflowCaption) — not from sessions: /mode may have changed while the run was in progress.
     * The language (`locale`) is fixed the same way and read from the item, not from the /lang setting.
     *
     * @var array{kind:string, chat_id:int, thread_id:int, thinking_id:int, prompt:string, last_progress_at:int, shown_count:int, compact_old_session_id:?string, session_saved:bool, inbox_files:list<string>, mode:string, locale:string, queue_id:int, epic_slug:?string, epic_run:array{run:string, pr:?string}|null, pm_gate_run_id:?string, prompt_hash:?string}|null
     */
    public ?array $jobContext = null;

    /**
     * Snapshot of HEAD of all docs/* repositories before Claude is launched.
     * Afterwards we compare — new commits get links in the reply.
     *
     * @var array<string, string|null>|null repoPath => headHash|null
     */
    public ?array $repoSnapshot = null;

    /**
     * With AUTO_COMMIT=0: the working tree as it was when the current run started, to tell what the run
     * changed in a tree that is normally dirty. It outlives finishJobState() and is dropped when the
     * run's outcome has been handled.
     *
     * @var array<string, string>|null
     */
    public ?array $runTreeSnapshot = null;

    /** AUTO_COMMIT=0: why the snapshot of the run is unknown (taking it failed); null when it is known or not taken. */
    public ?string $runTreeSnapshotError = null;

    /** Whether the job in progress belongs to the topic. */
    public function hasActiveJob(int $chatId, int $threadId): bool
    {
        return $this->activeJob !== null && $this->jobContext !== null
            && $this->jobContext['chat_id'] === $chatId && $this->jobContext['thread_id'] === $threadId;
    }
}
