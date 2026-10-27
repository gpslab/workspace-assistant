<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Synchronous git operations on the managed repository.
 *
 * All calls go through proc_open with an argument array: no shell interpolation.
 */
final class GitManager
{
    /** Timeout for local operations (status/commit/reset...), sec. */
    private const int LOCAL_TIMEOUT = 30;

    /**
     * @param int $netTimeout timeout for network operations (pull/push), sec;
     *                        guards against hanging forever on SSH/network
     */
    public function __construct(
        private readonly string $repoPath,
        private readonly int $netTimeout = 120,
        private readonly Locale $locale = new Locale(),
    ) {
    }

    /**
     * Runs git with a hard timeout and non-blocking pipe reads.
     *
     * Without a timeout a hung network operation (git waiting for an SSH passphrase, host key
     * or a dead network) would freeze the single-threaded bot forever.
     *
     * @param list<string> $args
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function git(array $args, ?int $timeout = null): array
    {
        $timeout ??= self::LOCAL_TIMEOUT;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(['git', ...$args], $descriptors, $pipes, $this->repoPath, $this->gitEnv());
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start git: '.implode(' ', $args));
        }

        // Close stdin right away: git must not read anything interactively.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $running = proc_get_status($proc)['running'];
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$running) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(50_000); // 50 ms
        }

        if ($timedOut) {
            proc_terminate($proc, 15); // SIGTERM
            usleep(300_000);
            $st = proc_get_status($proc);
            if ($st['running'] === true) {
                proc_terminate($proc, 9); // SIGKILL
            }
        }

        // Read the remaining output after the process finished/was killed.
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($timedOut) {
            $exit = 124;
            $stderr = trim($stderr."\ngit ".implode(' ', $args)." - timeout {$timeout}s, process killed");
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Environment for git: inherit the current one (HOME/PATH/SSH_AUTH_SOCK are needed for
     * keys), but force non-interactivity so git/ssh fail fast instead of hanging
     * on passphrase/host-key prompts.
     *
     * @return array<string, string>
     */
    private function gitEnv(): array
    {
        return self::buildGitEnv();
    }

    /**
     * Environment for git (static, only reads getenv), so the parallel runner
     * {@see fetchPruneParallel()} can use it too.
     *
     * @return array<string, string>
     */
    private static function buildGitEnv(): array
    {
        /** @var array<string, string> $env */
        $env = getenv();
        $env['GIT_TERMINAL_PROMPT'] = '0';

        $ssh = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10';

        // SSH multiplexing: keep one connection to github.com and
        // reuse it for subsequent git operations (fetch for all docs/*,
        // push). Without it each fetch pays ~3s for the SSH handshake. ControlMaster
        // =auto brings up the master on the first connect, ControlPersist=60s keeps
        // it for 60s after last use. Key for parallel
        // fetch: the managed repository pull before docs/* already brings up this master (same
        // git@github.com -> same %C socket), so all parallel fetches
        // are multiplexed over one connection, with no thundering herd on
        // handshakes. ControlPath=.../%C is a short hash of the parameters (avoids problems with
        // unix socket length). Requires an existing ~/.ssh; otherwise no muxing.
        $home = $env['HOME'] ?? '';
        if ($home !== '' && is_dir($home.'/.ssh')) {
            $ssh .= ' -o ControlMaster=auto -o ControlPersist=60s -o ControlPath='.$home.'/.ssh/cm-%C';
        }

        $env['GIT_SSH_COMMAND'] = $ssh;

        return $env;
    }

