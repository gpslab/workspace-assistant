<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;

/**
 * Epic run: kinds, mode and prompt. The run contract is input (prompt + plan,
 * journal, GitHub) and output (the signal file, see {@see Signal}).
 */
final class Run
{
    public const string TICK = 'tick';
    public const string REVIEW = 'review';
    public const string FINAL = 'final';

    /** Name of the runbook text in the bot's `prompts/` directory (`<name>.md`, else `<name>.md.dist`). */
    public const string RUNBOOK_FILE = 'epic_runbook';

    /** Instance addition to the runbook inside `PROMPTS_DIR`; optional, epic runs only. */
    public const string ADDITION_FILE = 'epic.md';

    /** Environment variable with the path to the signal file (by analogy with outbox). */
    public const string SIGNAL_ENV = 'WORKSPACE_ASSISTANT_EPIC_SIGNAL';

    /** Absolute path to the runbook: the local `prompts/epic_runbook.md` if present, else the shipped `.dist`. */
    public static function runbookPath(): string
    {
        return PromptFiles::resolvedPath(self::RUNBOOK_FILE);
    }

    /**
     * The instance's `epic.md` from the prompts dir. A missing, empty or unreadable file
     * is the norm (the file is optional) and yields an empty string without a warning.
     */
    public static function addition(?string $promptsDir): string
    {
        if ($promptsDir === null || $promptsDir === '') {
            return '';
        }
        $path = rtrim($promptsDir, '/').'/'.self::ADDITION_FILE;
        $text = is_file($path) ? @file_get_contents($path) : false;

        return $text === false ? '' : trim($text);
    }

    public static function isRun(string $run): bool
    {
        return in_array($run, [self::TICK, self::REVIEW, self::FINAL], true);
    }

    /** Tick and final check — pm, hot review — review. */
    public static function modeFor(string $run): string
    {
        return $run === self::REVIEW ? SystemPrompt::MODE_REVIEW : SystemPrompt::MODE_PM;
    }

    public static function marker(string $slug): string
    {
        return "<!-- epic:{$slug} -->";
    }

    public static function signalPath(string $stateDir, string $slug): string
    {
        return $stateDir.'/epics/'.$slug.'/signal.json';
    }

    public static function failuresPath(string $stateDir, string $slug): string
    {
        return $stateDir.'/epics/'.$slug.'/failures.log';
    }

    public static function planPath(string $repoPath, string $epicsDir, string $slug): string
    {
        return $repoPath.'/'.$epicsDir.'/'.$slug.'.md';
    }

    public static function journalPath(string $repoPath, string $epicsDir, string $slug): string
    {
        return $repoPath.'/'.$epicsDir.'/'.$slug.'.journal.md';
    }

