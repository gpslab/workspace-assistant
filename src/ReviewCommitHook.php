<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Claude Code PreToolUse hook (matcher `Bash`) of the review mode and of the epic review run:
 * a formal PR review is published only on the commit that was reviewed.
 *
 * Why: `gh pr review --approve | --request-changes` takes no commit argument, GitHub attaches the
 * review to the PR head at the moment of sending. A review takes minutes, a push in between puts the
 * verdict on a commit nobody reviewed. `POST pulls/N/reviews` with `commit_id` pins the commit. A rule in
 * the prompt alone is not enough (the status rule already lost to habit, see {@see ReviewStatusHook}), so
 * a wrong call is denied before it reaches GitHub; a Stop hook could only demand a second publication.
 *
 * Denied ({@see check()}):
 *  - `gh pr review --approve` / `--request-changes` (no commit argument);
 *  - a POST to `pulls/N/reviews` with `--input` (hides `event` and `commit_id` from the hook);
 *  - a POST to `pulls/N/reviews` without `commit_id`;
 *  - a `commit_id` that is not a literal 40-hex SHA (`$(…)`, a shell variable resolve at publish time,
 *    which is the bug itself);
 *  - a `commit_id` that differs from the SHA the bot recorded for the PR of the POST path;
 *  - on GitLab (whatever FORGE is): `glab mr approve` without a literal lowercase 40-hex `-s|--sha`; a
 *    `glab api` POST to `projects/<id>/merge_requests/<N>/approve` without such a `sha` field or query
 *    parameter (or with `--input`); and a `glab mr note` whose `verdict:` markers are not exactly one
 *    marker of the grammar {@see ReviewStatusHook::GITLAB_MARKER_RE}, the one the Stop hook counts.
 *
 * Recorded SHAs ($shas, `owner/repo#N` => SHA): the `ColdFindings` head in an epic run, `headRefOid` of
 * every PR the bot recognised in a manual review message. A PR without a recorded SHA is checked by form
 * only (a literal 40-hex `commit_id`). This is a known limitation: when the bot did not recognise the PR
 * (or the findings failed to load), the reviewer records `headRefOid` itself and the hook cannot tell
 * whether that value is the one the code was read at. On GitLab the bot records no SHAs yet (there is no
 * GitLab client): the check is by form only, so a literal 40-hex `sha` that is not the reviewed commit is
 * not caught.
 *
 * Fail-closed, like {@see PmGateHook} (see bin/review-commit-hook.php).
 */
final class ReviewCommitHook
{
    /** Environment variable: the path to the JSON file with the recorded SHAs of the run. */
    public const string SHAS_ENV = 'WORKSPACE_ASSISTANT_REVIEW_SHAS';

