<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\PmRunbook;

/**
 * Prompt preparation of epic runs and of the manual `review` request. A leaf: it depends on
 * the database, the config and the GitHub client only.
 */
final class RunPrompt
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?GitHub $github,
    ) {
    }

    /** Prompt of the epic run `$run` of the epic `$slug` (`$pr` - `repo#N` of a `review` run). */
    public function forEpicRun(string $slug, string $run, ?string $pr): string
    {
        $cold = $run === Run::REVIEW && $pr !== null
            ? ColdFindings::load(ColdFindings::path($this->config->stateDir, $slug, $pr))
            : null;

        return Run::prompt(
            $slug,
            $run,
            Run::planPath($this->config->repoPath, $this->config->epicsDir, $slug),
            Run::journalPath($this->config->repoPath, $this->config->epicsDir, $slug),
            Run::signalPath($this->config->stateDir, $slug),
            Run::failuresPath($this->config->stateDir, $slug),
            $pr,
            $cold['findings'] ?? null,
            $run === Run::TICK ? $this->masterEvents($slug) : [],
            $this->config->outboxDir,
            Run::addition($this->config->absolutePromptsDir()),
            PmRunbook::line($this->config->absolutePromptsDir()),
            $this->config->autoCommit,
            $cold['head'] ?? null,
        );
    }

    /**
     * The commit the epic `review` run reviews, for {@see ReviewCommitHook}: the head the findings were
     * collected on (it becomes `coverage_head`). Empty when it is not known (not a review run, the file
     * failed to load): the hook then checks only the form of the command.
     *
     * @return array<string, string> `owner/repo#N` => SHA
     */
    public function epicReviewShas(string $slug, string $run, ?string $pr): array
    {
        $cold = $run === Run::REVIEW && $pr !== null
            ? ColdFindings::load(ColdFindings::path($this->config->stateDir, $slug, $pr))
            : null;
        if ($cold === null) {
            return [];
        }
        try {
            return [$this->shaKey($pr) => $cold['head']];
        } catch (\RuntimeException $e) {
            Log::line('epic review: '.$e->getMessage());

            return [];
        }
    }

    /** `owner/repo#N` (lowercase) of a `repo#N` / `owner/repo#N` reference. */
    private function shaKey(string $pr): string
    {
        [$owner, $repo, $number] = $this->config->githubOwners->parse($pr);

        return strtolower("{$owner}/{$repo}#{$number}");
    }

    /**
     * Manual `review`: findings of the cold reviewer for PRs of GITHUB_OWNERS owners from the request.
     * Returns lines for the prompt and the path to the id file for the hook (null - ids not needed).
     * A collection failure for one PR (not a PR, no access, network) does not fail the request: the PR is skipped.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return array{list<string>, ?string, array<string, string>} prompt lines, the id file, the head commit of each recognised PR (`owner/repo#N` => SHA)
     */
    public function manualReviewFindings(array $parts): array
    {
        if ($this->github === null) {
            return [[], null, []];
        }
        try {
            $prs = ColdFindings::extractPrs($parts, $this->config->githubOwners);
        } catch (\RuntimeException $e) {
            Log::line('manual review: '.$e->getMessage());

            return [['Cold reviewer (claude[bot]) findings were NOT collected by the bot: '.$e->getMessage().' — check PRs for cold reviewer findings manually (`gh api`).'], null, []];
        }
        $unavailable = array_slice($prs, ColdFindings::MAX_MANUAL_PRS);
        $prs = array_slice($prs, 0, ColdFindings::MAX_MANUAL_PRS);
        if ($unavailable !== []) {
            Log::line('manual review: PRs over the limit skipped: '.implode(', ', $unavailable));
        }
        // Bare numbers are looked up only when there are no explicit PRs: next to `a#7` a bare `#3` usually
        // means an issue/PR of the same repo, not a PR of another org repository.
        $notes = [];
        $bare = $prs === [] && $unavailable === []
            ? ColdFindings::extractBareNumbers($parts, ColdFindings::MAX_MANUAL_PRS, $this->config->githubOwners)
            : [];
        if ($bare !== []) {
            try {
                $repos = $this->github->openPrRepos($bare);
                foreach ($bare as $n) {
                    $found = $repos[$n] ?? [];
                    if (count($found) === 1) {
                        $prs[] = $found[0].'#'.$n;
                    } elseif ($found === []) {
                        $notes[] = "PR #{$n} was not found among open PRs of the owners in GITHUB_OWNERS";
                    } else {
                        $notes[] = "PR #{$n} is ambiguous: ".implode(', ', array_map(static fn (string $r): string => "{$r}#{$n}", $found)).' — specify the repository';
                    }
                }
            } catch (\Throwable $e) {
                Log::line('manual review: bare numbers not resolved: '.$e->getMessage());
                foreach ($bare as $n) {
                    $unavailable[] = "#{$n}";
                }
            }
            $prs = array_values(array_unique($prs));
        }
        $byPr = [];
        foreach ($prs as $pr) {
            try {
                $byPr[$pr] = $this->github->coldFindings($pr);
            } catch (\Throwable $e) {
                Log::line("manual review: findings of {$pr} not collected: ".$e->getMessage());
                $unavailable[] = $pr;
            }
        }
        if ($byPr === [] && $unavailable === []) {
            return [$notes, null, []];
        }
        // The commit each PR is reviewed at, fixed now: a push during the review must not move the verdict.
        $shas = [];
        foreach ($prs as $pr) {
            try {
                $shas[$this->shaKey($pr)] = $this->github->prHeadOid($pr);
            } catch (\Throwable $e) {
                Log::line("manual review: head of {$pr} not recorded: ".$e->getMessage());
            }
        }
        $findings = ColdFindings::qualify($byPr);
        $idsPath = null;
        if ($findings !== []) {
            $path = ColdFindings::idsPath($this->config->stateDir);
            $json = json_encode(array_column($findings, 'id'), JSON_THROW_ON_ERROR);
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                Log::line('manual review: cannot create directory '.dirname($path));
            } elseif (file_put_contents($path, $json) === false) {
                Log::line("manual review: cannot write {$path}");
            } else {
                $idsPath = $path;
            }
        }

        return [[...ColdFindings::manualPromptLines($findings, $unavailable, $notes), ...ColdFindings::manualShaLines($shas)], $idsPath, $shas];
    }

    /**
     * Master CI events for the tick prompt: a confirmed breakage - while it persists,
     * flakiness - once (the tick got it in the prompt).
     *
     * @return list<array{repo: string, sha: string, kind: string, runs: list<array{id: int, url: string, workflow: string}>}>
     */
    private function masterEvents(string $slug): array
    {
        $events = [];
        foreach ($this->db->epicMasters($slug) as $rec) {
            if ($rec->kind === MasterRecord::BROKEN || ($rec->kind === MasterRecord::FLAKY && !$rec->prompted)) {
                $events[] = ['repo' => $rec->repo, 'sha' => $rec->sha, 'kind' => $rec->kind, 'runs' => $rec->runs];
            }
            if ($rec->kind === MasterRecord::FLAKY && !$rec->prompted) {
                $rec->prompted = true;
                $this->db->epicMasterSet($slug, $rec);
            }
        }

        return $events;
    }
}
