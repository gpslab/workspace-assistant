<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SubRepoSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubRepoSync::class)]
final class SubRepoSyncLinksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/subrepo_'.uniqid('', true);
        mkdir($this->dir.'/repo/.git', 0777, true);
        mkdir($this->dir.'/state', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function sync(string $extraEnv): SubRepoSync
    {
        file_put_contents($this->dir.'/.env', "BOT_TOKEN=t\nALLOWED_USER_IDS=1\nREPO_PATH={$this->dir}/repo\n"
            ."GITHUB_REPO=owner/repo\nCLAUDE_BIN=/usr/bin/claude\nSTATE_DIR={$this->dir}/state\n".$extraEnv);

        return new SubRepoSync(Config::load($this->dir.'/.env'), new Locale());
    }

    private function subRepo(string $name, string $origin): string
    {
        $path = $this->dir.'/'.$name;
        mkdir($path, 0777, true);
        $git = 'git -C '.escapeshellarg($path).' ';
        exec($git.'init -q 2>&1');
        exec($git.'remote add origin '.escapeshellarg($origin));
        exec($git.'-c user.name=t -c user.email=t@t commit -q --allow-empty -m one 2>&1');

        return $path;
    }

    private function head(string $path): string
    {
        return trim((string) shell_exec('git -C '.escapeshellarg($path).' rev-parse HEAD'));
    }

    public function testSubRepoOnGithubKeepsItsLinkWhenManagedRepoIsOnGitlab(): void
    {
        $sync = $this->sync("FORGE=gitlab\nFORGE_HOST=gitlab.example.com\n");
        $path = $this->subRepo('gh', 'git@github.com:org/lib.git');
        $hash = $this->head($path);

        self::assertSame(['https://github.com/org/lib/commit/'.$hash], $sync->collectSubRepoLinks([$path => null]));
    }

    public function testSubRepoOnConfiguredHostUsesConfiguredForge(): void
    {
        $sync = $this->sync("FORGE=gitlab\nFORGE_HOST=gitlab.example.com\n");
        $path = $this->subRepo('gl', 'git@gitlab.example.com:ns/lib.git');
        $hash = $this->head($path);

        self::assertSame(['https://gitlab.example.com/ns/lib/-/commit/'.$hash], $sync->collectSubRepoLinks([$path => null]));
    }

    public function testSubRepoOnGitlabComKeepsItsLinkWhenManagedRepoIsOnGithub(): void
    {
        $sync = $this->sync('');
        $path = $this->subRepo('gl2', 'https://gitlab.com/ns/lib.git');
        $hash = $this->head($path);

        self::assertSame(['https://gitlab.com/ns/lib/-/commit/'.$hash], $sync->collectSubRepoLinks([$path => null]));
    }
}
