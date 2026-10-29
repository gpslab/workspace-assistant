<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\GitManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitManager::class)]
final class ForgeUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function remotes(): iterable
    {
        yield 'gitlab scp' => ['gitlab', 'gitlab.example.com', 'git@gitlab.example.com:ns/sub/proj.git', 'https://gitlab.example.com/ns/sub/proj'];
        yield 'gitlab https' => ['gitlab', 'gitlab.example.com', 'https://gitlab.example.com/ns/proj.git', 'https://gitlab.example.com/ns/proj'];
        yield 'gitlab https with web port' => ['gitlab', 'gitlab.example.com', 'https://gitlab.example.com:8443/ns/proj.git', 'https://gitlab.example.com:8443/ns/proj'];
        yield 'gitlab ssh url' => ['gitlab', 'gitlab.example.com', 'ssh://git@gitlab.example.com/ns/proj.git', 'https://gitlab.example.com/ns/proj'];
        yield 'gitlab ssh url with port' => ['gitlab', 'gitlab.example.com', 'ssh://git@gitlab.example.com:2222/ns/proj.git', 'https://gitlab.example.com/ns/proj'];
        yield 'gitlab no .git suffix' => ['gitlab', 'gitlab.com', 'https://gitlab.com/ns/proj', 'https://gitlab.com/ns/proj'];
        yield 'github scp' => ['github', 'github.com', 'git@github.com:org/repo.git', 'https://github.com/org/repo'];
        yield 'github https' => ['github', 'github.com', 'https://github.com/org/repo.git', 'https://github.com/org/repo'];
        yield 'github ssh url with port' => ['github', 'github.com', 'ssh://git@github.com:22/org/repo.git', 'https://github.com/org/repo'];
    }

    #[DataProvider('remotes')]
    public function testCommitUrl(string $forge, string $host, string $remote, string $base): void
    {
        $dash = $forge === 'gitlab' ? '/-' : '';
        self::assertSame("{$base}{$dash}/commit/abc", GitManager::buildCommitUrl($remote, 'abc', $forge, $host));
    }

    #[DataProvider('remotes')]
    public function testFileUrl(string $forge, string $host, string $remote, string $base): void
    {
        $dash = $forge === 'gitlab' ? '/-' : '';
        self::assertSame("{$base}{$dash}/blob/main/a%20b/c.md", GitManager::buildFileUrl($remote, 'main', '/a b/c.md', $forge, $host));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wrongHosts(): iterable
    {
        yield 'gitlab other host scp' => ['gitlab', 'gitlab.example.com', 'git@other.example.com:ns/proj.git'];
        yield 'gitlab other host https' => ['gitlab', 'gitlab.example.com', 'https://other.example.com/ns/proj.git'];
        yield 'gitlab other host ssh url' => ['gitlab', 'gitlab.example.com', 'ssh://git@other.example.com:2222/ns/proj.git'];
        yield 'github remote on gitlab instance' => ['gitlab', 'gitlab.com', 'git@github.com:org/repo.git'];
        yield 'gitlab remote on github instance' => ['github', 'github.com', 'git@gitlab.com:ns/proj.git'];
        yield 'host suffix is not a match' => ['gitlab', 'gitlab.com', 'https://gitlab.com.evil.example/ns/proj.git'];
    }

    #[DataProvider('wrongHosts')]
    public function testWrongHostGivesBareHashAndNoFileUrl(string $forge, string $host, string $remote): void
    {
        self::assertSame('abc', GitManager::buildCommitUrl($remote, 'abc', $forge, $host));
        self::assertNull(GitManager::buildFileUrl($remote, 'main', 'a.md', $forge, $host));
    }

    public function testEmptyBranchGivesNoFileUrl(): void
    {
        self::assertNull(GitManager::buildFileUrl('git@github.com:o/r.git', '', 'a.md', 'github', 'github.com'));
    }

    public function testInstanceBuildersUseConfiguredForgeAndOrigin(): void
    {
        $repo = sys_get_temp_dir().'/forge_url_'.uniqid('', true);
        mkdir($repo);
        exec('git -C '.escapeshellarg($repo).' init -q -b main 2>&1');
        exec('git -C '.escapeshellarg($repo).' remote add origin ssh://git@gitlab.example.com:2222/ns/proj.git');

        try {
            $git = new GitManager($repo, 30, new \GpsLab\WorkspaceAssistant\Locale(), 'gitlab', 'gitlab.example.com');
            self::assertSame('https://gitlab.example.com/ns/proj/-/commit/abc', $git->forgeCommitUrl('abc'));
            self::assertSame('https://gitlab.example.com/ns/proj/-/blob/main/x.md', $git->forgeFileUrl('x.md'));

            $other = new GitManager($repo);
            self::assertSame('abc', $other->forgeCommitUrl('abc'));
            self::assertNull($other->forgeFileUrl('x.md'));
        } finally {
            exec('rm -rf '.escapeshellarg($repo));
        }
    }
}