    private const string GH_PR_REVIEW_RE = '/^gh\s+pr\s+review\b/';
    private const string GLAB_APPROVE_RE = '/^glab\s+mr\s+approve\b/';
    private const string GLAB_APPROVE_SHA_RE = '/(?:^|\s)(?:--sha(?:=|\s+)|-s(?:=|\s+)?)(?<sha>\S*)/';
    private const string GLAB_NOTE_RE = '/^glab\s+mr\s+note\b/';
    private const string MARKER_LOOSE_RE = '/<!--\s*verdict:(?<state>[^\s>]*)\s+sha=(?<sha>[^\s>]*)/';
    private const string GLAB_API_RE = '/^glab\s+api\b(?<args>.*)$/s';
    // Fail-closed: anything between `merge_requests/` and `/approve` (a number, `$N`, `"$N"`, `$(…)`, backticks).
    private const string GLAB_APPROVE_ENDPOINT_RE = '~(?:'.PmGateHook::GLAB_PROJECT_PATH.'|projects/[^\n]*?)/merge_requests/(?<n>(?:[^\s/"\'`$]|\$(?:\{[^}\n]*\}|\([^)\n]*\))?+|"[^"\n]*"|\'[^\'\n]*\'|`[^`\n]*`)*+)/approve(?<query>\?[^\s"\']*)?(?=[\s"\'?]|$)~';
    private const string BODY_FLAG_RE = '/(?:^|\s)(?:--message\b|-m)/';
    private const string QUERY_SHA_RE = '/[?&]sha=(?<value>[^\s&"\']*)/';
    private const string SHA_FIELD_RE = '/(?:^|\s)(?:-f|-F|--field|--raw-field)[\s=]*["\']?sha=(?<value>\S*)/';
    private const string LOWER_SHA_RE = '/^["\']?[0-9a-f]{40}["\']?$/';
    private const string ANY_CASE_SHA_RE = '/^["\']?[0-9a-fA-F]{40}["\']?$/';
    private const string MARKER_FORM = '`<!-- verdict:approve sha=<SHA> -->` or `<!-- verdict:changes sha=<SHA> -->` (lowercase state, <SHA> the literal lowercase 40-hex commit you reviewed)';
    private const string STATE_FLAG_RE = '/(?:^|\s)(?:--approve|--request-changes|-a|-r)(?=[\s=]|$)/';
    private const string GH_API_RE = '/^gh\s+api\b(?<args>.*)$/s';
    private const string REVIEWS_ENDPOINT_RE = '~(?<![\w.-])(?:(?:https?://api\.github\.com)?/?repos/(?<owner>[^\s/"\']+)/(?<repo>[^\s/"\']+)/)?pulls/(?<n>\d+)/reviews(?=[\s"\'?]|$)~';
    private const string METHOD_FLAG_RE = '/(?:^|\s)(?:-X|--method(?:=|\s|$))/';
    private const string METHOD_RE = '/(?:^|\s)(?:-X\s*|--method(?:=|\s+))(?<q>["\']?)(?<method>[A-Za-z]+)\k<q>(?=\s|$)/';
    private const string FIELD_FLAG_RE = '/(?:^|\s)(?:-f|-F|--field|--raw-field|--input)(?:[\s=]|$)/';
    private const string INPUT_FLAG_RE = '/(?:^|\s)--input(?:[\s=]|$)/';
    private const string COMMIT_FIELD_RE = '/(?:^|\s)(?:-f|-F|--field|--raw-field)[\s=]*["\']?commit_id=(?<value>\S*)/';
    private const string SHA_VALUE_RE = '/^["\']?(?<sha>[0-9a-fA-F]{40})["\']?$/';

    /** What the model sees when the hook itself fails. */
    public const string FAILURE_REASON = 'review publication blocked: the commit check failed, see state/review-commit-hook.log';

    public const string FORM = 'gh api repos/<owner>/<repo>/pulls/<N>/reviews -f commit_id=<SHA> -f event=<APPROVE|REQUEST_CHANGES> -f body=<review body>';

