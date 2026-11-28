<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\IdentityRouter;
use GpsLab\WorkspaceAssistant\Coder\Github\NamedGithubClient;
use GpsLab\WorkspaceAssistant\Coder\Github\StaticCredentialProvider;
use GpsLab\WorkspaceAssistant\Coder\Publication\Config;
use GpsLab\WorkspaceAssistant\Coder\Publication\DiffGuard;
use GpsLab\WorkspaceAssistant\Coder\Publication\PublicationController;
use GpsLab\WorkspaceAssistant\Coder\PublicationRequest;
use GpsLab\WorkspaceAssistant\Coder\Store;
use PHPUnit\Framework\TestCase;

abstract class PublicationTestCase extends TestCase
{
    protected string $root;
    protected string $stateDir;
    protected string $worktreeRoot;
    protected Store $store;
    protected FakeGithubTransport $transport;
    protected IdentityRouter $clients;
    protected int $now = 105;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/coder-publication-'.uniqid('', true);
        $this->stateDir = $this->root.'/state';
        $this->worktreeRoot = $this->root.'/worktrees';
        mkdir($this->root.'/managed', 0700, true);
        mkdir($this->worktreeRoot, 0700, true);
        $this->store = new Store($this->stateDir);
        $this->transport = new FakeGithubTransport();
        $this->transport->actors = ['reviewer-token' => 'reviewer', 'coder-token' => 'coder'];
        $reviewer = new StaticCredentialProvider('reviewer', 'reviewer-token', $this->stateDir.'/reviewer-config');
        $coder = new StaticCredentialProvider('coder', 'coder-token', $this->stateDir.'/coder-config', ['repo'], true);
        $this->clients = new IdentityRouter(
            new NamedGithubClient($this->transport, $reviewer),
            new NamedGithubClient($this->transport, $coder),
        );
    }

    protected function tearDown(): void
    {
        $this->store->disconnect();
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /** @param list<string> $protectedPaths */
    protected function controller(array $protectedPaths = []): PublicationController
    {
        return new PublicationController(
            $this->store, $this->clients, new DiffGuard($protectedPaths),
            new Config('master', $protectedPaths), fn (): int => $this->now,
        );
    }

    protected function job(string $path = 'src/change.txt', string $content = "safe change\n"): PublicationRequest
    {
        $id = $this->store->enqueue('acme/repo', 'pending', 7, diagnostics: ['prompt' => 'Implement fixture'], now: 100);
        $worktree = $this->worktreeRoot.'/job-'.$id;
        mkdir($worktree, 0700, true);
        $this->git($worktree, ['init', '-q', '-b', 'master']);
        $this->git($worktree, ['config', 'user.name', 'Fixture']);
        $this->git($worktree, ['config', 'user.email', 'fixture@example.test']);
        file_put_contents($worktree.'/README.md', "base\n");
        $this->git($worktree, ['add', '-A']);
        $this->git($worktree, ['commit', '-q', '-m', 'Base']);
        $base = $this->git($worktree, ['rev-parse', 'HEAD']);
        $file = $worktree.'/'.$path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, $content);
        $this->git($worktree, ['add', '-A']);
        $this->git($worktree, ['commit', '-q', '-m', 'Coder change']);
        $head = $this->git($worktree, ['rev-parse', 'HEAD']);
        $this->git($worktree, ['checkout', '-q', '-b', 'coder/job-'.$id]);
        $this->store->registerWorker(1, 100);
        $lease = $this->store->assign(1, 300, 100);
        self::assertNotNull($lease);
        $diagnostics = [
            'prompt' => 'Implement fixture', 'base_head' => $base,
            'local_result' => ['lease_generation' => $lease['lease_generation']],
            'checks' => ['full' => [['argv' => ['composer', 'test'], 'exit' => 0]]],
        ];
        self::assertTrue($this->store->updateContext(
            $id, 1, $lease['lease_token'], $head, 'session-1', $worktree, $diagnostics, now: 101, branch: 'coder/job-'.$id,
        ));
        self::assertTrue($this->store->transition($id, Store::RUNNING, 1, $lease['lease_token'], 102));
        self::assertTrue($this->store->transition($id, Store::AWAITING_ACTION, 1, $lease['lease_token'], 103));
        $this->transport->issues['acme/repo#7'] = [
            'number' => 7, 'state' => 'open', 'labels' => [['name' => PublicationController::DO_IT]],
        ];

        return new PublicationRequest($id, 'run-1', 'coder/job-'.$id, $worktree, $head, 'session-1', 1, $lease['lease_token'], 1);
    }

    /** @param list<string> $args */
    protected function git(string $cwd, array $args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, trim((string) $stderr));

        return trim((string) $stdout);
    }
}
