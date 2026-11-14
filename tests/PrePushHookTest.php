<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\GitHooks;
use GpsLab\WorkspaceAssistant\PatchSecrets;
use GpsLab\WorkspaceAssistant\PrePushScan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bot's git hooks (`hooks/git/`) on a real `git push` into a bare repository, with
 * `core.hooksPath` set through `GIT_CONFIG_*` exactly as the Claude process gets it (so a missing
 * executable bit fails the tests). Fake tokens are built by concatenation.
 */
#[CoversClass(GitHooks::class)]
#[CoversClass(PrePushScan::class)]
#[CoversClass(PatchSecrets::class)]
#[CoversClass(ClaudeRunner::class)]
final class PrePushHookTest extends TestCase
{
    private string $root;
    private string $work;
    private string $origin;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tgpp_'.uniqid('', true);
        mkdir($this->root);
        $this->origin = $this->root.'/origin.git';
        $this->work = $this->root.'/work';
        $this->exec(['git', 'init', '-q', '--bare', $this->origin]);
        $this->exec(['git', 'clone', '-q', $this->origin, $this->work]);
        foreach ([['config', 'user.email', 't@t.t'], ['config', 'user.name', 'test'], ['checkout', '-q', '-b', 'main']] as $args) {
            $this->git($args);
        }
        $this->commit('a.txt', "a\n");
        $this->git(['push', '-q', '-u', 'origin', 'main']);
        file_put_contents($this->root.'/bot.env', "SECRET_SCAN_EXCLUDE=fixtures/*\nDEPLOY_TOKEN=".self::knownValue()."\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testHooksDirectoryFilesAreExecutable(): void
    {
        foreach (['pre-push', ...GitHooks::DISPATCHED] as $name) {
            self::assertTrue(is_executable(GitHooks::dir().'/'.$name), $name);
        }
        $staged = (string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' ls-files -s hooks/git 2>/dev/null');
        foreach (array_filter(explode("\n", $staged)) as $entry) {
            if (!str_ends_with($entry, '/lib.sh')) {
                self::assertStringStartsWith('100755 ', $entry);
            }
        }
    }

    public function testProcessEnvAppendsHooksPathAfterInheritedEntries(): void
    {
        $env = (new ClaudeRunner('claude', $this->root, 0, [], extraEnv: ['X' => 'y']))->processEnv();
        $n = (int) getenv('GIT_CONFIG_COUNT');

        self::assertSame((string) ($n + 1), $env['GIT_CONFIG_COUNT']);
        self::assertSame('core.hooksPath', $env['GIT_CONFIG_KEY_'.$n]);
        self::assertSame(GitHooks::dir(), $env['GIT_CONFIG_VALUE_'.$n]);
        self::assertSame('y', $env['X']);
    }

    public function testExistingGitConfigEntriesAreKept(): void
    {
        $env = GitHooks::apply(['GIT_CONFIG_COUNT' => '2', 'GIT_CONFIG_KEY_0' => 'a.b', 'GIT_CONFIG_VALUE_0' => '1', 'GIT_CONFIG_KEY_1' => 'c.d', 'GIT_CONFIG_VALUE_1' => '2']);
        self::assertSame('3', $env['GIT_CONFIG_COUNT']);
        self::assertSame('a.b', $env['GIT_CONFIG_KEY_0']);
        self::assertSame('c.d', $env['GIT_CONFIG_KEY_1']);
        self::assertSame('core.hooksPath', $env['GIT_CONFIG_KEY_2']);
        self::assertSame(GitHooks::dir(), $env['GIT_CONFIG_VALUE_2']);
    }

    public function testPushWithShapeTokenIsBlockedWithoutValue(): void
    {
        $token = self::shapeToken();
        $this->commit('b.txt', "key=$token\n");

        $r = $this->push(['git', 'push', 'origin', 'main']);

        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('b.txt:1 (github_token)', $r['stderr']);
        self::assertStringNotContainsString(substr($token, 4, 12), $r['stderr']);
        self::assertStringNotContainsString($token, $r['stdout']);
        self::assertNotSame($this->head(), $this->originHead());
    }

    public function testPushWithKnownValueIsBlockedWithoutValue(): void
    {
        $this->commit('c.txt', 'x '.self::knownValue()."\n");

        $r = $this->push(['git', 'push', 'origin', 'main']);

        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('(known:DEPLOY_TOKEN)', $r['stderr']);
        self::assertStringNotContainsString(substr(self::knownValue(), 3, 10), $r['stderr']);
    }

    public function testCleanPushPasses(): void
    {
        $this->commit('b.txt', "hello\n");

        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'])['exit']);
        self::assertSame($this->head(), $this->originHead());
    }

    public function testExcludedPathSkipsShapesButNotKnownValues(): void
    {
        $this->commit('fixtures/t.txt', self::shapeToken()."\n");
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'])['exit']);

        $this->commit('fixtures/k.txt', self::knownValue()."\n");
        self::assertSame(1, $this->push(['git', 'push', 'origin', 'main'])['exit']);
    }

    public function testPushFromScriptAndShellIsChecked(): void
    {
        $this->commit('b.txt', 'k='.self::shapeToken()."\n");
        file_put_contents($this->root.'/push.sh', "#!/bin/sh\ngit -C \"\$1\" push origin main\n");
        chmod($this->root.'/push.sh', 0755);

        $viaScript = $this->push([$this->root.'/push.sh', $this->work], $this->root);
        $viaShell = $this->push(['sh', '-c', 'git -C '.escapeshellarg($this->work).' push origin main'], $this->root);

        self::assertSame(1, $viaScript['exit']);
        self::assertSame(1, $viaShell['exit']);
        self::assertNotSame($this->head(), $this->originHead());
    }

    public function testNewBranchIsScannedAgainstRemotes(): void
    {
        $this->git(['checkout', '-q', '-b', 'topic']);
        $this->commit('b.txt', 'k='.self::shapeToken()."\n");

        $blocked = $this->push(['git', 'push', 'origin', 'topic']);
        self::assertSame(1, $blocked['exit']);
        self::assertStringContainsString('b.txt:1', $blocked['stderr']);

        // The secret is already on a remote branch (as a fast-forwardable base): not scanned again.
        $this->git(['push', '-q', '--no-verify', 'origin', 'topic']);
        $this->git(['checkout', '-q', '-b', 'topic2']);
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'topic2'])['exit']);
    }

    public function testRefDeletionPasses(): void
    {
        $this->git(['checkout', '-q', '-b', 'topic']);
        $this->commit('b.txt', "ok\n");
        $this->git(['push', '-q', 'origin', 'topic']);

        self::assertSame(0, $this->push(['git', 'push', 'origin', ':topic'])['exit']);
    }

    public function testRepositoryHooksFromCommonDirRunAndPrePushRunsAfterScan(): void
    {
        $hooks = $this->work.'/.git/hooks';
        file_put_contents($hooks.'/pre-commit', "#!/bin/sh\necho pre-commit >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        file_put_contents($hooks.'/pre-push', "#!/bin/sh\necho \"pre-push \$1 \$(cat | wc -l)\" >> \"\$(git rev-parse --show-toplevel)/../log\"\nexit \${OWN_EXIT:-0}\n");
        chmod($hooks.'/pre-commit', 0755);
        chmod($hooks.'/pre-push', 0755);

        $this->commitWithHooks('b.txt', "ok\n");
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'])['exit']);
        self::assertSame("pre-commit\npre-push origin 1\n", (string) file_get_contents($this->root.'/log'));

        // Its failure fails the push, and it does not run when the scan blocks.
        $this->commit('c.txt', "ok\n");
        self::assertNotSame(0, $this->push(['git', 'push', 'origin', 'main'], null, ['OWN_EXIT' => '3'])['exit']);
        self::assertNotSame($this->head(), $this->originHead());
        $this->commit('d.txt', self::shapeToken()."\n");
        $before = (string) file_get_contents($this->root.'/log');
        self::assertSame(1, $this->push(['git', 'push', 'origin', 'main'], null, ['OWN_EXIT' => '0'])['exit']);
        self::assertSame($before, (string) file_get_contents($this->root.'/log'));
    }

    public function testRepositoryHooksFromLocalHooksPathRun(): void
    {
        mkdir($this->work.'/myhooks');
        file_put_contents($this->work.'/myhooks/pre-push', "#!/bin/sh\necho custom >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        file_put_contents($this->work.'/myhooks/pre-commit', "#!/bin/sh\necho custom-commit >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        chmod($this->work.'/myhooks/pre-push', 0755);
        chmod($this->work.'/myhooks/pre-commit', 0755);
        $this->git(['config', 'core.hooksPath', 'myhooks']);

        $this->commitWithHooks('b.txt', "ok\n");
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'])['exit']);

        self::assertSame("custom-commit\ncustom\n", (string) file_get_contents($this->root.'/log'));
    }

    public function testGlobalHooksPathIsKept(): void
    {
        $home = $this->root.'/home';
        mkdir($home.'/.githooks', 0777, true);
        file_put_contents($home.'/.githooks/pre-push', "#!/bin/sh\necho global >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        chmod($home.'/.githooks/pre-push', 0755);
        file_put_contents($home.'/.gitconfig', "[core]\n\thooksPath = ~/.githooks\n");
        // Without the bot's hooks the global one is what git runs.
        $extra = ['HOME' => $home, 'XDG_CONFIG_HOME' => $home.'/.config', 'GIT_CONFIG_GLOBAL' => $home.'/.gitconfig'];

        $this->commit('b.txt', "ok\n");
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'], null, $extra)['exit']);
        self::assertSame("global\n", (string) file_get_contents($this->root.'/log'));

        $this->commit('c.txt', self::shapeToken()."\n");
        self::assertSame(1, $this->push(['git', 'push', 'origin', 'main'], null, $extra)['exit']);
        self::assertSame("global\n", (string) file_get_contents($this->root.'/log'));
    }

    public function testLocalHooksPathWinsOverGlobal(): void
    {
        $home = $this->root.'/home';
        mkdir($home.'/.githooks', 0777, true);
        mkdir($this->work.'/myhooks');
        file_put_contents($home.'/.githooks/pre-push', "#!/bin/sh\necho global >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        file_put_contents($this->work.'/myhooks/pre-push', "#!/bin/sh\necho local >> \"\$(git rev-parse --show-toplevel)/../log\"\n");
        chmod($home.'/.githooks/pre-push', 0755);
        chmod($this->work.'/myhooks/pre-push', 0755);
        file_put_contents($home.'/.gitconfig', "[core]\n\thooksPath = ~/.githooks\n");
        $this->git(['config', 'core.hooksPath', 'myhooks']);
        $extra = ['HOME' => $home, 'XDG_CONFIG_HOME' => $home.'/.config', 'GIT_CONFIG_GLOBAL' => $home.'/.gitconfig'];

        $this->commit('b.txt', "ok\n");
        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'], null, $extra)['exit']);
        self::assertSame("local\n", (string) file_get_contents($this->root.'/log'));
    }

    public function testRepositoryHookIsNotCalledInALoop(): void
    {
        // The repository points at the bot's own directory: nothing may call itself.
        $this->git(['config', 'core.hooksPath', GitHooks::dir()]);
        $this->commitWithHooks('b.txt', "ok\n");

        self::assertSame(0, $this->push(['git', 'push', 'origin', 'main'])['exit']);
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $extra
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function push(array $cmd, ?string $cwd = null, array $extra = []): array
    {
        return $this->exec($cmd, $cwd ?? $this->work, $this->env($extra));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        $env = GitHooks::apply([...getenv(), GitHooks::ENV_FILE_ENV => $this->root.'/bot.env', ...$extra]);
        unset($env['DEPLOY_TOKEN']);

        return $env;
    }

    private function commit(string $file, string $content): void
    {
        @mkdir(dirname($this->work.'/'.$file), 0777, true);
        file_put_contents($this->work.'/'.$file, $content);
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '--no-verify', '-m', 'c '.$file]);
    }

    /** A commit with the hooks of the bot's directory active, as Claude's own `git commit`. */
    private function commitWithHooks(string $file, string $content): void
    {
        file_put_contents($this->work.'/'.$file, $content);
        $this->git(['add', '-A']);
        $this->exec(['git', 'commit', '-q', '-m', 'c '.$file], $this->work, $this->env());
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): void
    {
        $this->exec(['git', '-C', $this->work, ...$args]);
    }

    private function head(): string
    {
        return trim($this->exec(['git', '-C', $this->work, 'rev-parse', 'HEAD'])['stdout']);
    }

    private function originHead(): string
    {
        return trim($this->exec(['git', '-C', $this->origin, 'rev-parse', 'refs/heads/main'])['stdout']);
    }

    /**
     * @param list<string>               $cmd
     * @param array<string, string>|null $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function exec(array $cmd, ?string $cwd = null, ?array $env = null): array
    {
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function shapeToken(): string
    {
        return 'ghp_'.str_repeat('Ab1', 13);
    }

    private static function knownValue(): string
    {
        return 'zqXv-plain-value-9183';
    }
}
