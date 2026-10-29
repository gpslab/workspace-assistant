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
 *  - on GitLab: `glab mr approve` without a literal 40-hex `--sha`, and a `glab mr note` whose
 *    `verdict:` marker carries a `sha` that is not a literal 40-hex value.
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
    private const string GLAB_APPROVE_SHA_RE = '/(?:^|\s)--sha(?:=|\s+)["\']?[0-9a-fA-F]{40}["\']?(?=\s|$)/';
    private const string GLAB_NOTE_RE = '/^glab\s+mr\s+note\b/';
    private const string MARKER_SHA_RE = '/<!--\s*verdict:[a-z]+\s+sha=(?<sha>[^\s>]*)/';
    private const string STATE_FLAG_RE = '/(?:^|\s)(?:--approve|--request-changes|-a|-r)(?=[\s=]|$)/';
    private const string GH_API_RE = '/^gh\s+api\b(?<args>.*)$/s';
    private const string REVIEWS_ENDPOINT_RE = '~(?<![\w.-])(?:(?:https?://api\.github\.com)?/?repos/(?<owner>[^\s/"\']+)/(?<repo>[^\s/"\']+)/)?pulls/(?<n>\d+)/reviews(?=[\s"\'?]|$)~';
    private const string METHOD_RE = '/(?:^|\s)(?:-X\s*|--method(?:=|\s+))["\']?(?<method>[A-Za-z]+)/';
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
            if (preg_match(self::GH_PR_REVIEW_RE, $segment) === 1 && preg_match(self::STATE_FLAG_RE, $segment) === 1) {
                return self::deny('`gh pr review --approve | --request-changes` attaches the review to the PR head at the moment of sending, not to the commit you reviewed.', null);
            }
            if (preg_match(self::GLAB_APPROVE_RE, $segment) === 1 && preg_match(self::GLAB_APPROVE_SHA_RE, $segment) !== 1) {
                return 'MR approval denied: `glab mr approve` without a literal 40-hex `--sha` approves whatever the MR head is when it runs, not the commit you reviewed. Run `glab mr approve <N> --sha <SHA>` with the literal 40-hex commit you reviewed.';
            }
            if (preg_match(self::GLAB_NOTE_RE, $segment) === 1 && preg_match_all(self::MARKER_SHA_RE, $segment, $markers, PREG_SET_ORDER) > 0) {
                foreach ($markers as $marker) {
                    if (preg_match('/^[0-9a-fA-F]{40}$/', $marker['sha']) !== 1) {
                        return 'MR note denied: the `sha` of the verdict marker must be the literal 40-hex commit you reviewed, written out in the command (`$(…)` and shell variables resolve at publish time).';
                    }
                }
            }
            if (preg_match(self::GH_API_RE, $segment, $call) === 1) {
                $reason = self::checkApi($call['args'], $shas);
                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $shas
     */
    private static function checkApi(string $args, array $shas): ?string
    {
        if (preg_match(self::REVIEWS_ENDPOINT_RE, $args, $path) !== 1) {
            return null;
        }
        $post = preg_match(self::METHOD_RE, $args, $m) === 1
            ? strtoupper($m['method']) === 'POST'
            : preg_match(self::FIELD_FLAG_RE, $args) === 1;
        if (!$post) {
            return null;
        }
        $recorded = self::recorded($shas, $path['owner'], $path['repo'], (int) $path['n']);

        if (preg_match(self::INPUT_FLAG_RE, $args) === 1) {
            return self::deny('a POST to `pulls/'.$path['n'].'/reviews` with `--input` hides `event` and `commit_id` from the check.', $recorded);
        }
        if (preg_match_all(self::COMMIT_FIELD_RE, $args, $hits, PREG_SET_ORDER) === 0) {
            return self::deny('a POST to `pulls/'.$path['n'].'/reviews` has no `commit_id`, so GitHub attaches the review to the PR head at the moment of sending.', $recorded);
        }
        foreach ($hits as $hit) {
            if (preg_match(self::SHA_VALUE_RE, $hit['value'], $sha) !== 1) {
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
        $placeholder = static fn (string $s): bool => preg_match('/^(?:\{[^}]*\}|:.*)$/', $s) === 1;
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
            if (is_string($sha) && preg_match('/^[0-9a-fA-F]{40}$/', $sha) === 1) {
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
