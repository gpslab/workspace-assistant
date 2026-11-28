<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

use GpsLab\WorkspaceAssistant\Coder\ExecutionEnvironment;
use GpsLab\WorkspaceAssistant\Coder\Process;

/** argv-only gh/git transport. Identity is supplied to each child, never through putenv(). */
final readonly class GhCliTransport implements GithubTransport
{
    public function __construct(private string $workingDirectory = '.')
    {
    }

    public function call(string $action, string $repository, array $parameters, array $environment, int $timeoutSeconds): array
    {
        $repo = self::repository($repository, $action);
        $branch = (string) ($parameters['branch'] ?? '');
        $number = (int) ($parameters['number'] ?? 0);
        $path = implode('/', array_map('rawurlencode', explode('/', $branch)));
        $cwd = $this->workingDirectory;
        $argv = match ($action) {
            'self' => ['gh', 'api', 'user'],
            'probe' => ['gh', 'api', "repos/{$repo}"],
            'issue' => ['gh', 'api', "repos/{$repo}/issues/{$number}"],
            'branch' => ['gh', 'api', "repos/{$repo}/git/ref/heads/{$path}"],
            'find_pr' => ['gh', 'api', "repos/{$repo}/pulls?state=all&head=".rawurlencode(explode('/', $repo)[0].':'.$branch).'&per_page=100'],
            'pr' => ['gh', 'api', "repos/{$repo}/pulls/{$number}"],
            'comments' => ['gh', 'api', "repos/{$repo}/issues/{$number}/comments?per_page=100"],
            'delete_branch' => ['gh', 'api', '-X', 'DELETE', "repos/{$repo}/git/refs/heads/{$path}"],
            'create_pr' => ['gh', 'api', '-X', 'POST', "repos/{$repo}/pulls", '-f', 'title='.(string) $parameters['title'], '-f', 'body='.(string) $parameters['body'], '-f', 'head='.$branch, '-f', 'base='.(string) $parameters['base'], '-F', 'draft=true'],
            'update_pr' => ['gh', 'api', '-X', 'PATCH', "repos/{$repo}/pulls/{$number}", '-f', 'title='.(string) $parameters['title'], '-f', 'body='.(string) $parameters['body']],
            'ready' => ['gh', 'pr', 'ready', (string) $number, '--repo', $repo],
            'add_label' => ['gh', 'api', '-X', 'POST', "repos/{$repo}/issues/{$number}/labels", '-f', 'labels[]='.(string) $parameters['label']],
            'remove_label' => ['gh', 'api', '-X', 'DELETE', "repos/{$repo}/issues/{$number}/labels/".rawurlencode((string) $parameters['label'])],
            'comment' => ['gh', 'api', '-X', 'POST', "repos/{$repo}/issues/{$number}/comments", '-f', 'body='.(string) $parameters['body']],
            'push' => ['git', 'push', 'origin', (string) $parameters['head'].':refs/heads/'.$branch],
            default => throw new \InvalidArgumentException("Unknown GitHub transport action '{$action}'."),
        };
        if ($action === 'push') {
            $cwd = (string) $parameters['worktree'];
            $head = $this->run(['git', 'rev-parse', 'HEAD'], $cwd, $environment, $timeoutSeconds);
            if (trim($head['stdout']) !== (string) $parameters['head']) {
                throw new \RuntimeException('The worktree head changed before coder push.');
            }
        }
        $result = $this->run($argv, $cwd, $environment, $timeoutSeconds);
        if ($result['exit'] !== 0) {
            if (in_array($action, ['branch', 'pr'], true) && str_contains($result['stderr'].$result['stdout'], 'HTTP 404')) {
                return [];
            }
            throw new \RuntimeException('GitHub '.$action.' failed: '.trim($result['stderr']."\n".$result['stdout']));
        }
        if (in_array($action, ['push', 'ready', 'delete_branch', 'add_label', 'remove_label'], true)) {
            return [];
        }
        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub '.$action.' returned an invalid JSON response.');
        }

        return $decoded;
    }

    /** @param list<string> $argv
     * @param array<string, string> $environment
     *
     * @return array{exit:int,stdout:string,stderr:string,timed_out:bool}
     */
    private function run(array $argv, string $cwd, array $environment, int $timeoutSeconds): array
    {
        /** @var array<string, string> $inherited */
        $inherited = getenv();

        return Process::run($argv, $cwd, $timeoutSeconds, [...ExecutionEnvironment::sanitize($inherited), ...$environment]);
    }

    private static function repository(string $repository, string $action): string
    {
        if ($action === 'self') {
            return '';
        }
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$~', $repository) !== 1) {
            throw new \InvalidArgumentException("Invalid GitHub repository '{$repository}'.");
        }

        return $repository;
    }
}
