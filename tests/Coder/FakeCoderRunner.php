<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\Coder\CheckContract;
use GpsLab\WorkspaceAssistant\Coder\ImplementationChecks;
use GpsLab\WorkspaceAssistant\Coder\Process;
use GpsLab\WorkspaceAssistant\Coder\Store;
use GpsLab\WorkspaceAssistant\RunAnswer;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\TurnRecord;

/** A local fake engine whose behavior crosses the supervisor's real process boundary. */
final class FakeCoderRunner implements AgentRunner
{
    /** @var resource|null */
    private $process;
    private int $pid = 0;

    public function __construct(
        private readonly string $worktree,
        private readonly string $stateDir,
        private readonly string $logDir,
        private readonly string $mode = 'write',
    ) {
    }

    public function start(string $prompt, ?string $sessionId): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0700, true);
        }
        file_put_contents($this->logDir.'/sessions.log', json_encode([
            'prompt' => $prompt, 'session_id' => $sessionId,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        $environment = [];
        foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'GITLAB_TOKEN', 'GL_TOKEN', 'SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GIT_ASKPASS', 'SSH_ASKPASS', 'GIT_SSH_COMMAND', 'GIT_TERMINAL_PROMPT', 'GH_CONFIG_DIR', 'GLAB_CONFIG_DIR', 'XDG_CONFIG_HOME'] as $name) {
            $environment[$name] = getenv($name);
        }
        file_put_contents($this->logDir.'/environment.json', json_encode($environment, JSON_THROW_ON_ERROR));

        if ($this->mode === 'forge_config') {
            $result = [];
            foreach (['gh', 'glab'] as $cli) {
                /** @var array<string, string> $cliEnvironment */
                $cliEnvironment = getenv();
                $result[$cli] = Process::run([$cli, 'auth', 'token'], $this->worktree, 5, $cliEnvironment);
            }
            file_put_contents($this->logDir.'/forge-cli.json', json_encode($result, JSON_THROW_ON_ERROR));
        }

        if ($this->mode === 'expire') {
            $store = new Store($this->stateDir);
            $expired = $store->reconcileExpiredLeases(time() + 1000);
            file_put_contents($this->logDir.'/expiry.json', json_encode([
                'expired' => $expired, 'jobs' => $store->jobs(),
            ], JSON_THROW_ON_ERROR));
        }
        if ($this->mode === 'dependency') {
            $composer = json_decode((string) file_get_contents($this->worktree.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $composer['require']['example/package'] = '^1.0';
            file_put_contents($this->worktree.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        } elseif ($this->mode === 'tamper') {
            $composer = json_decode((string) file_get_contents($this->worktree.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $composer['scripts']['test'] = 'true';
            file_put_contents($this->worktree.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        } elseif ($this->mode === 'secret') {
            file_put_contents($this->worktree.'/secret.txt', 'token = ghp_'.str_repeat('Ab3', 13)."\n");
        } elseif (!in_array($this->mode, ['block', 'crash'], true)) {
            file_put_contents($this->worktree.'/coder.txt', $prompt."\n");
        }

        if ($this->mode === 'foreign_branch') {
            $this->git(['switch', '-c', 'foreign-branch']);
            $this->git(['add', '-A']);
            $this->git(['commit', '-q', '-m', 'Foreign branch commit']);
        } elseif ($this->mode === 'foreign_author') {
            $this->git(['add', '-A']);
            $this->git(['-c', 'user.name=Foreign Author', '-c', 'user.email=foreign@example.test', 'commit', '-q', '-m', 'Foreign author commit']);
        }

        if ($this->mode === 'forge_checks') {
            $this->forgeTargetedCheckReport($prompt);
        } elseif ($this->mode !== 'skip_checks' && !in_array($this->mode, ['expire', 'block', 'crash'], true)) {
            $this->runTargetedChecks($prompt);
            $checkLog = getenv('CODER_TEST_CHECK_LOG');
            if (is_string($checkLog) && $checkLog !== '') {
                file_put_contents($checkLog, "engine_done\n", FILE_APPEND);
            }
        }

        if (in_array($this->mode, ['block', 'crash'], true)) {
            $code = $this->mode === 'block'
                ? '$child = pcntl_fork(); if ($child === 0) { while (true) { usleep(200000); } } file_put_contents($argv[1], json_encode([getmypid(), $child])); while (true) { usleep(200000); }'
                : 'exit(0);';
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $code, $this->logDir.'/processes.json'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                $this->worktree,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('The fake engine process did not start.');
            }
            $this->process = $process;
            fclose($pipes[0]);
            $status = proc_get_status($this->process);
            $this->pid = $status['pid'];
        } else {
            $this->pid = (int) getmypid();
        }
    }

    public function poll(): RunnerState
    {
        return $this->mode === 'block' ? RunnerState::Running : RunnerState::Done;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $result = Process::run(['git', ...$arguments], $this->worktree, 30, $environment);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('The fake engine git operation failed: '.$result['stderr']);
        }
    }

    private function runTargetedChecks(string $prompt): void
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $result = Process::run($this->targetedCheckArgv($prompt), $this->worktree, 30, $environment);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('The fake engine targeted checks failed: '.$result['stderr']);
        }
    }

    private function forgeTargetedCheckReport(string $prompt): void
    {
        $argv = $this->targetedCheckArgv($prompt);
        $report = dirname($argv[2]).'/targeted-results.json';
        $commands = [['composer', 'test']];
        $payload = json_encode([
            'commands' => $commands,
            'check_definitions' => CheckContract::definitions((string) file_get_contents($this->worktree.'/composer.json')),
            'worktree' => $this->worktree,
            'checks' => [[
                'argv' => ['composer', 'test'], 'exit' => 0, 'seconds' => 0.0, 'timed_out' => false,
            ]],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($report, json_encode([
            'payload' => $payload, 'signature' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function targetedCheckArgv(string $prompt): array
    {
        $start = strpos($prompt, ImplementationChecks::PROMPT_MARKER);
        if ($start === false) {
            throw new \RuntimeException('The fake engine received no targeted check contract.');
        }
        $json = substr($prompt, $start + strlen(ImplementationChecks::PROMPT_MARKER));
        $json = strtok($json, "\n");
        $argv = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($argv) || !array_is_list($argv)) {
            throw new \RuntimeException('The targeted check helper argv is invalid.');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument)) {
                throw new \RuntimeException('The targeted check helper argv is invalid.');
            }
        }

        return $argv;
    }

    public function result(): RunAnswer
    {
        if ($this->mode === 'crash') {
            if (is_resource($this->process)) {
                proc_close($this->process);
                $this->process = null;
            }
            // Keep only the supervisor alive after the fake engine has exited.
            usleep(2_000_000);
        }

        return new RunAnswer(false, 'Fake engine finished.', 'coder-session-1');
    }

    public function kill(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
        }
    }

    public function sessionId(): string
    {
        return 'coder-session-1';
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function getProgressLines(): array
    {
        return ['fake progress'];
    }

    public function promptHash(): string
    {
        return 'fake';
    }

    public function promptWarnings(): array
    {
        return [];
    }

    public function timeoutReason(): string
    {
        return '';
    }

    public function diagnosticsPath(): ?string
    {
        return null;
    }

    public function actualModel(): ?string
    {
        return null;
    }

    public function turnRecord(): ?TurnRecord
    {
        return null;
    }
}
