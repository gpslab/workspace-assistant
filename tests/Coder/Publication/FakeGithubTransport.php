<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder\Publication;

use GpsLab\WorkspaceAssistant\Coder\Github\GithubTransport;

/** Stateful fake GitHub API with explicit actor attribution and crash injection. */
final class FakeGithubTransport implements GithubTransport
{
    /** @var array<string, string> token => login */
    public array $actors = [];
    /** @var array<string, array<string, mixed>> */
    public array $issues = [];
    /** @var array<string, string> repository:branch => SHA */
    public array $branches = [];
    /** @var array<string, array<string, mixed>> */
    public array $pullRequests = [];
    /** @var array<string, list<array<string, mixed>>> */
    public array $comments = [];
    /** @var list<array{actor:string,action:string,repository:string,parameters:array<string, mixed>,push:bool}> */
    public array $calls = [];
    /** @var array<string, true> */
    public array $failedProbes = [];
    public ?string $crashBefore = null;
    public ?string $crashAfter = null;
    public bool $staleCreatedTitle = false;
    private int $nextPr = 70;

    public function call(string $action, string $repository, array $parameters, array $environment, int $timeoutSeconds): array
    {
        $token = $environment['GH_TOKEN'] ?? '';
        $actor = $this->actors[$token] ?? 'unknown';
        $push = $action === 'push';
        if ($push) {
            $expected = 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token);
            if (($environment['GIT_CONFIG_VALUE_0'] ?? '') !== $expected) {
                throw new \RuntimeException('The fake push did not use the coder push credential.');
            }
        }
        $this->calls[] = ['actor' => $actor, 'action' => $action, 'repository' => $repository, 'parameters' => $parameters, 'push' => $push];
        if ($this->crashBefore === $action) {
            $this->crashBefore = null;
            throw new \RuntimeException('Injected crash before '.$action);
        }
        $result = $this->apply($action, $repository, $parameters, $actor);
        if ($this->crashAfter === $action) {
            $this->crashAfter = null;
            throw new \RuntimeException('Injected crash after '.$action);
        }

        return $result;
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function apply(string $action, string $repository, array $parameters, string $actor): array
    {
        $number = (int) ($parameters['number'] ?? 0);
        $branch = (string) ($parameters['branch'] ?? '');
        $issueKey = $repository.'#'.$number;
        switch ($action) {
            case 'self':
                return ['login' => $actor];
            case 'probe':
                if (isset($this->failedProbes[$repository])) {
                    throw new \RuntimeException('Read-only repository probe failed.');
                }

                return ['full_name' => $repository];
            case 'issue':
                return $this->issues[$issueKey] ?? throw new \RuntimeException('Issue not found.');
            case 'branch':
                $sha = $this->branches[$repository.':'.$branch] ?? null;

                return $sha === null ? [] : ['object' => ['sha' => $sha]];
            case 'find_pr':
                return array_values(array_filter($this->pullRequests, static fn (array $pr): bool => ($pr['_repository'] ?? null) === $repository && ($pr['head']['ref'] ?? null) === $branch));
            case 'pr':
                return $this->pullRequests[$issueKey] ?? [];
            case 'comments':
                return $this->comments[$issueKey] ?? [];
            case 'push':
                $this->branches[$repository.':'.$branch] = (string) $parameters['head'];

                return [];
            case 'delete_branch':
                unset($this->branches[$repository.':'.$branch]);

                return [];
            case 'create_pr':
                $number = $this->nextPr++;
                $pr = $this->newPr($repository, $number, $branch, (string) $parameters['title'], (string) $parameters['body']);
                $pr['head']['sha'] = $this->branches[$repository.':'.$branch] ?? '';
                if ($this->staleCreatedTitle) {
                    $pr['title'] = 'stale';
                }
                $this->pullRequests[$repository.'#'.$number] = $pr;

                return $pr;
            case 'update_pr':
                $pr = &$this->pullRequests[$issueKey];
                $pr['title'] = (string) $parameters['title'];
                $pr['body'] = (string) $parameters['body'];

                return $pr;
            case 'ready':
                $this->pullRequests[$issueKey]['isDraft'] = false;

                return [];
            case 'add_label':
            case 'remove_label':
                $labels = array_values(array_filter($this->issues[$issueKey]['labels'] ?? [], static fn (array $label): bool => $label['name'] !== $parameters['label']));
                if ($action === 'add_label') {
                    $labels[] = ['name' => (string) $parameters['label']];
                }
                $this->issues[$issueKey]['labels'] = $labels;

                return [];
            case 'comment':
                $comment = ['id' => count($this->comments[$issueKey] ?? []) + 1, 'body' => (string) $parameters['body'], 'user' => ['login' => $actor]];
                $this->comments[$issueKey][] = $comment;

                return $comment;
            default:
                throw new \InvalidArgumentException('Unknown fake action '.$action);
        }
    }

    /** @return array<string, mixed> */
    public function newPr(string $repository, int $number, string $branch, string $title, string $body): array
    {
        return [
            '_repository' => $repository, 'number' => $number, 'state' => 'open', 'merged' => false,
            'isDraft' => true, 'title' => $title, 'body' => $body, 'head' => ['ref' => $branch],
        ];
    }

    /** @return list<array{actor:string,action:string,repository:string,parameters:array<string, mixed>,push:bool}> */
    public function writes(?string $action = null): array
    {
        $reads = ['self', 'probe', 'issue', 'branch', 'find_pr', 'pr', 'comments'];

        return array_values(array_filter($this->calls, static fn (array $call): bool => !in_array($call['action'], $reads, true) && ($action === null || $call['action'] === $action)));
    }
}
