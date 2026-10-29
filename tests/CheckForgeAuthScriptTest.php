<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `bin/check_forge_auth.sh` and its `bin/check_gh_auth.sh` wrapper against stub `gh`, `glab` and
 * `curl` executables found through PATH (the CI runner has no glab).
 */
final class CheckForgeAuthScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/forge_auth_'.uniqid('', true);
        mkdir($this->root.'/bin', 0777, true);
        mkdir($this->root.'/stubs');
        // The only real tools on PATH: a gh/glab installed on the machine must not leak into the tests.
        mkdir($this->root.'/tools');
        foreach (['bash', 'env', 'dirname', 'grep', 'xargs', 'echo'] as $tool) {
            $path = '/usr/bin/'.$tool;
            if (is_file($path)) {
                symlink($path, $this->root.'/tools/'.$tool);
            }
        }
        foreach (['check_forge_auth.sh', 'check_gh_auth.sh', 'env.sh'] as $file) {
            copy(dirname(__DIR__).'/bin/'.$file, $this->root.'/bin/'.$file);
            chmod($this->root.'/bin/'.$file, 0755);
        }
        // Every stub appends its name and arguments to calls.log.
        foreach (['gh', 'glab', 'curl'] as $name) {
            $this->stub($name, 0);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function stub(string $name, int $exit): void
    {
        file_put_contents($this->root.'/stubs/'.$name, "#!/bin/sh\necho \"{$name} \$*\" >> ".$this->root."/calls.log\necho stub-output\nexit {$exit}\n");
        chmod($this->root.'/stubs/'.$name, 0755);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function script(string $script, array $env, string $envFile = "BOT_TOKEN=t\nALLOWED_USER_IDS=1\n"): array
    {
        file_put_contents($this->root.'/.env', $envFile);
        $vars = '';
        foreach ($env as $key => $value) {
            $vars .= $key.'='.escapeshellarg($value).' ';
        }
        exec("env -u FORGE -u FORGE_HOST -u GH_AUTH_HOST -u BOT_TOKEN -u ALLOWED_USER_IDS {$vars}PATH=".escapeshellarg($this->root.'/stubs:'.$this->root.'/tools').' '
            .escapeshellarg($this->root.'/bin/'.$script).' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private function calls(): string
    {
        return is_file($this->root.'/calls.log') ? (string) file_get_contents($this->root.'/calls.log') : '';
    }

    public function testGithubIsTheDefaultAndSucceedsSilently(): void
    {
        [$code] = $this->script('check_forge_auth.sh', []);

        self::assertSame(0, $code);
        self::assertSame("gh auth status -h github.com\n", $this->calls());
    }

    public function testGithubHonoursGhAuthHostOverride(): void
    {
        $this->script('check_forge_auth.sh', ['GH_AUTH_HOST' => 'ghe.example.com', 'FORGE_HOST' => 'other.example.com']);

        self::assertSame("gh auth status -h ghe.example.com\n", $this->calls());
    }

    public function testGithubUsesForgeHost(): void
    {
        $this->script('check_forge_auth.sh', ['FORGE_HOST' => 'ghe.example.com']);

        self::assertSame("gh auth status -h ghe.example.com\n", $this->calls());
    }

    public function testGitlabRunsGlabWithHostname(): void
    {
        [$code] = $this->script('check_forge_auth.sh', ['FORGE' => 'gitlab', 'FORGE_HOST' => 'gitlab.example.com', 'GH_AUTH_HOST' => 'ignored.example.com']);

        self::assertSame(0, $code);
        self::assertSame("glab auth status --hostname gitlab.example.com\n", $this->calls());
    }

    public function testGitlabDefaultHost(): void
    {
        $this->script('check_forge_auth.sh', ['FORGE' => 'gitlab']);

        self::assertSame("glab auth status --hostname gitlab.com\n", $this->calls());
    }

    public function testForgeAndHostAreReadFromEnvFile(): void
    {
        $this->script('check_forge_auth.sh', [], "BOT_TOKEN=t\nALLOWED_USER_IDS=1\nFORGE=gitlab\nFORGE_HOST=gitlab.example.com\n");

        self::assertSame("glab auth status --hostname gitlab.example.com\n", $this->calls());
    }

    public function testGitlabFailureNotifiesEveryUserWithGlabLoginHint(): void
    {
        $this->stub('glab', 1);

        [$code] = $this->script('check_forge_auth.sh', ['FORGE' => 'gitlab', 'FORGE_HOST' => 'gitlab.example.com', 'ALLOWED_USER_IDS' => '1, 2']);

        self::assertSame(1, $code);
        $calls = $this->calls();
        self::assertSame(2, substr_count($calls, 'curl '));
        self::assertStringContainsString('glab auth login --hostname gitlab.example.com', $calls);
        self::assertStringNotContainsString('gh auth login', $calls);
    }

    public function testGithubFailureNotifiesWithGhLoginHint(): void
    {
        $this->stub('gh', 1);

        [$code] = $this->script('check_forge_auth.sh', []);

        self::assertSame(1, $code);
        self::assertStringContainsString('gh auth login -h github.com', $this->calls());
        self::assertStringContainsString('curl ', $this->calls());
    }

    public function testMissingCliIsAFailure(): void
    {
        unlink($this->root.'/stubs/glab');

        [$code] = $this->script('check_forge_auth.sh', ['FORGE' => 'gitlab']);

        self::assertSame(1, $code);
        self::assertStringContainsString('glab is not installed in PATH', $this->calls());
    }

    public function testUnknownForgeFailsWithoutRunningAnything(): void
    {
        [$code, $out] = $this->script('check_forge_auth.sh', ['FORGE' => 'bitbucket']);

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown FORGE=bitbucket', $out);
        self::assertSame('', $this->calls());
    }

    public function testWithoutBotTokenNothingIsRun(): void
    {
        [$code] = $this->script('check_forge_auth.sh', [], '');

        self::assertSame(1, $code);
        self::assertSame('', $this->calls());
    }

    public function testLegacyWrapperDelegatesAndPassesTheExitCode(): void
    {
        $this->stub('glab', 1);

        [$code] = $this->script('check_gh_auth.sh', ['FORGE' => 'gitlab']);

        self::assertSame(1, $code);
        self::assertStringContainsString('glab auth status --hostname gitlab.com', $this->calls());
    }

    public function testLegacyWrapperContainsNoLogicOfItsOwn(): void
    {
        $lines = array_filter(array_map('trim', file(dirname(__DIR__).'/bin/check_gh_auth.sh') ?: []), static fn (string $l): bool => $l !== '' && $l[0] !== '#');

        self::assertCount(1, $lines);
        self::assertStringContainsString('exec ', (string) reset($lines));
        self::assertStringContainsString('check_forge_auth.sh', (string) reset($lines));
    }
}
