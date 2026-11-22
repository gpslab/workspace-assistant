<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionFiles::class)]
final class SessionFilesTest extends TestCase
{
    private string $projectsDir;
    private string $slugDir;
    private string $kimiHome;

    protected function setUp(): void
    {
        $this->projectsDir = sys_get_temp_dir().'/tgsf_'.uniqid('', true);
        $this->slugDir = $this->projectsDir.'/-some-slug';
        mkdir($this->slugDir, 0777, true);

        $this->kimiHome = sys_get_temp_dir().'/tgkf_'.uniqid('', true);
        mkdir($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d', 0777, true);
    }

    protected function tearDown(): void
    {
        // Best-effort recursive cleanup of the temp directories.
        $this->rmrf($this->projectsDir);
        $this->rmrf($this->kimiHome);
    }

    private const string UUID_A = '07e50475-f1e5-4a5a-8c46-14e25ad4cd83';
    private const string UUID_B = '1a0b203d-627e-46ad-baa4-15e752982e1c';

    /** Kimi session ids are the bare uuid with the `session_` prefix. */
    private const string KIMI_A = 'session_'.self::UUID_A;
    private const string KIMI_B = 'session_'.self::UUID_B;

    public function testDeletesTranscriptFileAndSiblingDir(): void
    {
        file_put_contents($this->slugDir.'/'.self::UUID_A.'.jsonl', 'transcript');
        mkdir($this->slugDir.'/'.self::UUID_A);
        file_put_contents($this->slugDir.'/'.self::UUID_A.'/history.txt', 'aux');

        (new SessionFiles($this->projectsDir))->delete(self::UUID_A);

        self::assertFileDoesNotExist($this->slugDir.'/'.self::UUID_A.'.jsonl');
        self::assertDirectoryDoesNotExist($this->slugDir.'/'.self::UUID_A);
    }

    public function testDeletesOnlyTargetSession(): void
    {
        file_put_contents($this->slugDir.'/'.self::UUID_A.'.jsonl', 'a');
        file_put_contents($this->slugDir.'/'.self::UUID_B.'.jsonl', 'b');

        (new SessionFiles($this->projectsDir))->delete(self::UUID_A);

        self::assertFileDoesNotExist($this->slugDir.'/'.self::UUID_A.'.jsonl');
        self::assertFileExists($this->slugDir.'/'.self::UUID_B.'.jsonl');
    }

    public function testMissingTranscriptIsNoop(): void
    {
        // Deleting a missing session neither throws nor touches neighbouring files.
        file_put_contents($this->slugDir.'/'.self::UUID_B.'.jsonl', 'b');

        (new SessionFiles($this->projectsDir))->delete(self::UUID_A);

        self::assertFileExists($this->slugDir.'/'.self::UUID_B.'.jsonl');
    }

    public function testNonUuidIsIgnored(): void
    {
        // Protection against an accidental glob: a non-UUID (including with wildcard characters)
        // must not delete anything.
        file_put_contents($this->slugDir.'/'.self::UUID_A.'.jsonl', 'a');

        (new SessionFiles($this->projectsDir))->delete('*');
        (new SessionFiles($this->projectsDir))->delete('not-a-uuid');
        (new SessionFiles($this->projectsDir))->delete('');

        self::assertFileExists($this->slugDir.'/'.self::UUID_A.'.jsonl');
    }

    public function testDoesNotFollowSymlinksOutsideSession(): void
    {
        // The symlink target lies OUTSIDE the session directory — its contents must not be touched.
        $target = $this->projectsDir.'/outside';
        mkdir($target);
        file_put_contents($target.'/keep.txt', 'keep');

        file_put_contents($this->slugDir.'/'.self::UUID_A.'.jsonl', 'a');
        mkdir($this->slugDir.'/'.self::UUID_A);
        symlink($target, $this->slugDir.'/'.self::UUID_A.'/link');

        (new SessionFiles($this->projectsDir))->delete(self::UUID_A);

        self::assertDirectoryDoesNotExist($this->slugDir.'/'.self::UUID_A);
        self::assertFileExists($target.'/keep.txt');
    }

    public function testDeletesKimiSessionDirectoryInAnyBucket(): void
    {
        // The bucket is derived from the cwd of the run and is not reconstructed: the session is found
        // in the bucket it lives in, whichever that is.
        $secondBucket = $this->kimiHome.'/sessions/wd_-other-repo_deadbeef';
        mkdir($secondBucket, 0777, true);
        mkdir($secondBucket.'/'.self::KIMI_A.'/agents/main', 0777, true);
        file_put_contents($secondBucket.'/'.self::KIMI_A.'/state.json', '{}');
        file_put_contents($secondBucket.'/'.self::KIMI_A.'/agents/main/wire.jsonl', 'wire');

        mkdir($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_B, 0777, true);

        $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, $this->kimiHome);

        self::assertDirectoryDoesNotExist($secondBucket.'/'.self::KIMI_A);
        self::assertDirectoryExists($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_B);
    }

