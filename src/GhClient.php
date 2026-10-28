<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\GitHub;
use GpsLab\WorkspaceAssistant\Epic\TaskState;

/**
 * {@see GitHub} over `gh` (GraphQL + REST). Arguments are an array, no shell.
 */
final class GhClient implements GitHub
{
    private const string COLD_REVIEW_WORKFLOW = 'claude-review.yml';
    private const string COLD_REVIEWER = 'claude[bot]';
    /** The cold reviewer's login in GraphQL (`__typename: Bot`); REST reports {@see COLD_REVIEWER}. */
    private const string COLD_REVIEWER_GRAPHQL = 'claude';
    private const string EXECUTOR_MARKER = '<!-- openronin:bot -->';
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 20;

    private const string ISSUE_QUERY = <<<'GQL'
        query($owner:String!,$name:String!,$number:Int!){
          repository(owner:$owner,name:$name){
            issue(number:$number){
              state
              labels(first:100){nodes{name}}
              comments(last:100){totalCount nodes{body createdAt author{__typename login}}}
              closedByPullRequestsReferences(first:10,includeClosedPrs:true){nodes{
                number state isDraft merged headRefOid mergeable baseRefName
                timelineItems(last:5,itemTypes:[READY_FOR_REVIEW_EVENT]){nodes{... on ReadyForReviewEvent{createdAt}}}
                comments(last:100){totalCount nodes{body createdAt author{__typename login}}}
                reviews(last:100){totalCount nodes{body state submittedAt author{login} commit{oid}}}
                commits(last:1){nodes{commit{committedDate statusCheckRollup{contexts(first:100){nodes{
                  __typename
                  ... on CheckRun{status conclusion}
                  ... on StatusContext{state}
                }}}}}}
              }}
            }
          }
        }
        GQL;

    private const string MASTER_QUERY = <<<'GQL'
        query($owner:String!,$name:String!){
          repository(owner:$owner,name:$name){
            defaultBranchRef{name target{oid ... on Commit{statusCheckRollup{state}}}}
          }
        }
        GQL;

    public function __construct(
        private readonly GithubOwners $owners = new GithubOwners(),
        private readonly int $timeout = 15,
        private readonly ?\Closure $runner = null,
        private readonly ?\Closure $clock = null,
        /** @var list<string> logins trusted to carry the openronin marker; empty — any author */
        private readonly array $openroninLogins = [],
    ) {
    }

    /** Login of the `gh` account the bot works as; resolved once per process. */
    private ?string $selfLogin = null;

    private const int REPOS_TTL = 3600;

    /** @var list<string>|null */
    private ?array $repos = null;
    private int $reposAt = 0;

    public function openPrRepos(array $numbers): array
    {
        $out = array_fill_keys($numbers, []);
        $repos = $this->repoNames();
        if ($numbers === [] || $repos === []) {
            return $out;
        }
        $aliases = [];
        $fields = '';
        foreach ($repos as $r => $slug) {
            [$owner, $name] = explode('/', $slug, 2);
            $repo = $this->owners->canonical($slug);
            foreach ($numbers as $n) {
                $alias = "r{$r}n{$n}";
                $aliases[$alias] = [$repo, $n];
                $fields .= $alias.':repository(owner:'.json_encode($owner).',name:'.json_encode($name).'){pullRequest(number:'.$n.'){state}} ';
            }
        }
        $res = $this->run(['gh', 'api', 'graphql', '-f', 'query={'.$fields.'}']);
        $json = json_decode($res['stdout'], true);
        // A non-zero `gh` exit code with a parseable `data` is a normal response with NOT_FOUND for some aliases.
        if (!is_array($json) || !is_array($json['data'] ?? null)) {
            throw new \RuntimeException('gh graphql: '.trim($res['stderr'] !== '' ? $res['stderr'] : $res['stdout']));
        }
        $errors = $json['errors'] ?? [];
        foreach (is_array($errors) ? $errors : [] as $err) {
            $path = is_array($err) && is_array($err['path'] ?? null) ? $err['path'] : [];
            if (!is_array($err) || ($err['type'] ?? null) !== 'NOT_FOUND' || count($path) !== 2 || $path[1] !== 'pullRequest' || !isset($aliases[$path[0]])) {
                throw new \RuntimeException('gh graphql: '.json_encode($err, JSON_UNESCAPED_UNICODE));
            }
        }
        foreach ($aliases as $alias => [$repo, $n]) {
            $state = $json['data'][$alias]['pullRequest']['state'] ?? null;
            if ($state === 'OPEN') {
                $out[$n][] = $repo;
            }
        }

        return $out;
    }

