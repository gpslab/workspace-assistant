<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Coder\CheckContract;
use GpsLab\WorkspaceAssistant\Coder\CheckRunner;
use GpsLab\WorkspaceAssistant\Coder\Config;
use GpsLab\WorkspaceAssistant\Coder\ExecutionEnvironment;
use GpsLab\WorkspaceAssistant\Coder\ImplementationChecks;
use GpsLab\WorkspaceAssistant\Coder\LocalResult;
use GpsLab\WorkspaceAssistant\Coder\Process;
use GpsLab\WorkspaceAssistant\Coder\ProcessSupervisor;
use GpsLab\WorkspaceAssistant\Coder\PublicationRequest;
use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\Coder\Worker;
use GpsLab\WorkspaceAssistant\Coder\Worktree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Worker::class)]
#[CoversClass(Worktree::class)]
#[CoversClass(CheckContract::class)]
#[CoversClass(CheckRunner::class)]
#[CoversClass(ExecutionEnvironment::class)]
#[CoversClass(ImplementationChecks::class)]
#[CoversClass(LocalResult::class)]
#[CoversClass(PublicationRequest::class)]
#[CoversClass(Process::class)]
final class WorkerTest extends TestCase
{
    private const string AUTHOR_NAME = 'Coder Fixture';
    private const string AUTHOR_EMAIL = 'coder@example.test';

    private string $root;
    private string $repository;
    private string $stateDir;
    private string $worktreeDir;
    private string $logDir;
    private string $baseHead;
    private Store $store;
    /** @var array<string, string> */
    private array $originalEnvironment = [];
    /** @var list<int> */
    private array $processGroups = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/coder-worker-'.uniqid('', true);
        $this->repository = $this->root.'/repository';
        $this->stateDir = $this->root.'/state';
        $this->worktreeDir = $this->root.'/worktrees';
        $this->logDir = $this->root.'/logs';
        mkdir($this->repository, 0700, true);
        mkdir($this->logDir);
        $this->writeComposerScripts();
        file_put_contents($this->repository.'/.gitignore', "/vendor/\n");
        file_put_contents($this->repository.'/README.md', "fixture\n");
        file_put_contents($this->repository.'/check.php', <<<'PHP'
            <?php
            $log = getenv('CODER_TEST_CHECK_LOG');
            if (is_string($log) && $log !== '') {
                file_put_contents($log, $argv[1]."\n", FILE_APPEND);
            }
            PHP);
        $this->git($this->repository, ['init', '-q']);
        $this->git($this->repository, ['config', 'user.name', 'Repository Owner']);
        $this->git($this->repository, ['config', 'user.email', 'owner@example.test']);
        $this->composer($this->repository, ['update', '--no-interaction', '--no-plugins', '--no-scripts', '--no-install']);
        $this->git($this->repository, ['add', '-A']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Initial fixture']);
        $this->baseHead = $this->git($this->repository, ['rev-parse', 'HEAD']);
        $this->store = new Store($this->stateDir);
        /** @var array<string, string> $environment */
        $environment = getenv();
        $this->originalEnvironment = $environment;
    }

