<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\ColdFindings;

/**
 * Claude Code Stop hook for review mode: a turn that worked on a PR without a formal
 * review status on the PR (`gh pr review --approve / --request-changes`) is not
 * released — with a 🟢/🔴 verdict or without one.
 *
 * Why code rather than a prompt rule: the rule in the review role prompt lost three times in a row
 * to the habit of a warm session (the verdict in chat, remarks via
 * `gh pr comment`, no status). The hook does not set the status itself — the model does,
 * since it has the context (PR number, review body); the hook only keeps the
 * turn from ending without it. The bot attaches it via `--settings` only in review mode
 * (see ClaudeRunner, JobRunner::launchJob).
 *
 * Decisions per turn (decide()):
 *  - pass    — the turn did no PR work (a retelling of an old verdict) /
 *              a status matching the verdict was set successfully / the reply has no
 *              verdict but a status was set during the turn;
 *  - block   — PR work without a status (with a verdict or without — a verdict in plain
 *              text instead of 🟢/🔴 does not release the hook either) or with the opposite
 *              status, first attempt: the model receives reason() and continues
 *              the turn. Cost: a reference question about a PR in review mode costs
 *              an extra round;
 *  - missing — a repeated entry (stop_hook_active) and the needed status is still absent:
 *              the hook releases the turn (no loops) but leaves a marker, and the bot
 *              appends a warning to the reply — the failure does not pass silently.
 *
 * Cold reviewer findings (manual review): if the bot managed to collect
 * the findings, it passes the hook a list of ids ($ids). The status then counts
 * only when the body of the published review (`--body "…"` or a heredoc in the
 * `gh pr review` command itself) contains every id; otherwise the same block / missing.
 * The hook does not read `--body-file`/`-F`. In an epic run $ids = null: there coverage is
 * checked by the signal (ColdFindings::check), a second check is not needed.
 */
final class ReviewStatusHook
{
    public const string PASS = 'pass';
    public const string BLOCK = 'block';
    public const string MISSING = 'missing';

    public const string APPROVE = 'approve';
    public const string REQUEST_CHANGES = 'request_changes';

    /**
     * Hardcoded reason for a block when the reason texts cannot be loaded: the hook must not
     * fail open on that path.
     */
    public const string FALLBACK_REASON = 'Review-status check: this turn worked with a PR but the PR has no matching review state. Publish it now with `gh pr review <N> -R <owner/repo> --approve` (🟢) or `--request-changes --body <findings>` (🔴), then repeat your full verdict reply as your final message, its first line starting with 🟢 or 🔴.';

    /** PR work during the turn — a sign that the verdict comes from a review, not a retelling. */
    private const string PR_ACTIVITY_RE = '/\bgh\s+(?:pr\s+(?:view|diff|checks|comment|review)\b|api\b[^\n]*\bpulls\b)/';

    /**
     * The command that sets the status: `gh pr review … --approve/--request-changes` or
     * a POST to pulls/N/reviews with an event. The `state` group is which status exactly.
     */
    private const string STATUS_CMD_RE = '/(?:^|[;&|(\n]\s*)gh\s+pr\s+review\b[\s\S]*?(?<state>--approve|--request-changes|\s-a\b|\s-r\b)/';
    private const string STATUS_API_RE = '/(?:^|[;&|(\n]\s*)gh\s+api\b[^\n]*pulls\/\d+\/reviews\b[^\n]*\bevent=(?<state>APPROVE|REQUEST_CHANGES)/';

