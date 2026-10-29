<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Bot;
use GpsLab\WorkspaceAssistant\BuiltinAgents;
use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\RunnerState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuiltinAgents::class)]
#[CoversClass(ClaudeRunner::class)]
final class BuiltinAgentsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/builtin-agents-'.uniqid('', true);
        mkdir($this->root.'/repo/.claude/agents', 0o700, true);
        mkdir($this->root.'/home/.claude/agents', 0o700, true);
        mkdir($this->root.'/prompts/agents', 0o700, true);
        mkdir($this->root.'/state', 0o700);
    }

    protected function tearDown(): void
    {
        PromptFiles::useDirectory(null);
        self::rrmdir($this->root);
    }

    public function testEveryAgentHasFrontmatterToolsAndWriteBan(): void
    {
        $dist = dirname(__DIR__).'/prompts/agents';
        $files = array_map(static fn (string $f): string => basename($f, '.md.dist'), glob($dist.'/*.md.dist') ?: []);
        sort($files);
        $names = array_keys(BuiltinAgents::TOOLS);
        sort($names);
        self::assertSame($names, $files);
        foreach (BuiltinAgents::TOOLS as $name => $tools) {
            $text = (string) file_get_contents($dist.'/'.$name.'.md.dist');
            self::assertMatchesRegularExpression('/\A---\nname: '.preg_quote($name, '/').'\ndescription: [^\n]+\ntools: '.preg_quote(implode(', ', $tools), '/').'\nmodel: inherit\n---\n/', $text, $name);
            self::assertStringNotContainsString('Write', explode('---', $text)[1], $name);
            self::assertStringNotContainsString('Edit', explode('---', $text)[1], $name);
            self::assertStringContainsString('strictly read-only', $text, $name);
            self::assertStringContainsString('Never write, create, edit, move or delete anything in the repository', $text, $name);
            self::assertSame([], PromptFiles::placeholders($text), $name);
        }
        self::assertNotContains('Write', array_merge(...array_values(BuiltinAgents::TOOLS)));
        self::assertNotContains('Edit', array_merge(...array_values(BuiltinAgents::TOOLS)));
    }

    public function testSecurityAgentIsRegisteredReadOnly(): void
    {
        $text = (string) file_get_contents(dirname(__DIR__).'/prompts/agents/security.md.dist');
        self::assertStringContainsString('`composer audit --locked`', $text);
        self::assertStringContainsString('`npm audit`', $text);
        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home');
        self::assertSame(['Read', 'Grep', 'Glob', 'Bash'], $agents['security']['tools']);
    }

    public function testBuildContainsAllAgentsFromFiles(): void
    {
        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home');

        self::assertSame(array_keys(BuiltinAgents::TOOLS), array_keys($agents));
        foreach ($agents as $name => $agent) {
            $dist = (string) file_get_contents(dirname(__DIR__).'/prompts/agents/'.$name.'.md.dist');
            self::assertSame('inherit', $agent['model']);
            self::assertSame(BuiltinAgents::TOOLS[$name], $agent['tools']);
            self::assertNotSame('', $agent['description']);
            self::assertStringContainsString("description: {$agent['description']}\n", $dist);
            self::assertTrue(str_ends_with(trim($dist), trim($agent['prompt'])));
            self::assertNotSame('', trim($agent['prompt']));
            self::assertStringNotContainsString('name: '.$name, $agent['prompt']);
        }
    }

    public function testRepoAgentWithSameNameShadowsBuiltinRegardlessOfFileName(): void
    {
        file_put_contents($this->root.'/repo/.claude/agents/my-critic.md', "---\nname: critic\ndescription: mine\n---\nbody\n");

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home');

        self::assertArrayNotHasKey('critic', $agents);
        self::assertArrayHasKey('verifier', $agents);
    }

    public function testHomeAgentWithSameNameShadowsBuiltinRegardlessOfFileName(): void
    {
        file_put_contents($this->root.'/home/.claude/agents/other.md', "---\nname: \"acceptor\"\ndescription: mine\n---\nbody\n");

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home');

        self::assertArrayNotHasKey('acceptor', $agents);
        self::assertCount(5, $agents);
    }

    public function testFileNameAloneIsNotAMatchAndBrokenFrontmatterIsIgnored(): void
    {
        file_put_contents($this->root.'/repo/.claude/agents/critic.md', "---\nname: something-else\n---\nbody\n");
        file_put_contents($this->root.'/repo/.claude/agents/verifier.md', "no frontmatter at all\n");

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home');

        self::assertArrayHasKey('critic', $agents);
        self::assertArrayHasKey('verifier', $agents);
    }

    public function testLocalOverrideReplacesBody(): void
    {
        PromptFiles::useDirectory($this->root.'/prompts');
        $this->seedDist();
        file_put_contents($this->root.'/prompts/agents/critic.md', "---\nname: critic\ndescription: local\nmodel: inherit\n---\nLocal body {x}\n");
        $warnings = [];

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home', $warnings);

        self::assertSame('Local body {x}', $agents['critic']['prompt']);
        self::assertSame('local', $agents['critic']['description']);
        self::assertSame(BuiltinAgents::TOOLS['critic'], $agents['critic']['tools']);
        self::assertSame([], $warnings);
    }

    public function testLocalOverrideWithForeignNameIsRejectedWithWarning(): void
    {
        PromptFiles::useDirectory($this->root.'/prompts');
        $this->seedDist();
        file_put_contents($this->root.'/prompts/agents/critic.md', "---\nname: other\ndescription: local\n---\nLocal body\n");
        $warnings = [];

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home', $warnings);

        self::assertStringContainsString('strictly read-only', $agents['critic']['prompt']);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('prompts/agents/critic.md', $warnings[0]);
    }

    public function testEmptyLocalOverrideWarningIsKept(): void
    {
        PromptFiles::useDirectory($this->root.'/prompts');
        $this->seedDist();
        file_put_contents($this->root.'/prompts/agents/critic.md', "  \n");
        $warnings = [];

        $agents = BuiltinAgents::build($this->root.'/repo', $this->root.'/home', $warnings);

        self::assertStringContainsString('strictly read-only', $agents['critic']['prompt']);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('empty or unreadable', $warnings[0]);
    }

    public function testInvalidUtf8OverrideFailsStartCleanly(): void
    {
        PromptFiles::useDirectory($this->root.'/prompts');
        $this->seedDist();
        file_put_contents($this->root.'/prompts/agents/critic.md', "---\nname: critic\ndescription: bad \xE0\xE1\n---\nBody \xE0\xE1\n");
        foreach (glob(dirname(__DIR__).'/prompts/*.md.dist') ?: [] as $file) {
            copy($file, $this->root.'/prompts/'.basename($file));
        }
        $bin = $this->fakeClaude($this->root.'/argv.json');

        $runner = new ClaudeRunner($bin, $this->root.'/repo', 0, [], stateDir: $this->root.'/state', homeDir: $this->root.'/home');
        try {
            $runner->start('prompt', null);
            self::fail('start() must throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('agents file', $e->getMessage());
        }

        self::assertSame([], glob($this->root.'/state/runs/*') ?: []);
        self::assertSame([], glob($this->root.'/state/agents/*') ?: []);
    }

    public function testCompactPhasesGetNoAgentsInBot(): void
    {
        self::assertFalse(Bot::runsWithAgents('compact_summarize'));
        self::assertFalse(Bot::runsWithAgents('compact_reseed'));
        foreach (['chat', 'epic', 'queue'] as $kind) {
            self::assertTrue(Bot::runsWithAgents($kind));
        }
    }

    public function testMissingDistIsAnError(): void
    {
        PromptFiles::useDirectory($this->root.'/prompts');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prompts/agents/critic.md.dist');
        BuiltinAgents::build($this->root.'/repo', $this->root.'/home');
    }

    public function testRunPassesAgentsFileAndRemovesIt(): void
    {
        $out = $this->root.'/argv.json';
        $bin = $this->fakeClaude($out);

        $runner = new ClaudeRunner($bin, $this->root.'/repo', 0, [], stateDir: $this->root.'/state', homeDir: $this->root.'/home');
        $runner->start('prompt', 'sess-1');
        $this->finish($runner);

        $rec = $this->record($out);
        $i = array_search('--agents', $rec['argv'], true);
        self::assertIsInt($i);
        self::assertContains('--resume', $rec['argv']);
        self::assertSame($rec['argv'][$i + 1], $rec['path']);
        self::assertSame(array_keys(BuiltinAgents::TOOLS), array_keys($rec['agents']));
        self::assertFileDoesNotExist((string) $rec['path']);
        self::assertSame([], glob($this->root.'/state/agents/*') ?: []);
        self::assertSame([], $runner->promptWarnings());
    }

    public function testEmptyStateDirUsesTempDirAndCleansUp(): void
    {
        $out = $this->root.'/argv.json';
        $bin = $this->fakeClaude($out);
        $before = glob(sys_get_temp_dir().'/claude-run-*') ?: [];

        $runner = new ClaudeRunner($bin, $this->root.'/repo', 0, []);
        $runner->start('prompt', null);
        $this->finish($runner);

        self::assertContains('--agents', $this->record($out)['argv']);
        self::assertFileDoesNotExist((string) $this->record($out)['path']);
        self::assertSame($before, glob(sys_get_temp_dir().'/claude-run-*') ?: []);
    }

    public function testExtraArgsAgentsSkipsBuiltinWithWarning(): void
    {
        $out = $this->root.'/argv.json';
        $bin = $this->fakeClaude($out);

        $runner = new ClaudeRunner($bin, $this->root.'/repo', 0, ['--agents', '{}'], stateDir: $this->root.'/state');
        $runner->start('prompt', null);
        $this->finish($runner);

        $argv = $this->record($out)['argv'];
        self::assertSame(1, count(array_keys($argv, '--agents', true)));
        self::assertCount(1, array_filter($runner->promptWarnings(), static fn (string $w): bool => str_contains($w, '--agents')));
        self::assertSame([], glob($this->root.'/state/agents/*') ?: []);

        // The warning repeats on every run.
        $runner->start('prompt', null);
        $this->finish($runner);
        self::assertCount(1, array_filter($runner->promptWarnings(), static fn (string $w): bool => str_contains($w, '--agents')));
    }

    public function testCompactRunsGetNoAgents(): void
    {
        $out = $this->root.'/argv.json';
        $bin = $this->fakeClaude($out);

        $runner = new ClaudeRunner($bin, $this->root.'/repo', 0, [], stateDir: $this->root.'/state', withAgents: false);
        $runner->start('prompt', null);
        $this->finish($runner);

        self::assertNotContains('--agents', $this->record($out)['argv']);
        self::assertSame([], glob($this->root.'/state/agents/*') ?: []);
    }

    public function testConcurrentRunsUseSeparateFiles(): void
    {
        $bin = $this->phpFake(<<<'PHP'
            $f = getenv('AGENTS_OUT');
            $i = array_search('--agents', $argv, true);
            file_put_contents($f, $argv[$i + 1]);
            sleep(30);
            PHP);
        $a = new ClaudeRunner($bin, $this->root.'/repo', 0, [], stateDir: $this->root.'/state', extraEnv: ['AGENTS_OUT' => $this->root.'/rec-a']);
        $b = new ClaudeRunner($bin, $this->root.'/repo', 0, [], stateDir: $this->root.'/state', extraEnv: ['AGENTS_OUT' => $this->root.'/rec-b']);
        $a->start('p', null);
        $b->start('p', null);

        try {
            $deadline = microtime(true) + 10;
            while (count(glob($this->root.'/rec-*') ?: []) < 2 && microtime(true) < $deadline) {
                usleep(50000);
            }
            $paths = [(string) file_get_contents($this->root.'/rec-a'), (string) file_get_contents($this->root.'/rec-b')];
            self::assertNotSame($paths[0], $paths[1]);
            foreach ($paths as $path) {
                self::assertFileExists($path);
            }
            $a->kill();
            self::assertFileDoesNotExist($paths[0]);
            self::assertFileExists($paths[1]);
        } finally {
            $a->kill();
            $b->kill();
        }
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testPurgeRemovesStaleAgentFiles(): void
    {
        mkdir($this->root.'/state/agents');
        file_put_contents($this->root.'/state/agents/20200101-000000-999999999-abcd.json', '{}');

        ClaudeRunner::purgeRunFiles($this->root.'/state');

        self::assertSame([], glob($this->root.'/state/agents/*') ?: []);
    }

    private function seedDist(): void
    {
        foreach (glob(dirname(__DIR__).'/prompts/agents/*.md.dist') ?: [] as $file) {
            copy($file, $this->root.'/prompts/agents/'.basename($file));
        }
    }

    /** Fake claude: records argv and the content of the --agents file into $out. */
    private function fakeClaude(string $out): string
    {
        return $this->phpFake(<<<'PHP'
            $i = array_search('--agents', $argv, true);
            $path = $i === false ? null : $argv[$i + 1];
            file_put_contents(getenv('AGENTS_OUT'), json_encode([
                'argv' => $argv,
                'path' => $path,
                'agents' => $path === null ? null : json_decode((string) file_get_contents($path), true),
            ]));
            fwrite(STDOUT, '{"type":"result","is_error":false,"result":"ok","session_id":"s"}'."\n");
            PHP, $out);
    }

    private function phpFake(string $code, ?string $out = null): string
    {
        $bin = $this->root.'/fake-'.bin2hex(random_bytes(4));
        file_put_contents($bin, '#!'.PHP_BINARY."\n<?php\n".($out !== null ? 'putenv('.var_export('AGENTS_OUT='.$out, true).');' : '').$code."\n");
        chmod($bin, 0o755);

        return $bin;
    }

    private function finish(ClaudeRunner $runner): void
    {
        $deadline = microtime(true) + 20;
        while ($runner->poll() === RunnerState::Running) {
            self::assertLessThan($deadline, microtime(true), 'the run did not finish');
            usleep(50000);
        }
    }

    /** @return array{argv: list<string>, path: ?string, agents: array<string, array<string, mixed>>} */
    private function record(string $out): array
    {
        return json_decode((string) file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? self::rrmdir($f) : unlink($f);
        }
        rmdir($dir);
    }
}