    protected function tearDown(): void
    {
        foreach ($this->processGroups as $pgid) {
            ProcessSupervisor::terminateGroup($pgid);
        }
        $this->store->disconnect();
        /** @var array<string, string> $currentEnvironment */
        $currentEnvironment = getenv();
        foreach (array_diff_key($currentEnvironment, $this->originalEnvironment) as $name => $value) {
            putenv($name);
        }
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($name.'='.$value);
        }
        $this->remove($this->root);
    }

    public function testFixtureJobProducesIsolatedCommitResultAndSanitizedEngine(): void
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'GITLAB_TOKEN', 'GL_TOKEN'] as $name) {
            putenv($name.'=inherited-'.$name);
        }
        putenv('SSH_AUTH_SOCK=/tmp/inherited-agent.sock');
        putenv('GIT_ASKPASS=/tmp/inherited-askpass');
        $id = $this->enqueue();
        $managedHead = $this->git($this->repository, ['rev-parse', 'HEAD']);

        $worker = $this->worker();
        self::assertFalse(getenv('GH_TOKEN'), 'The explicitly invoked worker must not retain forge credentials.');
        $result = $worker->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        self::assertSame($id, $result->jobId);
        self::assertSame('coder/job-'.$id, $result->branch);
        self::assertSame('coder-session-1', $result->sessionId);
        self::assertNotNull($result->publicationRequest());
        self::assertSame($this->worktreeDir.'/job-'.$id, $result->worktree);
        self::assertFileExists($result->worktree.'/coder.txt');
        self::assertSame($managedHead, $this->git($this->repository, ['rev-parse', 'HEAD']));
        self::assertSame('', $this->git($this->repository, ['status', '--porcelain']));
        self::assertSame('coder/job-'.$id, $this->git($result->worktree, ['branch', '--show-current']));
        self::assertSame(self::AUTHOR_NAME, $this->git($result->worktree, ['log', '-1', '--format=%an']));
        self::assertSame(self::AUTHOR_EMAIL, $this->git($result->worktree, ['log', '-1', '--format=%ae']));
        self::assertSame($this->baseHead, $this->git($result->worktree, ['rev-parse', 'HEAD^']));

        $job = $this->store->job($id);
        self::assertNotNull($job);
        self::assertSame(Store::DONE, $job['state']);
        self::assertSame($result->head, $job['current_head']);
        self::assertCount(1, $job['diagnostics']['checks']['targeted']);
        self::assertSame([
            ['composer', 'cs:check'], ['composer', 'stan'], ['composer', 'test'],
        ], array_column($job['diagnostics']['checks']['full'], 'argv'));
        self::assertSame(30, $job['diagnostics']['timeouts']['overall_run_seconds']);
        self::assertSame(600000, $job['diagnostics']['timeouts']['bash_default_ms']);
        self::assertSame(1200000, $job['diagnostics']['timeouts']['bash_max_ms']);

        $environment = json_decode((string) file_get_contents($this->logDir.'/environment.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'GITLAB_TOKEN', 'GL_TOKEN', 'SSH_AUTH_SOCK', 'SSH_AGENT_PID'] as $name) {
            self::assertFalse($environment[$name], $name.' must not cross into the engine.');
        }
        self::assertSame('/bin/false', $environment['GIT_ASKPASS']);
        self::assertSame('/bin/false', $environment['SSH_ASKPASS']);
        self::assertSame('0', $environment['GIT_TERMINAL_PROMPT']);
        self::assertStringContainsString('BatchMode=yes', $environment['GIT_SSH_COMMAND']);
        $sessions = file($this->logDir.'/sessions.log', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $sessions);
        $session = json_decode($sessions[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('Implement the fixture', $session['prompt']);
        self::assertStringContainsString('Targeted implementation checks', $session['prompt']);
        self::assertNull($session['session_id']);
    }

    public function testEngineCannotUseInheritedForgeCliConfiguration(): void
    {
        $credential = 'dummy-fixture-credential';
        $ghConfig = $this->root.'/inherited-gh';
        $glabConfig = $this->root.'/inherited-glab';
        mkdir($ghConfig, 0700, true);
        mkdir($glabConfig, 0700, true);
        file_put_contents($ghConfig.'/hosts.yml', "github.com:\n  oauth_token: {$credential}\n  user: fixture\n");
        file_put_contents($glabConfig.'/config.yml', "hosts:\n  gitlab.com:\n    token: {$credential}\n");
        $this->installFakeForgeCli();
        putenv('GH_CONFIG_DIR='.$ghConfig);
        putenv('GLAB_CONFIG_DIR='.$glabConfig);
        putenv('XDG_CONFIG_HOME='.$this->root.'/inherited-xdg');
        /** @var array<string, string> $inherited */
        $inherited = getenv();
        foreach (['gh', 'glab'] as $cli) {
            $positiveControl = Process::run([$cli, 'auth', 'token'], $this->repository, 5, $inherited);
            self::assertSame(0, $positiveControl['exit'], $positiveControl['stderr']);
            self::assertSame($credential, trim($positiveControl['stdout']), 'The fixture must expose its dummy credential before sanitization.');
        }
        $this->enqueue();

        $result = $this->worker(mode: 'forge_config')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        $environment = json_decode((string) file_get_contents($this->logDir.'/environment.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ghConfig, $environment['GH_CONFIG_DIR']);
        self::assertNotSame($glabConfig, $environment['GLAB_CONFIG_DIR']);
        self::assertNotSame($this->root.'/inherited-xdg', $environment['XDG_CONFIG_HOME']);
        self::assertNotSame($environment['GH_CONFIG_DIR'], $environment['GLAB_CONFIG_DIR']);
        $probes = json_decode((string) file_get_contents($this->logDir.'/forge-cli.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['gh', 'glab'] as $cli) {
            self::assertSame(1, $probes[$cli]['exit'], 'The engine must not read the inherited dummy credential.');
            self::assertSame('', $probes[$cli]['stdout']);
        }
    }

    public function testTargetedChecksRunInsideEngineBeforeOneFinalFullSet(): void
    {
        putenv('CODER_TEST_CHECK_LOG='.$this->logDir.'/checks.log');
        $this->writeComposerScripts(named: true);
        $this->git($this->repository, ['add', 'composer.json']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Record fixture check order']);
        $this->enqueue();

        $result = $this->worker()->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        self::assertSame(
            ['test', 'engine_done', 'cs:check', 'stan', 'test'],
            file($this->logDir.'/checks.log', FILE_IGNORE_NEW_LINES),
        );
        self::assertCount(1, $result->details['checks']['targeted']);
        self::assertCount(3, $result->details['checks']['full']);
    }

    public function testTargetedCheckBrokerStopsWithAnInheritedSigtermHandler(): void
    {
        $previousHandler = pcntl_signal_get_handler(SIGTERM);
        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
        });
        $this->enqueue();

        try {
            $result = $this->worker()->runNext();
        } finally {
            pcntl_signal(SIGTERM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
    }

    public function testFreshWorktreeInstallsLockedCheckTools(): void
    {
        $package = $this->repository.'/tools/test-runner';
        mkdir($package.'/bin', 0700, true);
        file_put_contents($package.'/composer.json', json_encode([
            'name' => 'fixture/test-runner', 'version' => '1.0.0', 'bin' => ['bin/test-runner'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($package.'/bin/test-runner', <<<'PHP'
            #!/usr/bin/env php
            <?php
            $log = getenv('CODER_TEST_TOOL_LOG');
            if (is_string($log) && $log !== '') {
                file_put_contents($log, "test-runner\n", FILE_APPEND);
            }
            PHP);
        chmod($package.'/bin/test-runner', 0700);
        $composer = json_decode((string) file_get_contents($this->repository.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['repositories'] = [['type' => 'path', 'url' => 'tools/test-runner', 'options' => ['symlink' => false]]];
        $composer['require-dev']['fixture/test-runner'] = '1.0.0';
        $composer['scripts']['test'] = 'test-runner';
        file_put_contents($this->repository.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->composer($this->repository, ['update', '--no-interaction', '--no-plugins', '--no-scripts', '--no-install']);
        $this->git($this->repository, ['add', '-A']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Add locked fixture test runner']);
        putenv('CODER_TEST_TOOL_LOG='.$this->logDir.'/tool.log');
        $id = $this->enqueue();
        $worktree = $this->worktreeDir.'/job-'.$id;
        self::assertDirectoryDoesNotExist($worktree.'/vendor');

        $result = $this->worker()->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        self::assertFileExists($worktree.'/vendor/bin/test-runner');
        self::assertSame(['test-runner', 'test-runner'], file($this->logDir.'/tool.log', FILE_IGNORE_NEW_LINES));
    }

    public function testEngineCannotSkipTargetedImplementationChecks(): void
    {
        $id = $this->enqueue();

        $result = $this->worker(mode: 'skip_checks')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertStringContainsString('targeted implementation check', (string) ($result->details['error'] ?? ''));
        self::assertSame($this->baseHead, $result->head);
        self::assertArrayNotHasKey('local_commit', $result->details);
        self::assertSame(Store::FAILED, $this->store->job($id)['state'] ?? null);
    }

    public function testEngineCannotForgeTargetedImplementationCheckReport(): void
    {
        putenv('CODER_TEST_CHECK_LOG='.$this->logDir.'/checks.log');
        $this->writeComposerScripts(named: true);
        $this->git($this->repository, ['add', 'composer.json']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Record forged check execution']);
        $id = $this->enqueue();

        $result = $this->worker(mode: 'forge_checks')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertStringContainsString('worker attestation', (string) ($result->details['error'] ?? ''));
        self::assertNull($result->publicationRequest());
        self::assertArrayNotHasKey('local_commit', $result->details);
        self::assertSame(Store::FAILED, $this->store->job($id)['state'] ?? null);
        self::assertFileDoesNotExist($this->logDir.'/checks.log', 'Neither targeted nor final checks may run for the forged report.');
    }

    public function testExistingSessionIsPassedToResumedEngine(): void
    {
        $id = $this->store->enqueue(
            'example/repo',
            'fixture',
            sessionId: 'session-before-retry',
            diagnostics: ['prompt' => 'Resume the fixture'],
        );

        $result = $this->worker()->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status);
        self::assertSame('coder-session-1', $result->sessionId);
        $sessions = file($this->logDir.'/sessions.log', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $sessions);
        $session = json_decode($sessions[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('Resume the fixture', $session['prompt']);
        self::assertSame('session-before-retry', $session['session_id']);
        self::assertSame($id, $result->jobId);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function engineGitViolations(): iterable
    {
        foreach (['first run' => false, 'resumed run' => true] as $label => $resumed) {
            yield $label.' foreign branch' => ['foreign_branch', $resumed, 'deterministic branch'];
            yield $label.' foreign author' => ['foreign_author', $resumed, 'unexpected author'];
        }
    }

    #[DataProvider('engineGitViolations')]
    public function testEngineBranchAndAuthorsAreReconciledBeforePublication(string $mode, bool $resumed, string $error): void
    {
        $diagnostics = ['prompt' => 'Implement the fixture'];
        if ($resumed) {
            $diagnostics['base_head'] = $this->baseHead;
        }
        $id = $this->store->enqueue('example/repo', 'fixture', diagnostics: $diagnostics);
        if ($resumed) {
            (new Worktree($this->repository, $this->worktreeDir, self::AUTHOR_NAME, self::AUTHOR_EMAIL))->prepare($id, $this->baseHead);
        }

        $result = $this->worker(mode: $mode)->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertNull($result->publicationRequest());
        self::assertStringContainsString($error, (string) ($result->details['error'] ?? ''));
        self::assertArrayNotHasKey('local_commit', $result->details);
        self::assertSame(Store::FAILED, $this->store->job($id)['state'] ?? null);
        $engineHead = $this->git($result->worktree, ['rev-parse', 'HEAD']);
        self::assertSame($this->baseHead, $this->git($result->worktree, ['rev-parse', $engineHead.'^']), 'Rejection must not rewrite engine history.');
    }

    public function testChecksAndGitWorkHeartbeatBeyondTheLeaseDuration(): void
    {
        $this->writeComposerScripts('@php -r "usleep(1100000);"');
        $this->git($this->repository, ['add', 'composer.json']);
        $this->git($this->repository, ['commit', '-q', '-m', 'Slow fixture checks']);
        $id = $this->enqueue();
        $started = microtime(true);

        $result = $this->worker(leaseSeconds: 2)->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        self::assertGreaterThan(4.0, microtime(true) - $started);
        self::assertSame(Store::DONE, $this->store->job($id)['state'] ?? null);
    }

    public function testLostLeaseIsNotCancellationOrAPublicationRequest(): void
    {
        $id = $this->enqueue();

        $result = $this->worker(mode: 'expire')->runNext();

        self::assertNotNull($result);
        $expiry = json_decode((string) file_get_contents($this->logDir.'/expiry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$id], $expiry['expired'], (string) json_encode($expiry, JSON_THROW_ON_ERROR));
        self::assertSame(LocalResult::LEASE_LOST, $result->status, (string) json_encode($expiry, JSON_THROW_ON_ERROR));
        self::assertNotSame(LocalResult::CANCELLED, $result->status);
        self::assertNull($result->publicationRequest());
        self::assertSame(
            Store::RECONCILING,
            $this->store->job($id)['state'] ?? null,
            (string) json_encode(['result' => $result->details, 'job' => $this->store->job($id)], JSON_THROW_ON_ERROR),
        );
    }

    public function testDefaultEngineAttachesSecretGuardPreToolUseHook(): void
    {
        $worker = new Worker($this->config(), $this->store);
        $runner = (new \ReflectionMethod(Worker::class, 'claudeRunner'))->invoke($worker, $this->repository);
        self::assertInstanceOf(ClaudeRunner::class, $runner);
        $json = (new \ReflectionProperty(ClaudeRunner::class, 'settingsJson'))->getValue($runner);
        self::assertIsString($json);
        $settings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Bash', $settings['hooks']['PreToolUse'][0]['matcher']);
        self::assertSame([[
            'type' => 'command',
            'command' => PHP_BINARY.' '.escapeshellarg(dirname(__DIR__, 2).'/bin/secret-guard-hook.php'),
        ]], $settings['hooks']['PreToolUse'][0]['hooks']);
    }

    public function testSecretGuardBlocksTheLocalCommit(): void
    {
        $id = $this->enqueue();

        $result = $this->worker(mode: 'secret')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertNull($result->publicationRequest());
        self::assertSame($this->baseHead, $result->head);
        self::assertSame(Store::FAILED, $this->store->job($id)['state'] ?? null);
        self::assertSame('', $this->git($result->worktree, ['diff', '--cached', '--name-only']));
    }

    public function testEngineCannotReplaceItsOwnComposerGates(): void
    {
        $id = $this->enqueue();

        $result = $this->worker(mode: 'tamper')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertStringContainsString('cannot change the Composer check definitions', (string) ($result->details['error'] ?? ''));
        self::assertSame($this->baseHead, $result->head);
        self::assertArrayNotHasKey('checks', $this->store->job($id)['diagnostics'] ?? []);
    }

    public function testEngineCanChangeUnrelatedComposerDependencies(): void
    {
        $id = $this->enqueue();

        $result = $this->worker(mode: 'dependency')->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status, (string) ($result->details['error'] ?? ''));
        self::assertSame(Store::DONE, $this->store->job($id)['state'] ?? null);
        $composer = json_decode((string) file_get_contents($result->worktree.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^1.0', $composer['require']['example/package']);
    }

    public function testTargetedChecksCannotInjectAnArbitraryWorkerCommand(): void
    {
        $id = $this->store->enqueue('example/repo', 'fixture', diagnostics: [
            'prompt' => 'Implement the fixture',
            'targeted_checks' => [[PHP_BINARY, '-r', 'file_put_contents("escaped", "yes");']],
        ]);

        $result = $this->worker()->runNext();

        self::assertNotNull($result);
        self::assertSame(LocalResult::FAILED, $result->status);
        self::assertStringContainsString('targeted check', (string) ($result->details['error'] ?? ''));
        self::assertFileDoesNotExist($this->worktreeDir.'/job-'.$id.'/escaped');
    }

    public function testQueuedCancellationIsDurableAndCannotPublish(): void
    {
        $id = $this->enqueue();

        $result = $this->worker()->cancel($id, 'pause');

        self::assertSame(LocalResult::CANCELLED, $result->status);
        self::assertNull($result->publicationRequest());
        $job = $this->store->job($id);
        self::assertNotNull($job);
        self::assertSame(Store::CANCELLED, $job['state']);
        self::assertSame('pause', $job['diagnostics']['cancellation']['reason']);
        self::assertSame($job['cancellation_requested_at'], $job['diagnostics']['cancellation']['requested_at']);
        self::assertNull($this->worker()->runNext());
    }

    public function testCancellationCleanupFailureDoesNotHideDurableCancellation(): void
    {
        $pgid = posix_getpgid((int) getmypid());
        self::assertIsInt($pgid);
        $id = $this->store->enqueue('example/repo', 'fixture', diagnostics: [
            'prompt' => 'Implement the fixture', 'run_id' => 'stale-run', 'pgid' => $pgid,
        ]);

        $result = $this->worker()->cancel($id);

        self::assertSame(LocalResult::CANCELLED, $result->status);
        $job = $this->store->job($id);
        self::assertNotNull($job);
        self::assertSame(Store::CANCELLED, $job['state']);
        self::assertArrayHasKey('reconciliation_error', $job['diagnostics']['cancellation']);
    }

    public function testActiveCancellationTerminatesEngineAndDescendantProcessGroup(): void
    {
        $id = $this->enqueue();
        $resultFile = $this->root.'/active-result.json';
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $this->store->disconnect();
            try {
                $result = $this->worker(new Store($this->stateDir), 'block')->runNext();
                file_put_contents($resultFile, json_encode(['status' => $result?->status], JSON_THROW_ON_ERROR));
                $code = 0;
            } catch (\Throwable $e) {
                file_put_contents($resultFile, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                $code = 1;
            }
            pcntl_exec(PHP_BINARY, ['-r', 'exit('.$code.');']);
            exit(1);
        }

        $job = $this->waitForRunningEngine($id);
        $pgid = $job['diagnostics']['pgid'];
        self::assertIsInt($pgid);
        $this->processGroups[] = $pgid;
        self::assertTrue($this->groupAlive($pgid));

        $cancelled = $this->worker()->cancel($id);
        $this->waitForProcess($pid);
        $data = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(LocalResult::CANCELLED, $cancelled->status);
        self::assertNull($cancelled->publicationRequest());
        self::assertSame(['status' => LocalResult::CANCELLED], $data);
        self::assertFalse($this->groupAlive($pgid), 'The complete engine PGID, including its descendant, must be gone.');
        self::assertSame(Store::CANCELLED, $this->store->job($id)['state'] ?? null);
        $this->processGroups = [];
    }

    public function testBusyAssignmentLockReleasesLeaseInsteadOfFailingHealthyWork(): void
    {
        $id = $this->enqueue();
        $lock = $this->holdLock($id);

        self::assertNull($this->worker()->runNext());
        $job = $this->store->job($id);
        self::assertNotNull($job);
        self::assertSame(Store::QUEUED, $job['state']);
        self::assertNull($job['worker_id']);

        flock($lock, LOCK_UN);
        fclose($lock);
        $result = $this->worker()->runNext();
        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status);
    }

    public function testReconcilingJobIsRetriedAfterAnotherWorkerReleasesItsLock(): void
    {
        $id = $this->enqueue();
        $this->store->registerWorker(7, 100);
        $lease = $this->store->assign(7, 1, 100);
        self::assertNotNull($lease);
        self::assertSame([$id], $this->store->reconcileExpiredLeases(101));
        $lock = $this->holdLock($id);
        $worker = $this->worker();

        self::assertNull($worker->runNext());
        self::assertSame(Store::RECONCILING, $this->store->job($id)['state'] ?? null);
        self::assertSame([$id], $this->store->reconcileExpiredLeases(102), 'Reconciling work must remain discoverable.');

        flock($lock, LOCK_UN);
        fclose($lock);
        $result = $this->worker()->runNext();
        self::assertNotNull($result);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status);
    }

    public function testFailedReconciliationIsRecordedAndDoesNotBlockOtherQueuedWork(): void
    {
        $broken = $this->enqueue();
        $healthy = $this->enqueue();
        $this->store->registerWorker(7, 100);
        $lease = $this->store->assign(7, 10, 100);
        self::assertNotNull($lease);
        self::assertTrue($this->store->updateContext($broken, 7, $lease['lease_token'], diagnostics: [
            'prompt' => 'Broken recovery', 'base_head' => 'not-a-commit', 'branch' => 'coder/job-'.$broken,
        ], now: 100));
        $this->store->reconcileExpiredLeases(110);

        $result = $this->worker()->runNext();

        self::assertNotNull($result);
        self::assertSame($healthy, $result->jobId);
        self::assertSame(LocalResult::PUBLICATION_REQUESTED, $result->status);
        $job = $this->store->job($broken);
        self::assertNotNull($job);
        self::assertSame(Store::FAILED, $job['state']);
        self::assertArrayHasKey('reconciliation_error', $job['diagnostics']);
        self::assertSame(Store::DONE, $this->store->job($healthy)['state'] ?? null);
    }

    private function enqueue(): int
    {
        return $this->store->enqueue('example/repo', 'fixture', diagnostics: ['prompt' => 'Implement the fixture']);
    }

    private function worker(?Store $store = null, string $mode = 'write', int $leaseSeconds = 300): Worker
    {
        return new Worker(
            $this->config($leaseSeconds),
            $store ?? $this->store,
            fn (string $worktree): FakeCoderRunner => new FakeCoderRunner($worktree, $this->stateDir, $this->logDir, $mode),
        );
    }

    private function config(int $leaseSeconds = 300): Config
    {
        return new Config(
            repository: $this->repository,
            stateDir: $this->stateDir,
            worktreeDir: $this->worktreeDir,
            authorName: self::AUTHOR_NAME,
            authorEmail: self::AUTHOR_EMAIL,
            runTimeout: 30,
            leaseSeconds: $leaseSeconds,
            checkTimeout: 30,
        );
    }

    /** @param list<string> $arguments */
    private function git(string $cwd, array $arguments): string
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $result = Process::run(['git', ...$arguments], $cwd, 30, $environment);
        self::assertSame(0, $result['exit'], $result['stderr'].$result['stdout']);

        return trim($result['stdout']);
    }

    /** @param list<string> $arguments */
    private function composer(string $cwd, array $arguments): void
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $result = Process::run(['composer', ...$arguments], $cwd, 60, $environment);
        self::assertSame(0, $result['exit'], $result['stderr'].$result['stdout']);
    }

    private function installFakeForgeCli(): void
    {
        $directory = $this->root.'/bin';
        mkdir($directory, 0700, true);
        $script = <<<'PHP'
            #!/usr/bin/env php
            <?php
            $cli = basename($argv[0]);
            $directory = getenv($cli === 'gh' ? 'GH_CONFIG_DIR' : 'GLAB_CONFIG_DIR');
            $file = $cli === 'gh' ? 'hosts.yml' : 'config.yml';
            if (!is_string($directory) || !is_file($directory.'/'.$file)) {
                exit(1);
            }
            $contents = file_get_contents($directory.'/'.$file);
            $pattern = $cli === 'gh' ? '/oauth_token:\s*(\S+)/' : '/token:\s*(\S+)/';
            if (!is_string($contents) || preg_match($pattern, $contents, $match) !== 1) {
                exit(1);
            }
            echo $match[1], "\n";
            PHP;
        foreach (['gh', 'glab'] as $cli) {
            file_put_contents($directory.'/'.$cli, $script."\n");
            chmod($directory.'/'.$cli, 0700);
        }
        putenv('PATH='.$directory.':'.getenv('PATH'));
    }

    private function writeComposerScripts(string $script = '@php -r "exit(0);"', bool $named = false): void
    {
        $scripts = [];
        foreach (['cs:check', 'stan', 'test'] as $name) {
            $scripts[$name] = $named ? '@php check.php '.$name : $script;
        }
        file_put_contents($this->repository.'/composer.json', json_encode([
            'scripts' => $scripts,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return resource */
    private function holdLock(int $jobId)
    {
        if (!is_dir($this->stateDir.'/coder-locks')) {
            mkdir($this->stateDir.'/coder-locks', 0700, true);
        }
        $lock = fopen($this->stateDir.'/coder-locks/job-'.$jobId.'.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        return $lock;
    }

    /** @return array<string, mixed> */
    private function waitForRunningEngine(int $jobId): array
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $job = $this->store->job($jobId);
            if ($job !== null && is_int($job['diagnostics']['engine_pid'] ?? null)
                && is_string($job['diagnostics']['supervisor_start_time'] ?? null)) {
                return $job;
            }
            usleep(50_000);
        }
        self::fail('The active fake engine did not publish its process identity.');
    }

    private function waitForProcess(int $pid): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                self::assertSame(0, pcntl_wexitstatus($status));

                return;
            }
            usleep(50_000);
        }
        posix_kill($pid, SIGKILL);
        self::fail('The actively running worker did not stop after cancellation.');
    }

    private function groupAlive(int $pgid): bool
    {
        foreach (glob('/proc/[0-9]*') ?: [] as $path) {
            $pid = (int) basename($path);
            $stat = @file_get_contents($path.'/stat');
            if (!is_string($stat) || posix_getpgid($pid) !== $pgid) {
                continue;
            }
            $end = strrpos($stat, ')');
            $fields = $end === false ? [] : (preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: []);
            if (($fields[0] ?? '') !== 'Z') {
                return true;
            }
        }

        return false;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