    public function testKimiSessionIdIsRequired(): void
    {
        // Only `session_<uuid>` names a Kimi session: a bare uuid, a wrong prefix, an id with uppercase
        // hex and a path-traversal id must not delete anything (a glob or a stray `..` must not escape
        // the home). Each rejected id has a fixture directory that an unguarded glob would delete, so
        // the assertions fail if the id check is removed.
        $bucket = $this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d';
        mkdir($bucket.'/'.self::UUID_A, 0777, true);
        mkdir($bucket.'/session_short', 0777, true);
        mkdir($bucket.'/session_'.strtoupper(self::UUID_A), 0777, true);

        // A traversal id resolves to `<home>/etc` once the `session_..` component exists in a bucket;
        // the sentinel there must survive.
        mkdir($bucket.'/session_..', 0777, true);
        mkdir($this->kimiHome.'/etc', 0777, true);
        file_put_contents($this->kimiHome.'/etc/sentinel.txt', 'keep');

        $sessionFiles = $this->sessionFiles();
        $sessionFiles->delete(self::UUID_A, Config::ENGINE_KIMI, $this->kimiHome);
        $sessionFiles->delete('session_short', Config::ENGINE_KIMI, $this->kimiHome);
        $sessionFiles->delete('session_'.strtoupper(self::UUID_A), Config::ENGINE_KIMI, $this->kimiHome);
        $sessionFiles->delete('session_../../../../etc', Config::ENGINE_KIMI, $this->kimiHome);
        $sessionFiles->delete('*', Config::ENGINE_KIMI, $this->kimiHome);
        $sessionFiles->delete('', Config::ENGINE_KIMI, $this->kimiHome);

        self::assertDirectoryExists($bucket.'/'.self::UUID_A);
        self::assertDirectoryExists($bucket.'/session_short');
        self::assertDirectoryExists($bucket.'/session_'.strtoupper(self::UUID_A));
        self::assertFileExists($this->kimiHome.'/etc/sentinel.txt');
    }

    public function testKimiSessionOutsideABucketIsNotDeleted(): void
    {
        // A session lives under `sessions/wd_<dir>_<hash>/`: a same-named directory directly under
        // `sessions/` belongs to no layout we know and is left alone.
        mkdir($this->kimiHome.'/sessions/'.self::KIMI_A, 0777, true);

        $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, $this->kimiHome);

        self::assertDirectoryExists($this->kimiHome.'/sessions/'.self::KIMI_A);
    }

    public function testKimiSessionIsKeptOnAnotherEngine(): void
    {
        // The layout follows the engine: on Claude a Kimi session directory is not touched (and a bare
        // uuid on Kimi is not a Kimi session id, see above).
        mkdir($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A, 0777, true);

        $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_OPENCODE, $this->kimiHome);

        self::assertDirectoryExists($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A);
    }

    public function testKimiDeletionDoesNotFollowSymlinks(): void
    {
        // The symlink target lies OUTSIDE the session directory — its contents must not be touched.
        $target = $this->kimiHome.'/outside';
        mkdir($target, 0777, true);
        file_put_contents($target.'/keep.txt', 'keep');

        $session = $this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A;
        mkdir($session, 0777, true);
        file_put_contents($session.'/state.json', '{}');
        symlink($target, $session.'/link');

        $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, $this->kimiHome);

        self::assertDirectoryDoesNotExist($session);
        self::assertFileExists($target.'/keep.txt');
    }

    public function testKimiDeletionIsNoopWhenTheHomeHasNoSessions(): void
    {
        $home = sys_get_temp_dir().'/tgkf-empty-'.uniqid('', true);
        mkdir($home, 0777, true);
        try {
            $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, $home);

            self::assertDirectoryExists($home);
        } finally {
            $this->rmrf($home);
        }
    }

    public function testKimiHomeOfTheProcessIsUsedWhenTheInstanceHasNone(): void
    {
        // The instance's `KIMI_HOME` is empty by default: the home then comes from the variable the
        // engine itself reads (`KIMI_CODE_HOME`).
        mkdir($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A, 0777, true);
        $previous = getenv('KIMI_CODE_HOME');
        putenv('KIMI_CODE_HOME='.$this->kimiHome);
        try {
            $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, '');
        } finally {
            $this->restoreEnv('KIMI_CODE_HOME', $previous);
        }

        self::assertDirectoryDoesNotExist($this->kimiHome.'/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A);
    }

    public function testKimiHomeFallsBackToTheDefaultHomeOfTheUser(): void
    {
        // With neither the instance home nor `KIMI_CODE_HOME` set, the sessions live in the default home
        // of the engine (`$HOME/.kimi-code`) — the shared one the instance usually logs in to.
        $home = sys_get_temp_dir().'/tgkf-user-'.uniqid('', true);
        $session = $home.'/.kimi-code/sessions/wd_-work-repo_0a1b2c3d/'.self::KIMI_A;
        mkdir($session, 0777, true);
        $previousCodeHome = getenv('KIMI_CODE_HOME');
        $previousHome = getenv('HOME');
        putenv('KIMI_CODE_HOME');
        putenv('HOME='.$home);
        try {
            $this->sessionFiles()->delete(self::KIMI_A, Config::ENGINE_KIMI, '');
        } finally {
            $this->restoreEnv('KIMI_CODE_HOME', $previousCodeHome);
            $this->restoreEnv('HOME', $previousHome);
        }

        self::assertDirectoryDoesNotExist($session);
    }

    private function sessionFiles(): SessionFiles
    {
        return new SessionFiles($this->projectsDir);
    }

    /** Restore an environment variable to its value before the test (`false` = it was unset). */
    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }
        putenv($name.'='.$value);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