    /**
     * @param list<array{id: string, source: string, path: ?string, line: ?int, body: string}>|null                           $coldFindings cold reviewer findings for a review run; null — not collected
     * @param list<array{repo: string, sha: string, kind: string, runs: list<array{id: int, url: string, workflow: string}>}> $master       master CI events for the tick: a confirmed breakage and flakiness
     */
    public static function prompt(string $slug, string $run, string $planPath, string $journalPath, string $signalPath, string $failuresPath, ?string $pr = null, ?array $coldFindings = null, array $master = [], string $outboxDir = '', string $addition = '', string $pmRunbookLine = '', bool $autoCommit = true, ?string $reviewHead = null, ?int $tailsRounds = null): string
    {
        $marker = self::marker($slug);
        $lines = [
            'This is an isolated epic run, not a chat with the author. Follow the runbook: read '.self::runbookPath().' in full before anything else.',
        ];
        if ($pmRunbookLine !== '') {
            $lines[] = $pmRunbookLine;
        }
        if ($addition !== '') {
            $lines[] = '';
            $lines[] = 'Instance additions to the runbook (they follow it and take precedence over it where they are more specific):';
            $lines[] = $addition;
        }
        if (!$autoCommit) {
            $lines[] = 'AUTO_COMMIT=0: the working tree belongs to the operator. For this run, nothing is committed or pushed, and this overrides the runbook rules "Journal — immediately" (including "A crashed run loses everything uncommitted"), the commit of decision documents and the commit of the report: write those files to disk and leave them uncommitted.';
        }
        $journalRule = $autoCommit
            ? 'commit and push it right after each external action'
            : 'write it to disk right after each external action; do not commit or push it';
        array_push(
            $lines,
            '',
            "Epic slug: {$slug}",
            "Run kind: {$run}",
            "Plan: {$planPath}",
            "Journal: {$journalPath} (the only memory between runs; keep it up to date, {$journalRule})",
            "Signal file: {$signalPath} (also in the environment variable ".self::SIGNAL_ENV.')',
        );
        $lines[] = "Failures log: {$failuresPath} (one line per failed earlier run of this epic; empty or missing means none)";
        if ($outboxDir !== '') {
            $lines[] = "Outbox directory: {$outboxDir}";
        }
        if ($pr !== null) {
            $lines[] = "Pull request: {$pr}";
        }
        $lines[] = '';
        $lines[] = match ($run) {
            self::REVIEW => "Hot review of a pull request of the epic. Put the epic marker `{$marker}` into the body of the review you publish.",
            self::FINAL => "Final check of the epic. Put the epic marker `{$marker}` into every issue or PR comment you publish. Put the final report into the outbox.",
            default => "Tick: look at GitHub state and act on the plan per the runbook. Put the epic marker `{$marker}` into every issue or PR comment you publish, including the comment that answers an openronin clarification (when the answer is duplicated into the issue body and a comment, the comment carries the marker).",
        };
        if ($run === self::REVIEW && $coldFindings !== null) {
            $lines[] = '';
            array_push($lines, ...ColdFindings::promptLines($coldFindings));
        }
        if ($run === self::REVIEW && $reviewHead !== null) {
            $lines[] = '';
            $lines[] = "Reviewed commit: {$reviewHead} — the head the findings above were collected on. Read the code at this SHA (`git diff <merge-base>...{$reviewHead}`, `git show {$reviewHead}:<path>`; tests and mutations in a temporary `git worktree add /tmp/… {$reviewHead}` removed at the end), not the current PR head, and publish the review with `-f commit_id={$reviewHead}`.";
        }
        if ($run === self::FINAL && $tailsRounds !== null) {
            $max = Database::MAX_TAILS_ROUNDS;
            $lines[] = $tailsRounds >= $max
                ? "Tails rounds: {$tailsRounds} of {$max} used — the limit is exhausted: the bot refuses any `added` task."
                : "Tails rounds: {$tailsRounds} of {$max} used — extra tasks in `added` are still accepted.";
        }
        if ($run === self::TICK) {
            array_push($lines, ...self::masterLines($master));
        }
        if ($run !== self::REVIEW) {
            $lines[] = 'Read the failures log first and carry every entry not yet in the journal over into the journal (what failed, when, the saved patch if any), so the journal and the final report list the failures.';
        }
        $lines[] = '';
        $lines[] = 'Your reply text is NOT sent to the author. Report to the bot only through the signal file (JSON object, all fields optional):';
        $lines[] = '- "added": [{"task": "repo#N", "after": ["repo#M"]}] — extra tasks and tails (a task is `owner/repo#N`, or the short `repo#N` for the default owner);';
        $lines[] = '- "hold": ["repo#N"] (also `owner/repo#N`) — the full current list of tasks that must not be handed to openronin (depend on a parked fork); [] clears it;';
        $lines[] = '- "message": "text" — a question or a fork for the author;';
        $lines[] = '- "blocked": "reason" — the whole epic cannot continue;';
        $lines[] = '- "done": true — the final check passed and the report is in the outbox.';
        $lines[] = '- "verdict": "approve" | "request_changes" — the review state you published (review run; required there);';
        $lines[] = '- "cold_findings": [{"id": "C1", "verdict": "fixed|open|refuted", "where": "file:line"}] — a verdict for EVERY cold reviewer finding id listed above (review run). "fixed": fixed in the diff, "where" points at the place, checked in the code; "open": not fixed, needs fixing (then the verdict cannot be approve); "refuted": disproved via your verification step.';
        $lines[] = 'Write `{}` if there is nothing to report. A missing or invalid signal file fails the run.';

        return implode("\n", $lines);
    }

    /**
     * @param list<array{repo: string, sha: string, kind: string, runs: list<array{id: int, url: string, workflow: string}>}> $master
     *
     * @return list<string>
     */
    private static function masterLines(array $master): array
    {
        $lines = [];
        foreach ($master as $event) {
            $runs = implode(', ', array_map(static fn (array $r): string => $r['url'].($r['workflow'] !== '' ? " ({$r['workflow']})" : ''), $event['runs']));
            $lines[] = '';
            $verdict = $event['runs'] === []
                ? 'The bot found no failed GitHub Actions runs to rerun and master is red (likely an external status), so it is a confirmed breakage.'
                : 'The bot reran the failed jobs once and master stayed red, so it is a confirmed breakage.';
            $lines[] = $event['kind'] === MasterRecord::BROKEN
                ? "Master CI is broken: repository {$event['repo']}, commit {$event['sha']}. {$verdict} Merges and releases in this repository are paused by the bot, except the fix task. Create a fix task (run it through the instance's review steps for new tasks; labels per the rules; link the failed run in it) and add it to the epic through the signal field \"added\" without \"after\"; the bot sets `openronin:do-it` itself."
                : "Flaky test: repository {$event['repo']}, commit {$event['sha']}. The failed jobs of master CI were rerun by the bot and went green, so nothing is broken and the epic goes on. File a side issue about the unstable test (no `openronin:do-it` label, do not add it to the epic) and record it in the journal.";
            $lines[] = 'Failed run: '.($runs !== '' ? $runs : 'none listed');
        }

        return $lines;
    }
}