    /**
     * Repositories (`owner/repo`) of all owners, without archived ones; cached in process memory, refreshed no more than once an hour.
     *
     * @return list<string>
     */
    private function repoNames(): array
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        if ($this->repos !== null && $now - $this->reposAt < self::REPOS_TTL) {
            return $this->repos;
        }
        if ($this->owners->isEmpty()) {
            throw new \RuntimeException(GithubOwners::NOT_SET);
        }
        $names = [];
        foreach ($this->owners->all() as $owner) {
            $list = json_decode($this->gh(['repo', 'list', $owner, '--limit', '1000', '--no-archived', '--json', 'name']), true);
            if (!is_array($list)) {
                throw new \RuntimeException('gh repo list: unparseable response');
            }
            foreach ($list as $row) {
                $name = is_array($row) ? ($row['name'] ?? null) : null;
                if (is_string($name) && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~', $name) === 1) {
                    $names[] = $owner.'/'.$name;
                }
            }
        }
        $this->repos = $names;
        $this->reposAt = $now;

        return $names;
    }

    public function taskState(string $task, string $marker, ?string $coverageHead = null): TaskState
    {
        [$owner, $repo, $number] = $this->split($task);
        $data = $this->graphql(self::ISSUE_QUERY, ['owner' => $owner, 'name' => $repo, 'number' => $number]);
        $issue = $data['data']['repository']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new \RuntimeException("issue {$owner}/{$repo}#{$number} not found");
        }

        $issueOpen = ($issue['state'] ?? 'OPEN') === 'OPEN';
        $labels = [];
        foreach ($issue['labels']['nodes'] ?? [] as $label) {
            $labels[] = (string) $label['name'];
        }
        $comments = (int) ($issue['comments']['totalCount'] ?? 0);
        $lastActivity = null;
        $lastEpic = null;
        $foreign = 0;
        foreach ($issue['comments']['nodes'] ?? [] as $comment) {
            $at = self::ts($comment['createdAt'] ?? null);
            $lastActivity = self::max($lastActivity, $at);
            if ($this->isEpicMarked($comment, $marker)) {
                $lastEpic = self::max($lastEpic, $at);
            } else {
                ++$foreign;
            }
        }

        $pr = self::pickPr($issue['closedByPullRequestsReferences']['nodes'] ?? []);
        if ($pr === null) {
            return new TaskState($task, $labels, comments: $comments, lastActivityAt: $lastActivity, lastEpicComment: $lastEpic, foreignComments: $foreign, issueOpen: $issueOpen);
        }

        $head = (string) $pr['headRefOid'];
        $comments += (int) ($pr['comments']['totalCount'] ?? 0);
        foreach ($pr['comments']['nodes'] ?? [] as $comment) {
            $lastActivity = self::max($lastActivity, self::ts($comment['createdAt'] ?? null));
            if (!$this->isEpicMarked($comment, $marker)) {
                ++$foreign;
            }
        }
        $commit = $pr['commits']['nodes'][0]['commit'] ?? [];
        $lastActivity = self::max($lastActivity, self::ts($commit['committedDate'] ?? null));

        foreach ($pr['timelineItems']['nodes'] ?? [] as $event) {
            $lastActivity = self::max($lastActivity, self::ts($event['createdAt'] ?? null));
        }

        [$executorLastWord, $coldSummary] = $this->lastWord($issue, $pr);

        $reviewed = false;
        $last = null;
        $changesRequestedByCommit = [];
        foreach ($pr['reviews']['nodes'] ?? [] as $review) {
            if (!$this->isEpicMarked($review, $marker)) {
                continue;
            }
            $last = $review;
            if (($review['commit']['oid'] ?? null) === $head) {
                $reviewed = true;
            }
            if (($review['state'] ?? '') === 'CHANGES_REQUESTED') {
                $at = self::ts($review['submittedAt'] ?? null) ?? 0;
                $oid = (string) ($review['commit']['oid'] ?? '');
                $changesRequestedByCommit[$oid] = isset($changesRequestedByCommit[$oid]) ? min($changesRequestedByCommit[$oid], $at) : $at;
            }
        }
        $changesRequestedAt = array_values($changesRequestedByCommit);
        $changesRequested = count($changesRequestedAt);
        $verdict = $last === null ? null : (string) ($last['state'] ?? '');
        $reviewHead = $last === null ? null : (string) ($last['commit']['oid'] ?? '');
        $reviewAt = $last === null ? null : self::ts($last['submittedAt'] ?? null);
        $changedAfter = false;
        if ($reviewAt !== null) {
            // Epic comments (marker from the gh account), bot comments (cold reviewer, CI) and openronin
            // service replies (body starts with its marker, author in OPENRONIN_LOGINS) do not require a re-review.
            foreach ([...($issue['comments']['nodes'] ?? []), ...($pr['comments']['nodes'] ?? [])] as $comment) {
                $body = (string) ($comment['body'] ?? '');
                if (!$this->isEpicMarked($comment, $marker) && !($this->isTrustedExecutor($comment) && str_starts_with(ltrim($body), self::EXECUTOR_MARKER)) && ($comment['author']['__typename'] ?? '') !== 'Bot') {
                    $changedAfter = $changedAfter || (self::ts($comment['createdAt'] ?? null) ?? 0) > $reviewAt;
                }
            }
            foreach ($pr['reviews']['nodes'] ?? [] as $review) {
                $changedAfter = $changedAfter || $review !== $last && !$this->isEpicMarked($review, $marker)
                    && (self::ts($review['submittedAt'] ?? null) ?? 0) > $reviewAt;
            }
        }
        $masterOnly = false;
        $behind = false;
        $prState = self::prState($pr);
        if ($verdict === 'APPROVED' && $prState === TaskState::OPEN && $reviewHead !== null && $reviewHead !== '') {
            $base = (string) ($pr['baseRefName'] ?? 'master');
            // A compare failure (force-push after approval: 404/422) means "not proven", not a failed epic poll.
            // GitHub moves APPROVED to the new head after a master merge, so the base is the bot's own `coverage_head`.
            try {
                $masterOnly = $coverageHead !== null && $coverageHead !== '' && $coverageHead !== $head
                    && $this->onlyMasterMerges($repo, $coverageHead, $head, $base);
            } catch (\RuntimeException) {
                $masterOnly = false;
            }
            try {
                $behind = $this->behind($repo, $base, $head);
            } catch (\RuntimeException) {
                $behind = false;
            }
        }

        return new TaskState(
            $task,
            $labels,
            $prState,
            (int) $pr['number'],
            $head,
            self::checks($commit['statusCheckRollup']['contexts']['nodes'] ?? []),
            self::mergeable($pr['mergeable'] ?? null),
            $comments,
            (int) ($pr['reviews']['totalCount'] ?? 0),
            $lastActivity,
            $lastEpic,
            $reviewed,
            $this->coldReviewDone($repo, $head),
            $foreign,
            $issueOpen,
            $verdict,
            $reviewHead,
            $changedAfter,
            $masterOnly,
            $behind,
            $changesRequested,
            $changesRequestedAt,
            $executorLastWord,
            $coldSummary,
        );
    }

    /**
     * Whether a trusted executor comment is strictly newer than all other activity, and whether the
     * cold reviewer's summary is on the PR (created after the last draft removal).
     *
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $pr
     *
     * @return array{bool, bool}
     */
    private function lastWord(array $issue, array $pr): array
    {
        $executor = null;
        $other = null;
        $summaries = [];
        $comments = [
            ...array_map(static fn (array $c): array => $c + ['_pr' => false], $issue['comments']['nodes'] ?? []),
            ...array_map(static fn (array $c): array => $c + ['_pr' => true], $pr['comments']['nodes'] ?? []),
        ];
        foreach ($comments as $comment) {
            $at = self::ts($comment['createdAt'] ?? null);
            if ($this->isTrustedExecutor($comment) && str_starts_with(ltrim((string) ($comment['body'] ?? '')), self::EXECUTOR_MARKER)) {
                $executor = self::max($executor, $at);
                continue;
            }
            $other = self::max($other, $at);
            if ($comment['_pr'] && self::isColdReviewer($comment)) {
                $summaries[] = $at ?? 0;
            }
        }
        foreach ($pr['reviews']['nodes'] ?? [] as $review) {
            $other = self::max($other, self::ts($review['submittedAt'] ?? null));
        }
        $other = self::max($other, self::ts($pr['commits']['nodes'][0]['commit']['committedDate'] ?? null));
        $ready = null;
        foreach ($pr['timelineItems']['nodes'] ?? [] as $event) {
            $ready = self::max($ready, self::ts($event['createdAt'] ?? null));
        }
        $other = self::max($other, $ready);

        return [
            $executor !== null && ($other === null || $executor > $other),
            array_any($summaries, static fn (int $at): bool => $ready === null || $at >= $ready),
        ];
    }

    /**
     * The author is `claude` with `__typename: Bot` in GraphQL and `claude[bot]` in REST.
     *
     * @param array<string, mixed> $node
     */
    private static function isColdReviewer(array $node): bool
    {
        return self::sameLogin($node, self::COLD_REVIEWER)
            || (($node['author']['__typename'] ?? '') === 'Bot' && self::sameLogin($node, self::COLD_REVIEWER_GRAPHQL));
    }

    /**
     * The epic marker counts only when the comment or review is written by the `gh` account the bot runs as.
     *
     * @param array<string, mixed> $node
     */
    private function isEpicMarked(array $node, string $marker): bool
    {
        $body = (string) ($node['body'] ?? '');

        return str_contains($body, $marker) && self::sameLogin($node, $this->selfLogin());
    }

    /**
     * The openronin marker counts only from `OPENRONIN_LOGINS`; without the list any author is accepted.
     *
     * @param array<string, mixed> $node
     */
    private function isTrustedExecutor(array $node): bool
    {
        if ($this->openroninLogins === []) {
            return true;
        }
        foreach ($this->openroninLogins as $login) {
            if (self::sameLogin($node, $login)) {
                return true;
            }
        }

        return false;
    }

    /**
     * GitHub logins are case-insensitive; a missing author (deleted account) never matches.
     *
     * @param array<string, mixed> $node
     */
    private static function sameLogin(array $node, string $expected): bool
    {
        $login = $node['author']['login'] ?? null;

        return is_string($login) && $login !== '' && strcasecmp($login, $expected) === 0;
    }

    private function selfLogin(): string
    {
        if ($this->selfLogin === null) {
            $user = json_decode($this->gh(['api', 'user']), true);
            $login = is_array($user) ? ($user['login'] ?? null) : null;
            if (!is_string($login) || $login === '') {
                throw new \RuntimeException('gh api user: no login in the response');
            }
            $this->selfLogin = $login;
        }

        return $this->selfLogin;
    }

    /**
     * The head differs from the approval SHA only by merge commits that bring no new
     * content. The first-parent chain is walked from head back to the approval SHA; the
     * compare commit list is only a `sha → parents` lookup, so default-branch commits that came
     * in with a merge are not on the chain and are not checked. Every commit on the chain
     * has exactly two parents, the second one lies on the default branch, and every file
     * changed by the merge commit matches byte for byte (blob) the version of one of the
     * parents. A chain that does not reach the approval SHA exactly, an automerge of a file
     * edited on both sides, and manual edits do not pass: "not proven" → a repeated review.
     */
    private function onlyMasterMerges(string $repo, string $from, string $head, string $base): bool
    {
        $data = $this->api("repos/{$this->slug($repo)}/compare/{$from}...{$head}");
        $commits = $data['commits'] ?? [];
        if (!is_array($commits) || $commits === [] || ($data['status'] ?? '') !== 'ahead' || count($commits) >= 250) {
            return false;
        }
        $parentsOf = [];
        foreach ($commits as $commit) {
            $parentsOf[(string) ($commit['sha'] ?? '')] = array_map(static fn (array $p): string => (string) ($p['sha'] ?? ''), $commit['parents'] ?? []);
        }
        $sha = $head;
        for ($steps = count($commits); $steps > 0 && $sha !== $from; --$steps) {
            $parents = $parentsOf[$sha] ?? null;
            if ($sha === '' || $parents === null || count($parents) !== 2 || !$this->isAncestor($repo, $parents[1], $base)
                || !$this->mergeAddsNothing($repo, $parents[0], $parents[1], $sha)) {
                return false;
            }
            $sha = $parents[0];
        }

        return $sha === $from;
    }

    private function isAncestor(string $repo, string $sha, string $branch): bool
    {
        $data = $this->api("repos/{$this->slug($repo)}/compare/{$sha}...{$branch}");

        return in_array($data['status'] ?? '', ['ahead', 'identical'], true);
    }

    /**
     * For every file where merge commit $m differs from its parent, it matches the version of
     * the other parent (the same blob; a deletion — only together with a deletion).
     */
    private function mergeAddsNothing(string $repo, string $p1, string $p2, string $m): bool
    {
        $pairs = [[$p1, $m, $p1, $p2], [$p2, $m, $p2, $p1]];
        foreach ($pairs as [$parent, $merge, $a, $b]) {
            $changed = $this->changedFiles($repo, $parent, $merge);
            $other = $this->changedFiles($repo, $a, $b);
            if ($changed === null || $other === null) {
                return false;
            }
            foreach ($changed as $path => $file) {
                if (!isset($other[$path]) || $other[$path] !== $file) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Files of diff `$from...$to` as path → "status-blob"; null — the list is truncated (300 files).
     *
     * @return array<string, string>|null
     */
    private function changedFiles(string $repo, string $from, string $to): ?array
    {
        $data = $this->api("repos/{$this->slug($repo)}/compare/{$from}...{$to}");
        $files = $data['files'] ?? [];
        if (!is_array($files) || count($files) >= 300) {
            return null;
        }
        $out = [];
        foreach ($files as $file) {
            $removed = ($file['status'] ?? '') === 'removed';
            $out[(string) ($file['filename'] ?? '')] = $removed ? 'removed' : 'blob:'.($file['sha'] ?? '');
        }

        return $out;
    }

    private function behind(string $repo, string $base, string $head): bool
    {
        $data = $this->api("repos/{$this->slug($repo)}/compare/{$base}...{$head}");

        return (int) ($data['behind_by'] ?? 0) > 0;
    }

    public function merge(string $task, int $pr): void
    {
        $repo = $task;
        $this->gh(['pr', 'merge', (string) $pr, '--merge', '--repo', $this->slug($repo)]);
        $this->deleteMergedBranch($repo, $pr);
    }

    /**
     * Deletes the head branch of a merged PR when it lives in the base repository and is not the default branch.
     * Never throws: the PR is already merged, so a failure here must not fail the merge gate.
     */
    private function deleteMergedBranch(string $repo, int $pr): void
    {
        $slug = $this->slug($repo);

        try {
            $data = $this->api("repos/{$slug}/pulls/{$pr}");
            // A successful `gh pr merge` may only enqueue the PR (merge queue): deleting the branch would close it.
            if (($data['merged'] ?? false) !== true) {
                Log::line("{$slug}#{$pr}: branch not deleted — PR is not merged yet");

                return;
            }
            $ref = $data['head']['ref'] ?? null;
            if (($data['head']['repo'] ?? null) === null) {
                return; // the fork was deleted: nothing of ours to clean up
            }
            $headRepo = $data['head']['repo']['full_name'] ?? null;
            $baseRepo = $data['base']['repo']['full_name'] ?? null;
            $default = $data['base']['repo']['default_branch'] ?? null;
            if (!is_string($ref) || $ref === '' || !is_string($headRepo) || !is_string($baseRepo) || !is_string($default)) {
                Log::line("{$slug}#{$pr}: branch not deleted — unexpected PR response");

                return;
            }
            // A fork is not ours to clean up.
            if (strcasecmp($headRepo, $baseRepo) !== 0 || $ref === $default) {
                return;
            }
            $path = implode('/', array_map('rawurlencode', explode('/', $ref)));
            $res = $this->run(['gh', 'api', '-X', 'DELETE', "repos/{$slug}/git/refs/heads/{$path}"]);
            if ($res['exit'] === 0) {
                return;
            }
            $err = trim($res['stderr'].' '.$res['stdout']);
            if (str_contains($err, 'Reference does not exist')) {
                return;
            }
            Log::line("{$slug}#{$pr}: branch {$ref} not deleted — {$err}");
        } catch (\Throwable $e) {
            Log::line("{$slug}#{$pr}: branch not deleted — ".$e->getMessage());
        }
    }

    public function updateBranch(string $task, int $pr): void
    {
        $repo = $task;
        $this->gh(['pr', 'update-branch', (string) $pr, '--repo', $this->slug($repo)]);
    }

    public function commentPr(string $task, int $pr, string $body): void
    {
        $repo = $task;
        $this->gh(['pr', 'comment', (string) $pr, '--repo', $this->slug($repo), '--body', $body]);
    }

    public function changelog(string $repo): ?string
    {
        $res = $this->run(['gh', 'api', '-H', 'Accept: application/vnd.github.raw', "repos/{$this->slug($repo)}/contents/CHANGELOG.md"]);
        if ($res['exit'] !== 0) {
            if (str_contains($res['stderr'].$res['stdout'], 'HTTP 404')) {
                return null;
            }

            throw new \RuntimeException('gh api CHANGELOG.md: '.trim($res['stderr']));
        }

        return $res['stdout'];
    }

    public function tagExists(string $repo, string $version): bool
    {
        $res = $this->run(['gh', 'api', "repos/{$this->slug($repo)}/git/ref/tags/".rawurlencode($version)]);
        if ($res['exit'] === 0) {
            return true;
        }
        if (str_contains($res['stderr'].$res['stdout'], 'HTTP 404')) {
            return false;
        }

        throw new \RuntimeException('gh api tag: '.trim($res['stderr']));
    }

    public function createRelease(string $repo, string $version): void
    {
        $this->gh(['release', 'create', $version, '--generate-notes', '--repo', $this->slug($repo)]);
    }

    public function coldFindings(string $pr): array
    {
        [$owner, $repo, $number] = $this->split($pr);
        $base = "repos/{$owner}/{$repo}";
        $findings = [];
        $add = static function (string $source, array $item, ?string $path, ?int $line) use (&$findings): void {
            if (($item['user']['login'] ?? null) !== self::COLD_REVIEWER) {
                return;
            }
            $body = trim((string) ($item['body'] ?? ''));
            if ($body === '') {
                return;
            }
            $findings[] = ['id' => 'C'.(count($findings) + 1), 'source' => $source, 'path' => $path, 'line' => $line, 'body' => $body];
        };
        foreach ($this->apiPages("{$base}/pulls/{$number}/comments") as $c) {
            $line = $c['line'] ?? $c['original_line'] ?? null;
            $add(ColdFindings::SOURCE_INLINE, $c, isset($c['path']) ? (string) $c['path'] : null, is_int($line) ? $line : null);
        }
        foreach ($this->apiPages("{$base}/pulls/{$number}/reviews") as $r) {
            $add(ColdFindings::SOURCE_REVIEW, $r, null, null);
        }
        foreach ($this->apiPages("{$base}/issues/{$number}/comments") as $c) {
            $add(ColdFindings::SOURCE_COMMENT, $c, null, null);
        }

        return $findings;
    }

    /**
     * All pages of a REST list (`per_page=100`, pages one by one — without --paginate).
     *
     * @return list<array<string, mixed>>
     */
    private function apiPages(string $path): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $chunk = $this->api($path.'?per_page='.self::PAGE_SIZE.'&page='.$page);
            foreach ($chunk as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            if (count($chunk) < self::PAGE_SIZE) {
                return $items;
            }
        }

        throw new \RuntimeException("{$path}: more than ".(self::MAX_PAGES * self::PAGE_SIZE).' records');
    }

    /**
     * @return array<string, mixed>
     */
    private function api(string $path): array
    {
        $out = json_decode($this->gh(['api', $path]), true);

        return is_array($out) ? $out : [];
    }

    public function masterCi(string $repo): array
    {
        [$owner, $name] = $this->split($repo);
        $data = $this->graphql(self::MASTER_QUERY, ['owner' => $owner, 'name' => $name]);
        $ref = $data['data']['repository']['defaultBranchRef'] ?? [];
        $state = $ref['target']['statusCheckRollup']['state'] ?? null;
        $oid = $ref['target']['oid'] ?? null;
        $branch = $ref['name'] ?? null;

        return [
            'state' => is_string($state) ? $state : 'NONE',
            'oid' => is_string($oid) ? $oid : '',
            'branch' => is_string($branch) ? $branch : 'master',
        ];
    }

    public function failedRuns(string $repo, string $branch, string $sha): array
    {
        $out = json_decode($this->gh([
            'run', 'list', '--repo', $this->slug($repo), '--commit', $sha, '--branch', $branch,
            '--status', 'failure', '--json', 'databaseId,url,workflowName', '--limit', '50',
        ]), true);
        $runs = [];
        foreach (is_array($out) ? $out : [] as $run) {
            $id = $run['databaseId'] ?? null;
            $workflow = (string) ($run['workflowName'] ?? '');
            // The list runs from newest to oldest: only the latest run is needed per workflow.
            if (!is_int($id) || isset($runs[$workflow])) {
                continue;
            }
            $runs[$workflow] = ['id' => $id, 'url' => (string) ($run['url'] ?? ''), 'workflow' => $workflow];
        }

        return array_values($runs);
    }

    public function rerunFailed(string $repo, int $id): void
    {
        $this->gh(['run', 'rerun', (string) $id, '--repo', $this->slug($repo), '--failed']);
    }

    public function addLabel(string $task, string $label): void
    {
        [$owner, $repo, $number] = $this->split($task);
        $this->gh(['issue', 'edit', (string) $number, '--repo', "{$owner}/{$repo}", '--add-label', $label]);
    }

    public function markReady(string $task, int $pr): void
    {
        $repo = $task;
        $this->gh(['pr', 'ready', (string) $pr, '--repo', $this->slug($repo)]);
    }

    /**
     * null — the repository has no such workflow. Otherwise false, only while the workflow has
     * an unfinished run on the head. "No runs on head" is true: claude-review.yml
     * does not run on synchronize, so the cold reviewer will not come for a new head.
     * A 404 also happens when the token lacks rights — then the reviewer is considered absent.
     */
    public function coldReviewDone(string $repo, string $head): ?bool
    {
        $path = "repos/{$this->slug($repo)}/actions/workflows/".self::COLD_REVIEW_WORKFLOW."/runs?per_page=20&head_sha={$head}";
        $res = $this->run(['gh', 'api', $path]);
        if ($res['exit'] !== 0) {
            if (str_contains($res['stderr'].$res['stdout'], 'HTTP 404')) {
                return null;
            }

            throw new \RuntimeException('gh api runs: '.trim($res['stderr']));
        }
        $runs = json_decode($res['stdout'], true)['workflow_runs'] ?? [];
        if (!is_array($runs)) {
            return true;
        }
        foreach ($runs as $run) {
            if (($run['status'] ?? '') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>|null
     */
    public static function pickPr(array $nodes): ?array
    {
        $best = null;
        $rank = static fn (array $pr): int => ($pr['merged'] ?? false) ? 2 : (($pr['state'] ?? '') === 'OPEN' ? 1 : 0);
        foreach ($nodes as $pr) {
            if ($best === null
                || $rank($pr) > $rank($best)
                || $rank($pr) === $rank($best) && (int) $pr['number'] > (int) $best['number']) {
                $best = $pr;
            }
        }

        return $best;
    }

    public static function mergeable(mixed $value): string
    {
        return match ($value) {
            'MERGEABLE' => TaskState::MERGEABLE_YES,
            'CONFLICTING' => TaskState::MERGEABLE_NO,
            default => TaskState::MERGEABLE_UNKNOWN,
        };
    }

    /**
     * @param array<string, mixed> $pr
     */
    public static function prState(array $pr): string
    {
        if ($pr['merged'] ?? false) {
            return TaskState::MERGED;
        }
        if (($pr['state'] ?? '') !== 'OPEN') {
            return TaskState::CLOSED;
        }

        return ($pr['isDraft'] ?? false) ? TaskState::DRAFT : TaskState::OPEN;
    }

    /**
     * @param list<array<string, mixed>> $contexts
     */
    public static function checks(array $contexts): string
    {
        if ($contexts === []) {
            return TaskState::CHECKS_NONE;
        }
        $pending = false;
        $failed = false;
        foreach ($contexts as $c) {
            if (($c['__typename'] ?? '') === 'CheckRun') {
                if (($c['status'] ?? '') !== 'COMPLETED') {
                    $pending = true;
                } elseif (!in_array($c['conclusion'] ?? '', ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true)) {
                    $failed = true;
                }
            } else {
                $state = $c['state'] ?? '';
                if (in_array($state, ['PENDING', 'EXPECTED'], true)) {
                    $pending = true;
                } elseif ($state !== 'SUCCESS') {
                    $failed = true;
                }
            }
        }

        return $pending ? TaskState::CHECKS_PENDING : ($failed ? TaskState::CHECKS_FAILED : TaskState::CHECKS_GREEN);
    }

    /** `owner/repo` of a repository or task reference; the short forms get the default owner. */
    private function slug(string $ref): string
    {
        return $this->owners->slug($ref);
    }

    /**
     * @return array{string, string, int} owner, repository, number
     */
    private function split(string $task): array
    {
        [$owner, $repo, $number] = $this->owners->parse($task);

        return [$owner, $repo, (int) $number];
    }

    private static function ts(mixed $value): ?int
    {
        $t = is_string($value) ? strtotime($value) : false;

        return $t === false ? null : $t;
    }

    private static function max(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /**
     * @param array<string, string|int> $vars
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $vars): array
    {
        $args = ['api', 'graphql', '-f', 'query='.$query];
        foreach ($vars as $k => $v) {
            $args[] = is_int($v) ? '-F' : '-f';
            $args[] = "{$k}={$v}";
        }
        $out = json_decode($this->gh($args), true);
        if (!is_array($out) || isset($out['errors'])) {
            throw new \RuntimeException('gh graphql: '.json_encode($out['errors'] ?? $out, JSON_UNESCAPED_UNICODE));
        }

        return $out;
    }

    /**
     * @param list<string> $args
     */
    private function gh(array $args): string
    {
        $res = $this->run(['gh', ...$args]);
        if ($res['exit'] !== 0) {
            throw new \RuntimeException('gh '.($args[0] ?? '').': '.trim($res['stderr']));
        }

        return $res['stdout'];
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function run(array $cmd): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($cmd);
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start gh');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                proc_close($proc);

                throw new \RuntimeException('gh: timeout');
            }
            usleep(50000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($proc);

        return ['exit' => $status['exitcode'], 'stdout' => $out, 'stderr' => $err];
    }
}
