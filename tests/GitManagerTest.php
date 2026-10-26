<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\GitManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitManager::class)]
final class GitManagerTest extends TestCase
{
    private string $repo;
    private GitManager $git;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/tggit_'.uniqid('', true);
        mkdir($this->repo);

        foreach (['init -q', 'config user.email t@t.t', 'config user.name test'] as $cmd) {
            exec('git -C '.escapeshellarg($this->repo).' '.$cmd);
        }

        $this->git = new GitManager($this->repo);
    }

    /** @var list<string> temp directories to remove in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
        foreach ($this->tmpDirs as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    /** Creates a commit with a unique file in the test repository. */
    private function commit(string $message): void
    {
        file_put_contents($this->repo.'/'.uniqid('f_', true).'.md', $message);
        exec('git -C '.escapeshellarg($this->repo).' add -A');
        exec('git -C '.escapeshellarg($this->repo).' commit -qm '.escapeshellarg($message));
    }

    /**
     * Creates a bare remote with one commit and a clone tracking origin/master.
     * Both directories are cleaned up in tearDown.
     *
     * @return array{0: string, 1: string} [clone path, bare remote path]
     */
    private function seedClone(string $tag): array
    {
        $remote = sys_get_temp_dir().'/tgpp_rem_'.$tag.'_'.uniqid('', true);
        $seed = sys_get_temp_dir().'/tgpp_seed_'.$tag.'_'.uniqid('', true);
        $work = sys_get_temp_dir().'/tgpp_wrk_'.$tag.'_'.uniqid('', true);
        $this->tmpDirs[] = $remote;
        $this->tmpDirs[] = $seed;
        $this->tmpDirs[] = $work;

        exec('git init -q --bare '.escapeshellarg($remote));
        exec('git clone -q '.escapeshellarg($remote).' '.escapeshellarg($seed).' 2>/dev/null');
        exec('git -C '.escapeshellarg($seed).' config user.email s@s.s');
        exec('git -C '.escapeshellarg($seed).' config user.name seed');
        file_put_contents($seed.'/init.md', '1');
        exec('git -C '.escapeshellarg($seed).' add -A');
        exec('git -C '.escapeshellarg($seed).' commit -qm init');
        exec('git -C '.escapeshellarg($seed).' branch -M master');
        exec('git -C '.escapeshellarg($seed).' push -q origin master');

        exec('git clone -q '.escapeshellarg($remote).' '.escapeshellarg($work));

        return [$work, $remote];
    }

    /** Adds a commit to the bare remote (simulating someone else's push) so that the clone falls behind. */
    private function pushToRemote(string $remote, string $tag): void
    {
        $pusher = sys_get_temp_dir().'/tgpp_push_'.$tag.'_'.uniqid('', true);
        $this->tmpDirs[] = $pusher;
        exec('git clone -q '.escapeshellarg($remote).' '.escapeshellarg($pusher));
        exec('git -C '.escapeshellarg($pusher).' config user.email p@p.p');
        exec('git -C '.escapeshellarg($pusher).' config user.name pusher');
        file_put_contents($pusher.'/'.uniqid('new_', true).'.md', 'x');
        exec('git -C '.escapeshellarg($pusher).' add -A');
        exec('git -C '.escapeshellarg($pusher).' commit -qm remote-work');
        exec('git -C '.escapeshellarg($pusher).' push -q origin master');
    }

    public function testFreshRepoIsClean(): void
    {
        self::assertTrue($this->git->isClean());
        self::assertFalse($this->git->hasChanges());
    }

    public function testUntrackedFileMakesRepoDirty(): void
    {
        file_put_contents($this->repo.'/note.md', 'hello');

        self::assertTrue($this->git->hasChanges());
        self::assertFalse($this->git->isClean());
    }

    public function testDiscardRemovesUntrackedChanges(): void
    {
        file_put_contents($this->repo.'/note.md', 'hello');
        $this->git->discardChanges();

        self::assertTrue($this->git->isClean());
        self::assertFileDoesNotExist($this->repo.'/note.md');
    }

    public function testChangesSummaryListsModifiedFile(): void
    {
        file_put_contents($this->repo.'/note.md', 'hello');

        self::assertStringContainsString('note.md', $this->git->changesSummary());
    }

    public function testHeadHashOrNullOnUnbornBranch(): void
    {
        self::assertNull($this->git->headHashOrNull());
    }

    public function testHeadHashOrNullAfterCommit(): void
    {
        file_put_contents($this->repo.'/f.md', 'x');
        exec('git -C '.escapeshellarg($this->repo).' add -A');
        exec('git -C '.escapeshellarg($this->repo).' commit -qm "init"');

        self::assertNotNull($this->git->headHashOrNull());
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $this->git->headHashOrNull());
    }

    public function testForPathReturnsNullForNonGitDir(): void
    {
        self::assertNull(GitManager::forPath(sys_get_temp_dir().'/nonexistent_'.uniqid()));
    }

    public function testForPathReturnsSelfForGitDir(): void
    {
        self::assertInstanceOf(GitManager::class, GitManager::forPath($this->repo));
    }

    public function testCurrentBranchReportsCheckedOutBranch(): void
    {
        $this->commit('init');
        exec('git -C '.escapeshellarg($this->repo).' checkout -q -b work');

        self::assertSame('work', $this->git->currentBranch());
    }

    public function testUpstreamRefNullWithoutRemote(): void
    {
        $this->commit('init');

        self::assertNull($this->git->upstreamRef());
    }

    /**
     * The key bug scenario: the clone sits on a branch that was deleted on the remote
     * (after a PR merge). fetch --prune removes the stale ref, after which the
     * upstream no longer resolves — pullSubRepos catches this with a clear string.
     */
    public function testUpstreamRefGoneAfterRemoteBranchDeleted(): void
    {
        $remote = sys_get_temp_dir().'/tgremote_'.uniqid('', true);
        exec('git init -q --bare '.escapeshellarg($remote));

        $this->commit('init');
        exec('git -C '.escapeshellarg($this->repo).' checkout -q -b feature');
        exec('git -C '.escapeshellarg($this->repo).' remote add origin '.escapeshellarg($remote));
        exec('git -C '.escapeshellarg($this->repo).' push -q -u origin feature');

        // upstream is configured and exists
        self::assertSame('origin/feature', $this->git->upstreamRef());

        // the branch was deleted on the remote → fetch --prune erases origin/feature
        exec('git -C '.escapeshellarg($remote).' branch -D feature');
        $error = '';
        self::assertTrue($this->git->fetchPrune($error), $error);

        // upstream no longer resolves — but the clone branch is still 'feature'
        self::assertNull($this->git->upstreamRef());
        self::assertSame('feature', $this->git->currentBranch());

        exec('rm -rf '.escapeshellarg($remote));
    }

    public function testRebaseOntoUpstreamFastForwards(): void
    {
        $remote = sys_get_temp_dir().'/tgremote_'.uniqid('', true);
        exec('git init -q --bare '.escapeshellarg($remote));

        $this->commit('init');
        exec('git -C '.escapeshellarg($this->repo).' remote add origin '.escapeshellarg($remote));
        exec('git -C '.escapeshellarg($this->repo).' push -q -u origin HEAD:refs/heads/master');
        exec('git -C '.escapeshellarg($this->repo).' branch --set-upstream-to=origin/master');

        // an independent clone adds a commit and pushes — ours falls behind
        $other = sys_get_temp_dir().'/tgother_'.uniqid('', true);
        exec('git clone -q '.escapeshellarg($remote).' '.escapeshellarg($other));
        exec('git -C '.escapeshellarg($other).' config user.email o@o.o');
        exec('git -C '.escapeshellarg($other).' config user.name other');
        file_put_contents($other.'/new.md', 'x');
        exec('git -C '.escapeshellarg($other).' add -A && git -C '.escapeshellarg($other).' commit -qm "remote work"');
        exec('git -C '.escapeshellarg($other).' push -q origin HEAD:master');

        $before = $this->git->headHash();
        $error = '';
        self::assertTrue($this->git->fetchPrune($error), $error);
        self::assertTrue($this->git->rebaseOntoUpstream($error), $error);

        self::assertNotSame($before, $this->git->headHash());
        self::assertFileExists($this->repo.'/new.md');

        exec('rm -rf '.escapeshellarg($remote).' '.escapeshellarg($other));
    }

    public function testDefaultBranchReadsRemoteHead(): void
    {
        $remote = sys_get_temp_dir().'/tgremote_'.uniqid('', true);
        exec('git init -q --bare '.escapeshellarg($remote));

        $this->commit('init');
        exec('git -C '.escapeshellarg($this->repo).' remote add origin '.escapeshellarg($remote));
        // The remote default is NOT master (like 2.x in some repositories).
        exec('git -C '.escapeshellarg($this->repo).' push -q origin HEAD:refs/heads/2.x');
        exec('git -C '.escapeshellarg($remote).' symbolic-ref HEAD refs/heads/2.x');

        self::assertSame('2.x', $this->git->defaultBranch());

        exec('rm -rf '.escapeshellarg($remote));
    }

    public function testIsMergedIntoTrueWhenCurrentIsAncestor(): void
    {
        $this->commit('base');
        exec('git -C '.escapeshellarg($this->repo).' branch other');

        // HEAD == other == base → HEAD is an ancestor of other
        self::assertTrue($this->git->isMergedInto('other'));
    }

    public function testIsMergedIntoFalseWhenCurrentHasUniqueCommits(): void
    {
        $this->commit('base');
        exec('git -C '.escapeshellarg($this->repo).' branch other');
        $this->commit('ahead'); // HEAD moved ahead of other

        self::assertFalse($this->git->isMergedInto('other'));
    }

    public function testCheckoutSwitchesBranch(): void
    {
        $this->commit('init');
        exec('git -C '.escapeshellarg($this->repo).' branch target');

        $error = '';
        self::assertTrue($this->git->checkout('target', $error), $error);
        self::assertSame('target', $this->git->currentBranch());
    }

    public function testCheckoutFailsForMissingBranch(): void
    {
        $this->commit('init');

        $error = '';
        self::assertFalse($this->git->checkout('no-such-branch', $error));
        self::assertNotSame('', $error);
    }

    public function testFetchPruneParallelFetchesAllRepos(): void
    {
        [$workA, $remoteA] = $this->seedClone('a');
        [$workB, $remoteB] = $this->seedClone('b');

        // Both clones are one commit behind their remotes.
        $this->pushToRemote($remoteA, 'a');
        $this->pushToRemote($remoteB, 'b');

        $beforeA = trim((string) shell_exec('git -C '.escapeshellarg($workA).' rev-parse origin/master'));
        $beforeB = trim((string) shell_exec('git -C '.escapeshellarg($workB).' rev-parse origin/master'));

        $results = GitManager::fetchPruneParallel(['a' => $workA, 'b' => $workB], 30);

        self::assertTrue($results['a']['ok'], $results['a']['error']);
        self::assertTrue($results['b']['ok'], $results['b']['error']);
        self::assertGreaterThan(0.0, $results['a']['sec']);

        // origin/master in each clone advanced — fetch really worked.
        $afterA = trim((string) shell_exec('git -C '.escapeshellarg($workA).' rev-parse origin/master'));
        $afterB = trim((string) shell_exec('git -C '.escapeshellarg($workB).' rev-parse origin/master'));
        self::assertNotSame($beforeA, $afterA);
        self::assertNotSame($beforeB, $afterB);
    }

    public function testFetchPruneParallelReportsFailurePerRepo(): void
    {
        [$workOk] = $this->seedClone('ok');
        $this->pushToRemote(
            trim((string) shell_exec('git -C '.escapeshellarg($workOk).' remote get-url origin')),
            'ok'
        );

        // The second repository points origin at a nonexistent path → fetch will fail.
        $broken = sys_get_temp_dir().'/tgpp_broken_'.uniqid('', true);
        $this->tmpDirs[] = $broken;
        exec('git init -q '.escapeshellarg($broken));
        exec('git -C '.escapeshellarg($broken).' remote add origin '.escapeshellarg($broken.'/nope.git'));

        $results = GitManager::fetchPruneParallel(['ok' => $workOk, 'broken' => $broken], 30);

        self::assertTrue($results['ok']['ok'], $results['ok']['error']);
        self::assertFalse($results['broken']['ok']);
        self::assertNotSame('', $results['broken']['error']);
    }

    public function testGithubCommitUrlWithNoRemote(): void
    {
        $hash = str_repeat('a', 40);
        self::assertSame($hash, $this->git->githubCommitUrl($hash));
    }

    public function testGithubCommitUrlWithSshRemote(): void
    {
        exec('git -C '.escapeshellarg($this->repo).' remote add origin git@github.com:anime-db/test-repo.git');
        $hash = str_repeat('b', 40);

        self::assertSame(
            'https://github.com/anime-db/test-repo/commit/'.$hash,
            $this->git->githubCommitUrl($hash)
        );
    }

    public function testGithubCommitUrlWithHttpsRemote(): void
    {
        exec('git -C '.escapeshellarg($this->repo).' remote add origin https://github.com/anime-db/test-repo.git');
        $hash = str_repeat('c', 40);

        self::assertSame(
            'https://github.com/anime-db/test-repo/commit/'.$hash,
            $this->git->githubCommitUrl($hash)
        );
    }

    public function testGithubFileUrlWithNoRemote(): void
    {
        self::assertNull($this->git->githubFileUrl('context/epics/a.md'));
    }

    public function testGithubFileUrlUsesCurrentBranch(): void
    {
        exec('git -C '.escapeshellarg($this->repo).' remote add origin git@github.com:anime-db/test-repo.git');
        $branch = trim((string) shell_exec('git -C '.escapeshellarg($this->repo).' symbolic-ref --short HEAD'));

        self::assertSame(
            'https://github.com/anime-db/test-repo/blob/'.$branch.'/context/epics/a.md',
            $this->git->githubFileUrl('context/epics/a.md')
        );
    }

    public function testGithubFileUrlEncodesBranchAndPathSegments(): void
    {
        exec('git -C '.escapeshellarg($this->repo).' remote add origin git@github.com:anime-db/test-repo.git');
        exec('git -C '.escapeshellarg($this->repo).' checkout -q -b feature/x');

        self::assertSame(
            'https://github.com/anime-db/test-repo/blob/feature/x/docs/a%20b%23c.md',
            $this->git->githubFileUrl('docs/a b#c.md')
        );
    }

    public function testGithubFileUrlOnDetachedHeadIsNull(): void
    {
        exec('git -C '.escapeshellarg($this->repo).' remote add origin git@github.com:anime-db/test-repo.git');
        exec('git -C '.escapeshellarg($this->repo).' commit -q --allow-empty -m init');
        exec('git -C '.escapeshellarg($this->repo).' checkout -q --detach');

        self::assertNull($this->git->githubFileUrl('context/epics/a.md'));
    }

    public function testChangedPathsInListsRenameUntrackedAndDeletedWithoutSourcePaths(): void
    {
        $sh = fn (string $cmd) => exec('cd '.escapeshellarg($this->repo).' && '.$cmd.' 2>&1');
        $sh('mkdir prompts && echo a > prompts/old.md && echo b > prompts/gone.md && echo c > outside.md && echo d > prompts/wt.md && git add -A && git commit -q -m init');
        $sh('git mv prompts/old.md prompts/new.md');
        $sh('rm prompts/gone.md');
        $sh('echo e > prompts/fresh.md');
        $sh('echo f >> outside.md');

        $paths = $this->git->changedPathsIn('prompts');
        sort($paths);

        self::assertSame(['prompts/fresh.md', 'prompts/gone.md', 'prompts/new.md'], $paths);
    }

    public function testChangedPathsInSkipsSourceOfWorktreeRename(): void
    {
        $sh = fn (string $cmd) => exec('cd '.escapeshellarg($this->repo).' && '.$cmd.' 2>&1');
        $sh('mkdir prompts && seq 1 30 > prompts/old.md && git add -A && git commit -q -m init');
        $sh('git mv prompts/old.md prompts/new.md && git reset -q && git add -N prompts/new.md');

        self::assertSame(['prompts/new.md'], $this->git->changedPathsIn('prompts'));
    }
}