    /**
     * The denial text for a command, null when the command may run.
     *
     * @param array<string, string> $shas lowercase `owner/repo#N` => recorded SHA
     */
    public static function check(string $command, array $shas = []): ?string
    {
        foreach (PmGateHook::commands($command) as $segment) {
            if (self::match(self::GH_PR_REVIEW_RE, $segment) === 1 && self::match(self::STATE_FLAG_RE, $segment) === 1) {
                return self::deny('`gh pr review --approve | --request-changes` attaches the review to the PR head at the moment of sending, not to the commit you reviewed.', null);
            }
            if (self::match(self::GLAB_APPROVE_RE, $segment) === 1) {
                $reason = self::checkApprove($segment);
                if ($reason !== null) {
                    return $reason;
                }
            }
            if (self::match(self::GLAB_NOTE_RE, $segment) === 1) {
                $reason = self::checkNote($segment);
                if ($reason !== null) {
                    return $reason;
                }
            }
            if (self::match(self::GLAB_API_RE, $segment, $call) === 1) {
                $reason = self::checkGlabApi($call['args'], $command);
                if ($reason !== null) {
                    return $reason;
                }
            }
            if (self::match(self::GH_API_RE, $segment, $call) === 1) {
                $reason = self::checkApi($call['args'], $shas);
                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        return null;
    }

    private static function checkApprove(string $segment): ?string
    {
        // Every value counts: pflag keeps the last one, so a literal followed by a variable is unpinned.
        if (self::matchAll(self::GLAB_APPROVE_SHA_RE, $segment, $hits) === 0) {
            return 'MR approval denied: `glab mr approve` without a literal lowercase 40-hex `-s|--sha` approves whatever the MR head is when it runs, not the commit you reviewed. Run `glab mr approve <N> --sha <SHA>` with the literal lowercase 40-hex commit you reviewed.';
        }
        foreach ($hits['sha'] as $value) {
            if (self::match(self::LOWER_SHA_RE, $value) !== 1) {
                return self::match(self::ANY_CASE_SHA_RE, $value) === 1
                    ? 'MR approval denied: the `--sha` must be lowercase 40-hex (GitLab compares it strictly with the lowercase MR head SHA and answers 409 otherwise).'
                    : 'MR approval denied: every `-s|--sha` must be the literal lowercase 40-hex commit you reviewed (`$(…)`, shell variables and short SHAs resolve or match differently at publish time, and the last `--sha` wins).';
            }
        }

        return null;
    }

    /**
     * A note whose `verdict:` markers the Stop hook would not count: exactly one marker of
     * {@see ReviewStatusHook::GITLAB_MARKER_RE} is a verdict, anything else is denied here.
     */
    private static function checkNote(string $segment): ?string
    {
        $any = self::matchAll(ReviewStatusHook::GITLAB_MARKER_ANY_RE, $segment);
        if ($any === 0) {
            return null;
        }
        // The Stop hook reads only `-m "…"`, `-m '…'`, `--message` and heredoc bodies; a marker elsewhere
        // (`-m"…"`, `$'…'`) would be published but never counted.
        $body = ReviewStatusHook::gitlabNoteBody($segment);
        if (self::matchAll(ReviewStatusHook::GITLAB_MARKER_ANY_RE, $body) !== $any) {
            return 'MR note denied: the `verdict:` marker is outside a body the Stop hook reads. Pass the note as `-m "…"`, `-m \'…\'`, `--message="…"` or, for several lines, `-m "$(cat <<\'EOF\' … EOF)"` (no `-m"…"`, no `$\'…\'`, no heredoc outside the `-m` value): '.self::MARKER_FORM.'.';
        }
        $parts = ReviewStatusHook::gitlabNoteParts($segment);
        foreach ($parts['heredocs'] as [, , $text]) {
            if (self::match(ReviewStatusHook::GITLAB_MARKER_ANY_RE, $text) === 1) {
                return 'MR note denied: the `verdict:` marker is in a heredoc outside the `-m` value, glab does not publish it as the note. Pass the note as `-m "…"`, `-m \'…\'`, `--message="…"` or, for several lines, `-m "$(cat <<\'EOF\' … EOF)"`: '.self::MARKER_FORM.'.';
            }
        }
        // glab keeps only the last `-m`: count the flags outside the body values the Stop hook reads.
        $rest = $segment;
        foreach ([...$parts['values'], ...array_map(static fn (array $h): array => [$h[0], $h[1]], $parts['heredocs'])] as [$from, $to]) {
            $rest = substr_replace($rest, str_repeat(' ', $to - $from), $from, $to - $from);
        }
        if (count($parts['values']) + self::matchAll(self::BODY_FLAG_RE, $rest) > 1) {
            return 'MR note denied: a note with a `verdict:` marker has more than one `-m`/`--message` flag, and glab publishes only the last `-m`, so the marker or the findings may not be on the MR. Use exactly one: `-m "…"`, `-m \'…\'`, `--message="…"` or, for several lines, `-m "$(cat <<\'EOF\' … EOF)"`: '.self::MARKER_FORM.'.';
        }
        $segment = $body;
        if ($any > 1) {
            return 'MR note denied: the note carries '.$any.' `verdict:` markers, a note with more than one counts as no verdict. Write exactly one: '.self::MARKER_FORM.'.';
        }
        if (self::match(ReviewStatusHook::GITLAB_MARKER_RE, $segment) === 1) {
            return null;
        }
        if (self::match(self::MARKER_LOOSE_RE, $segment, $marker) === 1) {
            if (self::match('/^(?:approve|changes)$/', $marker['state']) !== 1) {
                return 'MR note denied: the verdict state `'.$marker['state'].'` is not recognised, it is exactly `approve` or `changes` in lowercase: '.self::MARKER_FORM.'.';
            }
            if (self::match('/^[0-9a-fA-F]{40}$/', $marker['sha']) === 1) {
                return 'MR note denied: the `sha` of the verdict marker must be lowercase 40-hex: '.self::MARKER_FORM.'.';
            }
            if (self::match('/^[0-9a-f]{40}$/', $marker['sha']) !== 1) {
                return 'MR note denied: the `sha` of the verdict marker must be the literal lowercase 40-hex commit you reviewed, written out in the command (`$(…)` and shell variables resolve at publish time): '.self::MARKER_FORM.'.';
            }
        }

        return 'MR note denied: the `verdict:` marker is malformed, write it as '.self::MARKER_FORM.'.';
    }

    /**
     * @param array<string, string>|null $matches
     *
     * @param-out array<string, string> $matches
     */
    private static function match(string $pattern, string $subject, ?array &$matches = null): int
    {
        $result = preg_match($pattern, $subject, $found);
        if ($result === false) {
            throw new \RuntimeException('regex error: '.preg_last_error_msg());
        }
        /** @var array<string, string> $found */
        $matches = $found;

        return $result;
    }

    /**
     * @param array<array-key, mixed>|null $matches
     *
     * @param-out array<array-key, mixed> $matches
     */
    private static function matchAll(string $pattern, string $subject, ?array &$matches = null, int $flags = PREG_PATTERN_ORDER): int
    {
        $result = preg_match_all($pattern, $subject, $matches, $flags);
        if ($result === false) {
            throw new \RuntimeException('regex error: '.preg_last_error_msg());
        }

        return $result;
    }

    /** A method flag whose value is not a literal word (`$M`, `"$(…)"`, a backtick) is treated as POST: it is unknown to the check. */
    private static function isPost(string $args): bool
    {
        if (self::match(self::METHOD_RE, $args, $m) === 1) {
            return strtoupper($m['method']) === 'POST';
        }
        if (self::match(self::METHOD_FLAG_RE, $args) === 1) {
            return true;
        }

        return self::match(self::FIELD_FLAG_RE, $args) === 1;
    }

    /** `glab api` POST to `projects/<id>/merge_requests/<N>/approve`: only with a literal lowercase 40-hex `sha`. */
    private static function checkGlabApi(string $args, string $command): ?string
    {
        // PmGateHook::commands() cuts a segment at an unquoted `$(`: the rest of the command (path, method, fields) is not visible here.
        if (str_ends_with(rtrim($args), '$') && str_contains($command, 'merge_requests')) {
            return 'MR approval denied: a `$(…)` in a `glab api` command that touches `merge_requests` hides the rest of the command (path, method, fields) from the check. Approve only as `glab mr approve <N> --sha <SHA>` or `glab api -X POST projects/<id>/merge_requests/<N>/approve -f sha=<SHA>`, with a literal path, method and the literal lowercase 40-hex commit you reviewed.';
        }
        if (self::match(self::GLAB_APPROVE_ENDPOINT_RE, $args, $path) !== 1) {
            return null;
        }
        $post = self::isPost($args);
        if (!$post) {
            return null;
        }
        $form = ' Approve only as `glab mr approve <N> --sha <SHA>` or `glab api -X POST projects/<id>/merge_requests/<N>/approve -f sha=<SHA>`, with the literal lowercase 40-hex commit you reviewed.';
        if (self::match(self::INPUT_FLAG_RE, $args) === 1) {
            return 'MR approval denied: `--input` hides the `sha` from the check.'.$form;
        }
        $values = [];
        if (self::matchAll(self::SHA_FIELD_RE, $args, $hits) > 0) {
            $values = $hits['value'];
        }
        if (isset($path['query']) && self::matchAll(self::QUERY_SHA_RE, $path['query'], $hits) > 0) {
            $values = [...$values, ...$hits['value']];
        }
        if ($values === []) {
            return 'MR approval denied: a POST to `merge_requests/'.$path['n'].'/approve` without `sha` approves whatever the MR head is when it runs, not the commit you reviewed.'.$form;
        }
        foreach ($values as $value) {
            if (self::match(self::LOWER_SHA_RE, $value) !== 1) {
                return self::match(self::ANY_CASE_SHA_RE, $value) === 1
                    ? 'MR approval denied: the `sha` must be lowercase 40-hex (GitLab compares it strictly with the lowercase MR head SHA and answers 409 otherwise).'.$form
                    : 'MR approval denied: the `sha` must be a literal lowercase 40-hex SHA: `$(…)` and shell variables resolve at publish time, to the current MR head.'.$form;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $shas
     */
    private static function checkApi(string $args, array $shas): ?string
    {
        if (self::match(self::REVIEWS_ENDPOINT_RE, $args, $path) !== 1) {
            return null;
        }
        $post = self::isPost($args);
        if (!$post) {
            return null;
        }
        $recorded = self::recorded($shas, $path['owner'], $path['repo'], (int) $path['n']);

        if (self::match(self::INPUT_FLAG_RE, $args) === 1) {
            return self::deny('a POST to `pulls/'.$path['n'].'/reviews` with `--input` hides `event` and `commit_id` from the check.', $recorded);
        }
        if (self::matchAll(self::COMMIT_FIELD_RE, $args, $hits, PREG_SET_ORDER) === 0) {
            return self::deny('a POST to `pulls/'.$path['n'].'/reviews` has no `commit_id`, so GitHub attaches the review to the PR head at the moment of sending.', $recorded);
        }
        foreach ($hits as $hit) {
            if (self::match(self::SHA_VALUE_RE, $hit['value'], $sha) !== 1) {
                return self::deny('`commit_id` must be a literal 40-hex SHA: `$(…)` and shell variables resolve at publish time, to the current PR head.', $recorded);
            }
            if ($recorded !== null && strtolower($sha['sha']) !== $recorded) {
                return self::deny('`commit_id` '.strtolower($sha['sha']).' is not the commit recorded for PR #'.$path['n'].' ('.$recorded.').', $recorded);
            }
        }

        return null;
    }

    /**
     * The SHA recorded for the PR of a POST path; null — none (or the path names no repository and
     * several PRs share the number).
     *
     * @param array<string, string> $shas
     */
    private static function recorded(array $shas, string $owner, string $repo, int $n): ?string
    {
        // `gh api` expands `{owner}`/`:owner` placeholders from the current repository: match by number.
        $placeholder = static fn (string $s): bool => self::match('/^(?:\{[^}]*\}|:.*)$/', $s) === 1;
        if ($owner !== '' && $repo !== '' && !$placeholder($owner) && !$placeholder($repo)) {
            return $shas[strtolower("{$owner}/{$repo}#{$n}")] ?? null;
        }
        $found = [];
        foreach ($shas as $key => $sha) {
            if (str_ends_with($key, '#'.$n)) {
                $found[$sha] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    private static function deny(string $why, ?string $recorded): string
    {
        $text = 'Review publication denied: '.$why.' Publish the review only as `'.self::FORM.'`';
        $text .= $recorded === null
            ? ', where <SHA> is the literal 40-hex commit you reviewed (the `headRefOid` you recorded when you started), written out in the command.'
            : ', with `commit_id='.$recorded.'` — the commit the bot recorded for this PR, written out literally.';

        return $text.' Request changes always goes on that commit; Approve in a manual review only if the PR head still equals it; if GitHub rejects the POST, set no status and do not republish on another commit.';
    }

    /**
     * Recorded SHAs of a run: `owner/repo#N` (any case) => 40-hex SHA. Invalid entries are dropped.
     *
     * @param array<string, mixed> $shas
     *
     * @return array<string, string> lowercase keys and SHAs
     */
    public static function normalize(array $shas): array
    {
        $out = [];
        foreach ($shas as $pr => $sha) {
            if (is_string($sha) && self::match('/^[0-9a-fA-F]{40}$/', $sha) === 1) {
                $out[strtolower((string) $pr)] = strtolower($sha);
            }
        }

        return $out;
    }

    public static function shasPath(string $stateDir, string $runId): string
    {
        return rtrim($stateDir, '/').'/review-shas/'.$runId.'.json';
    }

    /**
     * @param array<string, string> $shas
     *
     * @throws \RuntimeException
     */
    public static function writeShas(string $stateDir, string $runId, array $shas): string
    {
        $path = self::shasPath($stateDir, $runId);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('cannot create '.dirname($path));
        }
        if (file_put_contents($path, json_encode(self::normalize($shas), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)) === false) {
            throw new \RuntimeException('cannot write '.$path);
        }

        return $path;
    }

    /**
     * @return array<string, string>
     *
     * @throws \RuntimeException the file is set but cannot be read: the hook must not fall back to a form-only check
     */
    public static function readShas(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('recorded SHAs file is missing or invalid: '.$path);
        }

        return self::normalize($data);
    }

    public static function cleanup(string $stateDir, string $runId): void
    {
        @unlink(self::shasPath($stateDir, $runId));
    }

    /** `claude --settings` JSON with the commit hook added to its PreToolUse hooks. */
    public static function addTo(string $settingsJson, string $stateDir): string
    {
        $settings = $settingsJson === '' ? [] : json_decode($settingsJson, true, 512, JSON_THROW_ON_ERROR);
        $settings['hooks']['PreToolUse'][] = self::hookEntry($stateDir);

        return (string) json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The `hooks.PreToolUse` entry for `claude --settings`; wrapped like the {@see PmGateHook} one.
     *
     * @return array{matcher: string, hooks: list<array{type: string, command: string}>}
     */
    public static function hookEntry(string $stateDir): array
    {
        $inner = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg(dirname(__DIR__).'/bin/review-commit-hook.php').' '
            .escapeshellarg($stateDir);
        $command = '{ '.$inner.' || { echo '.escapeshellarg(self::FAILURE_REASON).' >&2; false; }; } || exit 2';

        return ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => $command]]];
    }
}
