<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Env;
use GpsLab\WorkspaceAssistant\RunResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunResult::class)]
final class RunResultCommitUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function urls(): iterable
    {
        yield 'github default' => [['FORGE_REPO' => 'acme/proj'], 'https://github.com/acme/proj/commit/abc123'];
        yield 'github enterprise host' => [['FORGE_REPO' => 'acme/proj', 'FORGE_HOST' => 'git.example.org'], 'https://git.example.org/acme/proj/commit/abc123'];
        yield 'gitlab default host' => [['FORGE' => 'gitlab', 'FORGE_REPO' => 'group/sub/proj'], 'https://gitlab.com/group/sub/proj/-/commit/abc123'];
        yield 'gitlab self-hosted' => [['FORGE' => 'gitlab', 'FORGE_HOST' => 'git.example.org', 'FORGE_REPO' => 'group/proj'], 'https://git.example.org/group/proj/-/commit/abc123'];
        yield 'no repo' => [[], 'abc123'];
    }

    /**
     * @param array<string, string> $vars
     */
    #[DataProvider('urls')]
    public function testCommitUrlFollowsForgeAndHost(array $vars, string $expected): void
    {
        $dir = sys_get_temp_dir().'/commit_url_'.uniqid('', true);
        mkdir($dir.'/.git', 0777, true);
        $lines = ['BOT_TOKEN=t', 'ALLOWED_USER_IDS=1', 'REPO_PATH='.$dir, 'STATE_DIR='.$dir.'/state'];
        foreach ($vars as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        file_put_contents($dir.'/.env', implode("\n", $lines)."\n");

        try {
            $config = Config::fromEnv(Env::load($dir.'/.env', true), $dir);
            $runResult = (new \ReflectionClass(RunResult::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(RunResult::class, 'config'))->setValue($runResult, $config);

            self::assertSame($expected, $runResult->commitUrl('abc123'));
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