    /**
     * Parallel `git fetch --prune` over several repositories. All processes
     * start at once and are multiplexed over one SSH connection (see
     * buildGitEnv); we wait for all until the overall $timeout deadline; those that did not make it are
     * killed (SIGTERM->KILL). So wall-clock is about the longest single fetch, not
     * their sum.
     *
     * @param array<string, string> $repos   name => path to the git repository
     * @param int                   $timeout overall deadline, sec
     *
     * @return array<string, array{ok: bool, error: string, sec: float}> name => result
     */
    public static function fetchPruneParallel(array $repos, int $timeout, Locale $locale = new Locale()): array
    {
        $env = self::buildGitEnv();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $results = [];
        /** @var array<string, array{proc: resource, pipes: array<int, resource>, start: float, stderr: string}> $procs */
        $procs = [];
        $start = microtime(true);

        foreach ($repos as $name => $path) {
            $pipes = [];
            $proc = @proc_open(['git', 'fetch', '--prune'], $descriptors, $pipes, $path, $env);
            if (!is_resource($proc)) {
                $results[$name] = ['ok' => false, 'error' => $locale->t('git.start_failed'), 'sec' => 0.0];

                continue;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$name] = ['proc' => $proc, 'pipes' => $pipes, 'start' => $start, 'stderr' => ''];
        }

        $deadline = $start + $timeout;
        while ($procs !== []) {
            foreach (array_keys($procs) as $name) {
                // Merge both pipes (otherwise a buffer overflow would block git).
                stream_get_contents($procs[$name]['pipes'][1]);
                $procs[$name]['stderr'] .= stream_get_contents($procs[$name]['pipes'][2]) ?: '';

                if (proc_get_status($procs[$name]['proc'])['running'] === false) {
                    $procs[$name]['stderr'] .= stream_get_contents($procs[$name]['pipes'][2]) ?: '';
                    $exit = (int) proc_get_status($procs[$name]['proc'])['exitcode'];
                    $results[$name] = [
                        'ok' => $exit === 0,
                        'error' => $exit === 0 ? '' : trim($procs[$name]['stderr']),
                        'sec' => microtime(true) - $procs[$name]['start'],
                    ];
                    self::closeProc($procs[$name]['proc'], $procs[$name]['pipes']);
                    unset($procs[$name]);
                }
            }

            if ($procs === []) {
                break;
            }
            if (microtime(true) >= $deadline) {
                foreach ($procs as $name => $p) {
                    proc_terminate($p['proc'], 15);
                }
                usleep(300_000);
                foreach ($procs as $name => $p) {
                    if (proc_get_status($p['proc'])['running'] === true) {
                        proc_terminate($p['proc'], 9);
                    }
                    $results[$name] = [
                        'ok' => false,
                        'error' => $locale->t('git.fetch_timeout', ['seconds' => $timeout]),
                        'sec' => microtime(true) - $p['start'],
                    ];
                    self::closeProc($p['proc'], $p['pipes']);
                }

                break;
            }

            usleep(50_000); // 50 ms
        }

        return $results;
    }

    /**
     * @param resource             $proc
     * @param array<int, resource> $pipes
     */
    private static function closeProc($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($proc);
    }

    public function isClean(): bool
    {
        return trim($this->git(['status', '--porcelain'])['stdout']) === '';
    }

    public function hasChanges(): bool
    {
        return !$this->isClean();
    }

    /**
     * Paths of uncommitted changes (modified, added, deleted, untracked) under
     * $subdir, relative to the repository root.
     *
     * @return list<string>
     */
    public function changedPathsIn(string $subdir): array
    {
        $out = $this->git(['status', '--porcelain', '-z', '--untracked-files=all', '--', $subdir])['stdout'];

        return array_keys($this->parseStatus($out));
    }

    /**
     * The working-tree state as "path => status and content hash", from `git status --porcelain -z
     * --untracked-files=all`. It is not a diff against HEAD: pair it with {@see changedSince()} to find
     * what a single run changed in an already dirty tree.
     *
     * @return array<string, string>
     */
    public function treeSnapshot(): array
    {
        $out = $this->git(['status', '--porcelain', '-z', '--untracked-files=all'])['stdout'];
        $snapshot = [];
        foreach ($this->parseStatus($out) as $path => $status) {
            $snapshot[$path] = $status.' '.$this->contentHash($path);
        }

        return $snapshot;
    }

