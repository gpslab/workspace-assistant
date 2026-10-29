<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use GpsLab\WorkspaceAssistant\Locale;

/**
 * Cold reviewer (`claude[bot]`) findings on an epic PR: the file with the list
 * and the head the hot review was started on, the check of coverage by the signal and the text
 * for the prompt. Pure functions plus reading/writing one file; no network here.
 *
 * @phpstan-type Finding array{id: string, source: string, path: ?string, line: ?int, body: string}
 * @phpstan-type Stored array{task: string, pr: string, head: string, findings: list<Finding>}
 */
final class ColdFindings
{
    public const string SOURCE_INLINE = 'inline';
    public const string SOURCE_REVIEW = 'review';
    public const string SOURCE_COMMENT = 'comment';

    /** Hot review attempts per head before the task becomes blocked. */
    public const int MAX_ATTEMPTS = 2;

    /** `state/epics/<slug>/cold-<repo>-<n>.json` (`cold-<owner>@<repo>-<n>.json`); $pr — `repo#N` or `owner/repo#N`. */
    public static function path(string $stateDir, string $slug, string $pr): string
    {
        return $stateDir.'/epics/'.$slug.'/cold-'.str_replace(['#', '/'], ['-', '@'], $pr).'.json';
    }

    /**
     * @param Stored $data
     */
    public static function save(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $json."\n") === false) {
            throw new \RuntimeException("cannot write {$path}");
        }
    }

    /**
     * @return Stored|null null — the file is missing or corrupt
     */
    public static function load(string $path): ?array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_string($data['task'] ?? null) || !is_string($data['pr'] ?? null)
            || !is_string($data['head'] ?? null) || !is_array($data['findings'] ?? null)) {
            return null;
        }

        // Only save() writes the file: the shape of the findings is not rechecked.
        return $data; // @phpstan-ignore return.type
    }

    /**
     * Coverage of findings by the `review` run signal: the list of ids with no
     * verdict, and the failure reasons. An empty result means full coverage.
     *
     * @param list<Finding> $findings
     *
     * @return array{missing: list<string>, open: list<string>, problems: list<string>}
     */
    public static function check(Signal $signal, array $findings, Locale $locale = new Locale()): array
    {
        $covered = [];
        $open = [];
        foreach ($signal->coldFindings as $item) {
            $covered[$item['id']] = true;
            if ($item['verdict'] === Signal::FINDING_OPEN) {
                $open[] = $item['id'];
            }
        }
        $missing = [];
        foreach ($findings as $finding) {
            if (!isset($covered[$finding['id']])) {
                $missing[] = $finding['id'];
            }
        }

        $problems = [];
        if ($signal->verdict === null) {
            $problems[] = $locale->t('coldfindings.no_verdict');
        }
        if ($missing !== []) {
            $problems[] = $locale->t('coldfindings.missing', ['ids' => implode(', ', $missing)]);
        }
        if ($signal->verdict === Signal::VERDICT_APPROVE && $open !== []) {
            $problems[] = $locale->t('coldfindings.approve_with_open', ['ids' => implode(', ', $open)]);
        }

        return ['missing' => $missing, 'open' => $open, 'problems' => $problems];
    }

    /**
     * `review` run prompt lines: findings with ids and the requirement to report on each one.
     *
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    public static function promptLines(array $findings): array
    {
        if ($findings === []) {
            return ['Cold reviewer (claude[bot]) findings: none. Report "cold_findings": [] in the signal.'];
        }
        $lines = ['Cold reviewer (claude[bot]) findings collected by the bot — every one needs a verdict in the signal:'];
        foreach ($findings as $f) {
            $where = $f['path'] !== null ? ' ('.$f['path'].($f['line'] !== null ? ':'.$f['line'] : '').')' : '';
            $body = trim(str_replace(["\r\n", "\r"], "\n", $f['body']));
            $lines[] = "[{$f['id']}] {$f['source']}{$where}:";
            foreach (explode("\n", $body) as $row) {
                $lines[] = '    '.$row;
            }
        }
        $lines[] = 'Check each finding against the code of the PR diff (not against an "addressed" reply of openronin). Report every id in "cold_findings" and the published review state in "verdict". Do not approve while any finding is open.';

        return $lines;
    }

    /** Environment variable of the manual `review`: the path to the file with the list of finding ids for ReviewStatusHook. */
    public const string IDS_ENV = 'WORKSPACE_ASSISTANT_REVIEW_IDS';

    /** How many PRs from the request the manual `review` handles: collection is synchronous (3+ `gh` calls each). */
    public const int MAX_MANUAL_PRS = 3;

    /** `state/review-cold-ids.json`: the list of finding ids of the current manual `review` run. */
    public static function idsPath(string $stateDir): string
    {
        return $stateDir.'/review-cold-ids.json';
    }

    /**
     * PRs of the FORGE_OWNERS owners mentioned in the request parts: links `…/<owner>/<repo>/pull/<n>`
     * and entries `<repo>#<n>`, `<owner>/<repo>#<n>`. A bare `#N` and PRs of other owners are not recognised.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return list<string> `repo#N` (default owner) or `owner/repo#N`, without repeats, in order of appearance
     *
     * @throws \RuntimeException FORGE_OWNERS is not set
     */
    public static function extractPrs(array $parts, ForgeOwners $owners, string $forge = Config::FORGE_GITHUB, string $forgeHost = 'github.com'): array
    {
        if ($forge === Config::FORGE_GITLAB) {
            return self::extractMrs($parts, $forgeHost);
        }
        $ownersRe = $owners->pattern();
        $found = [];
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (!is_string($text) || $text === '') {
                continue;
            }
            $hits = [];
            if (preg_match_all('~https://github\.com/('.$ownersRe.')/([A-Za-z0-9][A-Za-z0-9._-]*)/pull/(\d+)(?!\d)~i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m as $hit) {
                    $hits[$hit[0][1]] = $owners->canonical($hit[1][0].'/'.$hit[2][0].'#'.$hit[3][0]);
                }
            }
            // Before the entry there must be no `/`, word or `#`; an owner not in the list is not an entry.
            if (preg_match_all('~(?<![A-Za-z0-9._/#-])(?:([A-Za-z0-9][A-Za-z0-9._-]*)/)?([A-Za-z0-9][A-Za-z0-9._-]*)#(\d+)(?!\d)~', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m as $hit) {
                    $owner = $hit[1][0];
                    if ($owner !== '' && $owners->resolve($owner) === null) {
                        continue;
                    }
                    $hits[$hit[0][1]] = $owners->canonical(($owner !== '' ? $owner.'/' : '').$hit[2][0].'#'.$hit[3][0]);
                }
            }
            ksort($hits);
            foreach ($hits as $pr) {
                $found[$pr] = true;
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * GitLab merge requests mentioned in the request parts: `!N`, `<namespace>/<project>!N` (nested
     * namespaces allowed) and `https://<host>/<namespace>/<project>/-/merge_requests/N`. A bare `#N`
     * is an issue on GitLab and is never recognised.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return list<string> `!N` or `<namespace>/<project>!N`, without repeats, in order of appearance
     */
    public static function extractMrs(array $parts, string $forgeHost): array
    {
        $path = '[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)+';
        $found = [];
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (!is_string($text) || $text === '') {
                continue;
            }
            $hits = [];
            if (preg_match_all('~https?://'.preg_quote($forgeHost, '~').'(?::\d+)?/('.$path.')/-/merge_requests/(\d+)(?!\d)~i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m as $hit) {
                    $hits[$hit[0][1]] = $hit[1][0].'!'.$hit[2][0];
                }
            }
            if (preg_match_all('~(?<![A-Za-z0-9._/#!&-])(?:('.$path.')|)!(\d+)(?!\d)~', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m as $hit) {
                    $hits[$hit[0][1]] = $hit[1][0].'!'.$hit[2][0];
                }
            }
            ksort($hits);
            foreach ($hits as $mr) {
                $found[$mr] = true;
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Bare PR numbers (`#47`, `PR #47`, or `#47` after any prefix word) from the request parts: only those with no explicit link
     * or `<repo>#<n>` entry in the same part. Numbers beyond $slots are cut off; numbers longer than 7 digits and `#0` are not counted as PR numbers
     * (GraphQL Int is 32 bits, one garbage number fails the whole query).
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return list<int> without repeats, in order of appearance
     */
    public static function extractBareNumbers(array $parts, int $slots, ForgeOwners $owners, string $forge = Config::FORGE_GITHUB): array
    {
        if ($forge === Config::FORGE_GITLAB) {
            return [];
        }
        $found = [];
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (!is_string($text) || $text === '') {
                continue;
            }
            $covered = [];
            foreach (self::extractPrs([$part], $owners) as $pr) {
                $covered[(int) substr($pr, (int) strrpos($pr, '#') + 1)] = true;
            }
            if (preg_match_all('~(?<![A-Za-z0-9._/#&-])#(\d{1,7})(?!\d)~', $text, $m) > 0) {
                foreach ($m[1] as $n) {
                    if ((int) $n > 0 && !isset($covered[(int) $n])) {
                        $found[(int) $n] = true;
                    }
                }
            }
        }

        return array_slice(array_keys($found), 0, max(0, $slots));
    }

    /**
     * Findings of several PRs with ids suitable for the manual review: one PR — `C1…CN`,
     * several — `<repo>#<n>:C1…`.
     *
     * @param array<string, list<Finding>> $byPr `repo#N` or `owner/repo#N` => PR findings
     *
     * @return list<Finding>
     */
    public static function qualify(array $byPr): array
    {
        $out = [];
        foreach ($byPr as $pr => $findings) {
            foreach ($findings as $f) {
                if (count($byPr) > 1) {
                    $f['id'] = $pr.':'.$f['id'];
                }
                $out[] = $f;
            }
        }

        return $out;
    }

    /**
     * Manual `review` prompt lines: the commit each recognised PR is reviewed at.
     *
     * @param array<string, string> $shas `owner/repo#N` => head SHA recorded by the bot
     *
     * @return list<string>
     */
    public static function manualShaLines(array $shas): array
    {
        if ($shas === []) {
            return [];
        }
        $lines = ['Reviewed commits, recorded by the bot when the request arrived (a push may land while you review):'];
        foreach ($shas as $pr => $sha) {
            $lines[] = "- {$pr}: {$sha}";
        }
        $lines[] = 'Read each PR at its SHA (`git diff <merge-base>...<SHA>`, `git show <SHA>:<path>`; tests and mutations in a temporary `git worktree add /tmp/… <SHA>` removed at the end) and publish its review with `-f commit_id=<that SHA>`. A different `commit_id` for a listed PR is denied.';

        return $lines;
    }

    /**
     * Manual `review` prompt lines: findings and the requirement of an "id — verdict" line for each
     * in the body of the published review.
     *
     * @param list<Finding> $findings
     * @param list<string>  $unavailable `repo#N` (or `#N`) whose findings were not collected
     * @param list<string>  $notes       notes on bare numbers: not found / ambiguous
     *
     * @return list<string>
     */
    public static function manualPromptLines(array $findings, array $unavailable = [], array $notes = []): array
    {
        $lines = $notes;
        if ($unavailable !== []) {
            $lines[] = 'Cold reviewer (claude[bot]) findings of '.implode(', ', $unavailable).' were NOT collected by the bot — check those PRs for cold reviewer findings manually (`gh api`) before approving; do not assume there are none.';
        }
        if ($findings === []) {
            $lines[] = 'Cold reviewer (claude[bot]) findings collected by the bot: none.';

            return $lines;
        }
        $lines[] = 'Cold reviewer (claude[bot]) findings collected by the bot — every one needs a verdict line in the published review:';
        foreach ($findings as $f) {
            $where = $f['path'] !== null ? ' ('.$f['path'].($f['line'] !== null ? ':'.$f['line'] : '').')' : '';
            $body = trim(str_replace(["\r\n", "\r"], "\n", $f['body']));
            $lines[] = "[{$f['id']}] {$f['source']}{$where}:";
            foreach (explode("\n", $body) as $row) {
                $lines[] = '    '.$row;
            }
        }
        $lines[] = 'Check each finding against the code of the PR diff. The body of your review (an Approve included) must carry one line per finding: its id and a verdict — fixed (resolved in the diff), open (not resolved) or refuted (wrong, refuted by your own verification), e.g. "'.$findings[0]['id'].' — fixed: <where>". Pass the body in the `gh api …/reviews` command itself (`-f body="…"`, or a heredoc): a body read from a file (`-F body=@file`, `--input`) is not read, and the Stop hook blocks the turn until every id is in the body. Do not approve while any finding is open.';

        return $lines;
    }
}
