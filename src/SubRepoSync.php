<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Exception\GitStatusException;

/**
 * Pulling, snapshotting and reporting of the nested repositories (clones under the
 * configured sub-repositories directory of the managed repository).
 */
final class SubRepoSync
{
    public function __construct(
        private readonly Config $config,
        private readonly Locale $locale,
    ) {
    }

    /**
     * Best-effort `git pull --rebase` in all docs/* repositories before running
     * Claude. Component development happens on the forge, local clones lag behind —
     * refresh them so Claude does not work on stale code.
     *
     * Not critical for the run: any failure only ends up in the returned note,
     * the job itself is not cancelled (the managed repository is pulled separately and
     * gates the run). Dirty clones are skipped — an uncommitted implementation may
     * be in progress there (the task implementation flow), a rebase would wipe it
     * with a conflict; the fact of skipping is reported.
     *
     * Per-clone order: fetch --prune (one network round + cleanup of remote
     * branches removed after a PR merge) → branch state check → local rebase on
     * the upstream.
     *
     * If a clone's branch is deleted on the remote (a common case: the clone stayed
     * on an openronin branch, the PR is merged and the branch deleted) — and it is
     * fully merged into the default branch (all its commits are already there →
     * openronin is finished, nothing to lose), the clone is automatically switched
     * to the default branch and "from which to which" is reported. If the branch is
     * NOT merged (an abandoned/unpushed PR, local WIP) — it is left alone and a
     * note is issued for manual review: silently moving away from unmerged work is
     * not allowed. The switch is non-destructive — the previous branch remains.
     *
     * @param array<string, float> $timings out parameter: repo name => seconds spent
     *                                      on it (latency profile)
     *
     * @return list<string> human-readable warnings (empty — all clean)
     */
    public function pullSubRepos(array &$timings = []): array
    {
        $notes = [];
        if ($this->config->subreposDir === '') {
            return $notes;
        }
        $docsDir = $this->config->repoPath.'/'.$this->config->subreposDir;
        if (!is_dir($docsDir)) {
            return $notes;
        }

        // 1. Collect clones, filter out dirty ones (a local instant check).
        /** @var array<string, GitManager> $clean */
        $clean = [];
        $paths = [];
        foreach (glob($docsDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mgr = GitManager::forPath($dir, $this->config->gitTimeout, $this->config->forge, $this->config->forgeHost);
            if ($mgr === null) {
                continue;
            }
            $name = basename($dir);
            try {
                $dirty = $mgr->hasChanges();
            } catch (GitStatusException $e) {
                $notes[] = $this->locale->t('subrepo.status_failed', ['name' => $name, 'error' => $e->getMessage()]);

                continue;
            }
            if ($dirty) {
                $notes[] = $this->locale->t('subrepo.dirty', ['name' => $name]);

                continue;
            }
            $clean[$name] = $mgr;
            $paths[$name] = $dir;
        }
        if ($paths === []) {
            return $notes;
        }

        // 2. Network fetch --prune over all clean clones IN PARALLEL (one
        //    multiplexed SSH connection) — the main time win.
        $fetch = GitManager::fetchPruneParallel($paths, $this->config->gitTimeout, $this->locale);

        // 3. Fast local logic (branch/upstream/rebase) — sequentially.
        foreach ($clean as $name => $mgr) {
            $result = $fetch[$name] ?? ['ok' => false, 'error' => $this->locale->t('subrepo.no_fetch_result'), 'sec' => 0.0];
            $timings[$name] = $result['sec'];
            $note = $this->finishSubRepo($mgr, $name, $result);
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * Local finishing of a clone after the parallel fetch has completed.
     * Returns a note string or null. Order: fetch result → branch check →
     * auto-switch if the remote branch is deleted and merged → local rebase.
     *
     * @param array{ok: bool, error: string, sec: float} $fetch fetch result for this repo
     */
    private function finishSubRepo(GitManager $mgr, string $name, array $fetch): ?string
    {
        if (!$fetch['ok']) {
            return $this->locale->t('subrepo.fetch_failed', ['name' => $name, 'error' => strtok(trim($fetch['error']), "\n") ?: $this->locale->t('common.error')]);
        }

        $branch = $mgr->currentBranch();
        if ($branch === 'HEAD') {
            return $this->locale->t('subrepo.detached', ['name' => $name]);
        }
        if ($mgr->upstreamRef() === null) {
            return $this->recoverGoneBranch($mgr, $name, $branch);
        }

        $error = '';
        if (!$mgr->rebaseOntoUpstream($error)) {
            return $this->locale->t('subrepo.rebase_failed', ['name' => $name, 'error' => strtok(trim($error), "\n") ?: $this->locale->t('common.error')]);
        }

        return null;
    }

    /**
     * Sync profile before the "thinking" placeholder message: a log string (single) and a
     * human-readable message for the verbose chat (per-repository breakdown,
     * sorted by descending time).
     *
     * @param array<string, float> $timings repo name => seconds
     *
     * @return array{log: string, message: string}
     */
    public function formatSyncProfile(float $managedSec, float $subReposSec, array $timings): array
    {
        arsort($timings);
        $totalSec = $managedSec + $subReposSec;

        $top = [];
        foreach ($timings as $name => $sec) {
            $top[] = sprintf('%s %.1fs', $name, $sec);
        }

        $log = sprintf(
            'sync-profile: total %.1fs = managed repository %.1fs + docs/* %.1fs (%d repos)%s',
            $totalSec,
            $managedSec,
            $subReposSec,
            count($timings),
            $top === [] ? '' : '; descending: '.implode(', ', $top),
        );

        $lines = [
            $this->locale->t('sync.profile_header'),
            $this->locale->t('sync.profile_total', ['sec' => sprintf('%.1f', $totalSec)]),
            $this->locale->t('sync.profile_managed', ['sec' => sprintf('%.1f', $managedSec)]),
            $this->locale->t('sync.profile_subrepos', ['sec' => sprintf('%.1f', $subReposSec), 'count' => count($timings)]),
        ];
        if ($top !== []) {
            // fetches run in parallel, so per-repo time is the latency of each
            // fetch separately; their sum exceeds the total wall time.
            $lines[] = $this->locale->t('sync.profile_fetch_header');
            foreach ($timings as $name => $sec) {
                $lines[] = $this->locale->t('sync.profile_fetch_line', ['name' => $name, 'sec' => sprintf('%.1f', $sec)]);
            }
        }

        return ['log' => $log, 'message' => implode("\n", $lines)];
    }

    /**
     * Handling a clone whose branch lost its upstream (deleted on the remote). If
     * the branch is fully merged into the default one — auto-switch to the default
     * and refresh it. Otherwise — a note for manual review. Returns the note
     * string for the summary.
     */
    private function recoverGoneBranch(GitManager $mgr, string $name, string $branch): string
    {
        $default = $mgr->defaultBranch();

        // Not merged / default branch cannot be determined / this is the default — leave alone.
        if ($default === null || $default === $branch || !$mgr->isMergedInto('origin/'.$default)) {
            return $this->locale->t('subrepo.no_upstream', ['name' => $name, 'branch' => $branch]);
        }

        $error = '';
        if (!$mgr->checkout($default, $error)) {
            return $this->locale->t('subrepo.switch_failed', ['name' => $name, 'branch' => $branch, 'default' => $default, 'error' => strtok(trim($error), "\n") ?: $this->locale->t('common.error')]);
        }

        // origin/<default> is already fresh after the fetch --prune above — a local rebase.
        $mgr->rebaseOntoUpstream($error);

        return $this->locale->t('subrepo.switched', ['name' => $name, 'branch' => $branch, 'default' => $default]);
    }

    /**
     * Snapshots the HEAD hashes of all git repositories in docs/*.
     *
     * @return array<string, string|null> repoPath => headHash|null
     */
    public function snapshotSubRepos(): array
    {
        $snapshot = [];
        if ($this->config->subreposDir === '') {
            return $snapshot;
        }
        $docsDir = $this->config->repoPath.'/'.$this->config->subreposDir;
        if (!is_dir($docsDir)) {
            return $snapshot;
        }

        foreach (glob($docsDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mgr = GitManager::forPath($dir, $this->config->gitTimeout, $this->config->forge, $this->config->forgeHost);
            if ($mgr !== null) {
                $snapshot[$dir] = $mgr->headHashOrNull();
            }
        }

        return $snapshot;
    }

    /**
     * Compares the current HEADs with the snapshot; returns forge links for the
     * repositories that got new commits.
     *
     * @param array<string, string|null> $snapshot
     *
     * @return list<string>
     */
    public function collectSubRepoLinks(array $snapshot): array
    {
        $links = [];
        foreach ($snapshot as $repoPath => $beforeHash) {
            $mgr = GitManager::forPath($repoPath, $this->config->gitTimeout, $this->config->forge, $this->config->forgeHost);
            if ($mgr === null) {
                continue;
            }
            $afterHash = $mgr->headHashOrNull();
            if ($afterHash !== null && $afterHash !== $beforeHash) {
                $links[] = $this->commitUrl($repoPath, $mgr, $afterHash);
            }
        }

        return $links;
    }

    /**
     * Sub-repositories have their own origins, so the forge is detected per repository: the
     * configured FORGE/FORGE_HOST first, then the well-known github.com and gitlab.com.
     */
    private function commitUrl(string $repoPath, GitManager $mgr, string $hash): string
    {
        $url = $mgr->forgeCommitUrl($hash);
        if ($url !== $hash) {
            return $url;
        }

        foreach ([[Config::FORGE_GITHUB, 'github.com'], [Config::FORGE_GITLAB, 'gitlab.com']] as [$forge, $host]) {
            $known = GitManager::forPath($repoPath, $this->config->gitTimeout, $forge, $host);
            $url = $known?->forgeCommitUrl($hash) ?? $hash;
            if ($url !== $hash) {
                return $url;
            }
        }

        return $hash;
    }
}