    /**
     * Paths that are new, gone or have a different status or content hash than in $before. With
     * $subdir only the paths under it are considered.
     *
     * @param array<string, string> $before result of {@see treeSnapshot()}
     *
     * @return list<string>
     */
    public function changedSince(array $before, string $subdir = ''): array
    {
        $after = $this->treeSnapshot();
        $prefix = $subdir === '' ? '' : rtrim($subdir, '/').'/';
        $changed = [];
        foreach ($after + $before as $path => $_) {
            $path = (string) $path;
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, string> path => two-letter status
     */
    private function parseStatus(string $out): array
    {
        $entries = explode("\0", $out);
        $paths = [];
        for ($i = 0, $n = count($entries); $i < $n; ++$i) {
            if (strlen($entries[$i]) < 4) {
                continue;
            }
            $paths[substr($entries[$i], 3)] = substr($entries[$i], 0, 2);
            if (in_array('R', [$entries[$i][0], $entries[$i][1]], true) || in_array('C', [$entries[$i][0], $entries[$i][1]], true)) {
                ++$i; // a rename/copy entry is followed by its source path
            }
        }

        return $paths;
    }

    private function contentHash(string $path): string
    {
        $full = $this->repoPath.'/'.$path;
        if (is_link($full)) {
            return sha1('link:'.(string) readlink($full));
        }
        if (is_file($full)) {
            return (string) hash_file('sha1', $full);
        }

        return is_dir($full) ? 'dir' : 'gone';
    }

    /**
     * git pull --rebase. Returns true on success. On conflict/error
     * aborts the rebase and returns false.
     */
    public function pullRebase(string &$error): bool
    {
        $res = $this->git(['pull', '--rebase'], $this->netTimeout);
        if ($res['exit'] === 0) {
            return true;
        }

        $error = trim($res['stderr']."\n".$res['stdout']);
        // Do not leave the repository in an unfinished rebase state.
        $this->git(['rebase', '--abort']);

        return false;
    }

    /**
     * `git fetch --prune`: pulls remote state and removes stale
     * remote-tracking branches (branches deleted on GitHub after a PR merge). A network
     * operation under netTimeout. Returns true on success.
     */
    public function fetchPrune(string &$error): bool
    {
        $res = $this->git(['fetch', '--prune'], $this->netTimeout);
        if ($res['exit'] === 0) {
            return true;
        }

        $error = trim($res['stderr']."\n".$res['stdout']);

        return false;
    }

    /**
     * Name of the current branch, or `HEAD` when detached.
     */
    public function currentBranch(): string
    {
        return trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);
    }

    /**
     * Upstream of the current branch (e.g. `origin/master`) or null if no upstream
     * is configured or its remote-tracking ref is missing (branch deleted on
     * the remote). To reliably detect "deleted", call after {@see fetchPrune()};
     * otherwise the stale ref still resolves.
     */
    public function upstreamRef(): ?string
    {
        $res = $this->git(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);

        return $res['exit'] === 0 ? trim($res['stdout']) : null;
    }

    /**
     * Rebase the current branch onto its upstream (`@{u}`) WITHOUT a network fetch;
     * assumes a fresh {@see fetchPrune()} was already done. This way auto-pull
     * hits the network once, not twice (fetch + pull). On conflict/error
     * aborts the rebase and returns false.
     */
    public function rebaseOntoUpstream(string &$error): bool
    {
        $res = $this->git(['rebase', '@{u}']);
        if ($res['exit'] === 0) {
            return true;
        }

        $error = trim($res['stderr']."\n".$res['stdout']);
        // Do not leave the repository in an unfinished rebase state.
        $this->git(['rebase', '--abort']);

        return false;
    }

    /**
     * Default branch of the remote (`master`, `2.x`, ...), read from the symref HEAD of
     * origin. null if origin is unreachable or HEAD does not point to a branch.
     * A network call under netTimeout.
     */
    public function defaultBranch(): ?string
    {
        $res = $this->git(['ls-remote', '--symref', 'origin', 'HEAD'], $this->netTimeout);
        if ($res['exit'] !== 0) {
            return null;
        }

        // A line like: "ref: refs/heads/2.x\tHEAD"
        if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $res['stdout'], $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * true if the current HEAD is an ancestor of $ref (all commits of the current branch are already
     * contained in $ref). For "branch fully merged into default" call with
     * $ref = "origin/<default>". Requires $ref to exist locally (after
     * fetch). Any error/divergence -> false (fail-closed: not merged).
     */
    public function isMergedInto(string $ref): bool
    {
        return $this->git(['merge-base', '--is-ancestor', 'HEAD', $ref])['exit'] === 0;
    }

    /**
     * Switch the working tree to an existing branch. Non-destructive: the previous
     * branch (with its commits) stays; to return, check it out again. Requires
     * a clean tree (dirty clones are filtered out by the caller).
     */
    public function checkout(string $branch, string &$error): bool
    {
        $res = $this->git(['checkout', $branch]);
        if ($res['exit'] === 0) {
            return true;
        }

        $error = trim($res['stderr']."\n".$res['stdout']);

        return false;
    }

    /**
     * Short summary of uncommitted changes (for confirmation before committing).
     */
    public function changesSummary(): string
    {
        $short = trim($this->git(['status', '--short'])['stdout']);
        $stat = trim($this->git(['diff', '--stat', 'HEAD'])['stdout']);

        $parts = [];
        if ($short !== '') {
            $parts[] = $short;
        }
        if ($stat !== '') {
            $parts[] = $stat;
        }

        return $parts === [] ? $this->locale->t('git.no_changes') : implode("\n\n", $parts);
    }

    public function headHash(): string
    {
        return trim($this->git(['rev-parse', 'HEAD'])['stdout']);
    }

    /**
     * HEAD hash, or null if the branch has no commits yet (unborn branch).
     */
    public function headHashOrNull(): ?string
    {
        $result = $this->git(['rev-parse', 'HEAD']);

        return $result['exit'] === 0 ? trim($result['stdout']) : null;
    }

    /**
     * Factory: creates a GitManager for an arbitrary path, or null if the path is not a git repository.
     */
    public static function forPath(string $repoPath, int $netTimeout = 120): ?self
    {
        if (!is_dir($repoPath.'/.git')) {
            return null;
        }

        return new self($repoPath, $netTimeout);
    }

    /**
     * GitHub link to a commit, built from the origin remote.
     * If origin is not GitHub or no remote is set, returns just the hash.
     */
    public function githubCommitUrl(string $hash): string
    {
        $result = $this->git(['remote', 'get-url', 'origin']);
        if ($result['exit'] !== 0) {
            return $hash;
        }

        $base = self::githubBaseUrl(trim($result['stdout']));

        return $base !== '' ? "{$base}/commit/{$hash}" : $hash;
    }

    /**
     * GitHub link to a repository file on the current branch, built from the
     * origin remote. null if origin is not GitHub, no remote is set or the branch is undetermined.
     */
    public function githubFileUrl(string $path): ?string
    {
        $remote = $this->git(['remote', 'get-url', 'origin']);
        $branch = $this->git(['symbolic-ref', '--short', '-q', 'HEAD']);
        if ($remote['exit'] !== 0 || $branch['exit'] !== 0) {
            return null;
        }
        $base = self::githubBaseUrl(trim($remote['stdout']));
        $ref = trim($branch['stdout']);
        if ($base === '' || $ref === '') {
            return null;
        }

        $encode = static fn (string $p): string => implode('/', array_map(rawurlencode(...), explode('/', $p)));

        return "{$base}/blob/".$encode($ref).'/'.$encode(ltrim($path, '/'));
    }

    /**
     * Parses a remote URL and returns the base https://github.com/org/repo,
     * or an empty string if not GitHub.
     */
    private static function githubBaseUrl(string $remoteUrl): string
    {
        // SSH: git@github.com:org/repo.git
        if (preg_match('#^git@github\.com:(.+?)(?:\.git)?$#', $remoteUrl, $m)) {
            return 'https://github.com/'.$m[1];
        }
        // HTTPS: https://github.com/org/repo.git
        if (preg_match('#^https?://github\.com/(.+?)(?:\.git)?$#', $remoteUrl, $m)) {
            return 'https://github.com/'.$m[1];
        }

        return '';
    }

    /**
     * Commit all changes and push. Returns the hash of the new commit.
     */
    public function commitAndPush(string $message): string
    {
        $this->git(['add', '-A']);
        $commit = $this->git(['commit', '-m', $message]);
        if ($commit['exit'] !== 0) {
            throw new \RuntimeException('git commit failed: '.trim($commit['stderr']));
        }

        $push = $this->git(['push'], $this->netTimeout);
        if ($push['exit'] !== 0) {
            throw new \RuntimeException('git push failed: '.trim($push['stderr']));
        }

        return $this->headHash();
    }

    /**
     * The whole working-tree diff against HEAD as one patch, including new
     * untracked and binary files (`add -A` + `diff --cached --binary`).
     * The index stays staged; the caller reverts the tree itself.
     */
    public function dirtyPatch(): string
    {
        $add = $this->git(['add', '-A']);
        if ($add['exit'] !== 0) {
            throw new \RuntimeException('git add failed: '.trim($add['stderr']));
        }
        $diff = $this->git(['diff', '--cached', '--binary']);
        if ($diff['exit'] !== 0) {
            throw new \RuntimeException('git diff failed: '.trim($diff['stderr']));
        }

        return $diff['stdout'];
    }

    /**
     * Unstage the index (after a failed {@see dirtyPatch()}); does not touch files.
     */
    public function unstage(): void
    {
        $this->git(['reset', '-q']);
    }

    /**
     * Revert all uncommitted changes, including new files.
     */
    public function discardChanges(): void
    {
        $this->git(['reset', '--hard']);
        $this->git(['clean', '-fd']);
    }
}
