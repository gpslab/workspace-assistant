<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\SessionFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionFiles::class)]
final class SessionFilesTest extends TestCase
{
    private string $projectsDir;
    private string $slugDir;

    protected function setUp(): void
    {
        $this->projectsDir = sys_get_temp_dir().'/tgsf_'.uniqid('', true);
        $this->slugDir = $this->projectsDir.'/-some-slug';
        mkdir($this->slugDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Best-effort recursive cleanup of the temp directory.
        $this->rmrf($this->projectsDir);
    }

    private const string UUID_A = '07e50475-f1e5-4a5a-8c46-14e25ad4cd83';
    private const string UUID_B = '1a0b203d-627e-46ad-baa4-15e752982e1c';

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