    /**
     * @param array<string, mixed> $input           Stop hook stdin
     * @param list<string>         $transcriptLines JSONL transcript lines
     * @param list<string>|null    $ids             finding ids the review body must cover; null/[] — do not check
     */
    public static function decide(array $input, array $transcriptLines, ?array $ids = null): string
    {
        $turn = self::currentTurn($transcriptLines);
        if (!self::hasPrActivity($turn)) {
            return self::PASS;
        }

        if (self::statusSatisfied($input, $turn)) {
            return $ids === null || $ids === [] || self::missingIds($transcriptLines, $ids) === []
                ? self::PASS
                : self::stopOrRelease($input);
        }

        return self::stopOrRelease($input);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function stopOrRelease(array $input): string
    {
        return ($input['stop_hook_active'] ?? false) === true ? self::MISSING : self::BLOCK;
    }

    /**
     * The requirement text for the model when decide() === BLOCK: which status is needed and
     * what is wrong with the one that was set.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $transcriptLines
     * @param list<string>|null    $ids
     */
    public static function reason(array $input, array $transcriptLines, ?array $ids = null): string
    {
        $turn = self::currentTurn($transcriptLines);
        if (self::statusSatisfied($input, $turn) && $ids !== null && $ids !== []) {
            $missing = self::missingIds($transcriptLines, $ids);
            if ($missing !== []) {
                return PromptFiles::render('review_status_reason_ids', ['ids' => implode(', ', $missing)]);
            }
        }

        $last = $input['last_assistant_message'] ?? '';
        $verdict = is_string($last) ? self::verdict($last) : null;
        if ($verdict === null) {
            return PromptFiles::load('review_status_reason_no_verdict')."\n".PromptFiles::load('review_status_reason_steps');
        }
        $actual = self::statusSet(self::currentTurn($transcriptLines));

        return PromptFiles::render('review_status_reason', [
            'verdict' => $verdict === self::APPROVE ? '🟢' : '🔴',
            'state' => $verdict === self::APPROVE ? 'Approve' : 'Request changes',
            'problem' => match ($actual) {
                null => 'no successful `gh pr review` call happened in this turn, so the PR still has no review state.',
                default => 'the last state you set in this turn is the opposite one — replace it.',
            },
        ])."\n".PromptFiles::load('review_status_reason_steps');
    }

    /**
     * {@see reason()}, but a failure to load the texts yields {@see FALLBACK_REASON}: a block
     * decision must not turn into a pass.
     *
     * @param array<string, mixed>               $input
     * @param list<string>                       $transcriptLines
     * @param list<string>|null                  $ids
     * @param (callable(\Throwable): mixed)|null $onError
     */
    public static function reasonOrFallback(array $input, array $transcriptLines, ?array $ids = null, ?callable $onError = null): string
    {
        try {
            return self::reason($input, $transcriptLines, $ids);
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e);
            }

            return self::FALLBACK_REASON;
        }
    }

    /**
     * The status in the turn already matches the reply's verdict (or there is no verdict and a status is set).
     *
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $turn
     */
    private static function statusSatisfied(array $input, array $turn): bool
    {
        $last = $input['last_assistant_message'] ?? '';
        $verdict = is_string($last) ? self::verdict($last) : null;
        $status = self::statusSet($turn);

        return $verdict === null ? $status !== null : $status === $verdict;
    }

    /**
     * The status and ids the hook needs as a whole: for the hook script (the "ids not covered" marker).
     *
     * @param array<string, mixed> $input
     * @param list<string>         $transcriptLines
     */
    public static function statusOk(array $input, array $transcriptLines): bool
    {
        return self::statusSatisfied($input, self::currentTurn($transcriptLines));
    }

    /**
     * Ids of findings missing from the body of the published review: the union of the bodies of successful
     * `gh pr review` calls in the turn (GitHub honours the latest review, but the findings of one PR
     * the model may spread over reviews of different PRs).
     *
     * @param list<string> $transcriptLines
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function missingIds(array $transcriptLines, array $ids): array
    {
        $turn = self::currentTurn($transcriptLines);
        $failed = self::failedToolUseIds($turn);
        $body = '';
        foreach (self::bashCalls($turn) as $callId => $command) {
            if (!isset($failed[$callId])) {
                $body .= "\n".self::reviewBody($command);
            }
        }

        return array_values(array_filter($ids, static fn (string $id): bool => preg_match(
            '/(?<![A-Za-z0-9_-])'.preg_quote($id, '/').'(?![0-9])/',
            $body,
        ) !== 1));
    }

    /**
     * The review body from `gh pr review` commands (including chains via `&&`/`;`):
     * `--body`/`-b` values and heredocs. `--body-file`/`-F` are not read.
     * For a status via `gh api …/reviews` the whole command counts as the body (`-f body=…`).
     */
    private static function reviewBody(string $command): string
    {
        if (preg_match(self::STATUS_API_RE, $command) === 1) {
            return $command;
        }
        $body = '';
        if (preg_match_all('/(?:^|[;&|(\n]\s*)gh\s+pr\s+review\b/', $command, $starts, PREG_OFFSET_CAPTURE) === 0) {
            return '';
        }
        foreach ($starts[0] as [, $offset]) {
            $rest = substr($command, $offset);
            if (preg_match_all('/(?<![\w-])(?:--body|-b)(?:=|\s+)(?:"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\')/s', $rest, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $hit) {
                    $body .= "\n".(($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? ''));
                }
            }
            if (preg_match_all('/<<-?\s*([\'"]?)(\w+)\1[^\n]*\n(.*?)\n\s*\2(?=\s|\)|$)/s', $rest, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $hit) {
                    $body .= "\n".$hit[3];
                }
            }
        }

        return $body;
    }

    /**
     * The verdict — 🟢/🔴 at the start of one of the first three non-empty lines (after
     * markdown wrapping and a short prefix such as "Verdict:" / "PR 1004:").
     * The model does not keep to the verdict format strictly, so we look wider than
     * the first line; a false positive is damped by the check for PR work in the turn.
     *
     * A reply may contain both emoji (the verdict + a status as a separate item) —
     * the one that comes earlier decides.
     *
     * @return self::APPROVE|self::REQUEST_CHANGES|null
     */
    public static function verdict(string $text): ?string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            static fn (string $l): bool => $l !== '',
        ));

        foreach (array_slice($lines, 0, 3) as $line) {
            $head = mb_substr(ltrim($line, "*_#>`- \t"), 0, 30);
            $green = mb_strpos($head, '🟢');
            $red = mb_strpos($head, '🔴');
            if ($green === false && $red === false) {
                continue;
            }

            return $red === false || ($green !== false && $green < $red) ? self::APPROVE : self::REQUEST_CHANGES;
        }

        return null;
    }

    public static function isVerdict(string $text): bool
    {
        return self::verdict($text) !== null;
    }

    /**
     * Current turn entries: everything after the last real user
     * message. Not counted: tool_result (a content array), hook feedback
     * (isMeta) and task-notification of background tasks.
     *
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    public static function currentTurn(array $lines): array
    {
        $turn = [];
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $entry = json_decode($lines[$i], true);
            if (!is_array($entry)) {
                continue;
            }
            if (self::isRealUserMessage($entry)) {
                break;
            }
            $turn[] = $entry;
        }

        return array_reverse($turn);
    }

    /**
     * @param list<array<string, mixed>> $turn
     */
    private static function hasPrActivity(array $turn): bool
    {
        foreach (self::bashCalls($turn) as $command) {
            if (preg_match(self::PR_ACTIVITY_RE, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The status that stands after the turn: the last successful call
     * (GitHub honours the latest review). Only a call whose
     * tool_result is not marked as an error counts: an attempt that GitHub
     * rejected does not set a status.
     *
     * @param list<array<string, mixed>> $turn
     *
     * @return self::APPROVE|self::REQUEST_CHANGES|null
     */
    private static function statusSet(array $turn): ?string
    {
        $failed = self::failedToolUseIds($turn);
        $status = null;
        foreach (self::bashCalls($turn) as $id => $command) {
            if (isset($failed[$id])) {
                continue;
            }
            if (preg_match(self::STATUS_CMD_RE, $command, $m) === 1 || preg_match(self::STATUS_API_RE, $command, $m) === 1) {
                $status = in_array(trim($m['state']), ['--approve', '-a', 'APPROVE'], true)
                    ? self::APPROVE
                    : self::REQUEST_CHANGES;
            }
        }

        return $status;
    }

    /**
     * @param list<array<string, mixed>> $turn
     *
     * @return array<string, string> tool_use id => Bash command
     */
    private static function bashCalls(array $turn): array
    {
        $calls = [];
        foreach ($turn as $entry) {
            if (($entry['type'] ?? null) !== 'assistant') {
                continue;
            }
            foreach (self::contentBlocks($entry) as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'Bash') {
                    $command = $block['input']['command'] ?? null;
                    if (is_string($command)) {
                        $calls[(string) ($block['id'] ?? count($calls))] = $command;
                    }
                }
            }
        }

        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $turn
     *
     * @return array<string, true>
     */
    private static function failedToolUseIds(array $turn): array
    {
        $failed = [];
        foreach ($turn as $entry) {
            if (($entry['type'] ?? null) !== 'user') {
                continue;
            }
            foreach (self::contentBlocks($entry) as $block) {
                if (($block['type'] ?? null) === 'tool_result' && ($block['is_error'] ?? false) === true) {
                    $failed[(string) ($block['tool_use_id'] ?? '')] = true;
                }
            }
        }

        return $failed;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function isRealUserMessage(array $entry): bool
    {
        if (($entry['type'] ?? null) !== 'user' || ($entry['isMeta'] ?? false) === true) {
            return false;
        }
        $content = $entry['message']['content'] ?? null;

        return is_string($content) && !str_starts_with(ltrim($content), '<task-notification>');
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private static function contentBlocks(array $entry): array
    {
        $content = $entry['message']['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }

    /**
     * JSON for `claude --settings`: a Stop hook on bin/review-stop-hook.php of this
     * bot copy (path from __DIR__ — claude runs with the managed repository as cwd).
     */
    public static function settingsJson(string $stateDir): string
    {
        $command = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg(dirname(__DIR__).'/bin/review-stop-hook.php').' '
            .escapeshellarg($stateDir);

        return (string) json_encode(
            ['hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => $command]]]]]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public static function markerPath(string $stateDir, string $sessionId): string
    {
        return $stateDir.'/review-status-missing/'.preg_replace('/[^A-Za-z0-9-]/', '', $sessionId);
    }

    public static function idsMarkerPath(string $stateDir, string $sessionId): string
    {
        return $stateDir.'/review-ids-missing/'.preg_replace('/[^A-Za-z0-9-]/', '', $sessionId);
    }

    /**
     * Marker "the review body lacks finding ids": the content is ids, one per line.
     *
     * @param list<string> $missing
     */
    public static function writeIdsMarker(string $stateDir, string $sessionId, array $missing): void
    {
        $path = self::idsMarkerPath($stateDir, $sessionId);
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, implode("\n", $missing));
    }

    /**
     * Missing ids from the session marker (the marker is deleted); null — no marker.
     *
     * @return list<string>|null
     */
    public static function consumeIdsMarker(string $stateDir, string $sessionId): ?array
    {
        $path = self::idsMarkerPath($stateDir, $sessionId);
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        @unlink($path);

        return array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== ''));
    }

    /** Whether there is a "status not set" marker for the session; the marker is deleted. */
    public static function consumeMarker(string $stateDir, string $sessionId): bool
    {
        $path = self::markerPath($stateDir, $sessionId);
        if (!is_file($path)) {
            return false;
        }
        @unlink($path);

        return true;
    }
}
