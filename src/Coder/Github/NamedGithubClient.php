<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder\Github;

/** One named actor with its own credential provider and selfLogin cache. */
final class NamedGithubClient implements GithubClient
{
    private ?string $selfLogin = null;

    public function __construct(
        private readonly GithubTransport $transport,
        private readonly CredentialProvider $credentials,
        private readonly int $timeoutSeconds = 30,
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('The GitHub request timeout must be positive.');
        }
    }

    public function selfLogin(): string
    {
        if ($this->selfLogin === null) {
            $response = $this->call('self', '');
            $login = $response['login'] ?? null;
            if (!is_string($login) || strcasecmp($login, $this->credentials->expectedLogin()) !== 0) {
                throw new \RuntimeException('The resolved GitHub actor does not match expected login '.$this->credentials->expectedLogin().'.');
            }
            $this->selfLogin = $login;
        }

        return $this->selfLogin;
    }

    public function probeRepository(string $repository): void
    {
        $response = $this->call('probe', $repository);
        if (!is_string($response['full_name'] ?? null)) {
            throw new \RuntimeException("The non-mutating repository probe failed for {$repository}.");
        }
    }

    public function issue(string $repository, int $number): array
    {
        $issue = self::object($this->call('issue', $repository, ['number' => $number]));
        $labels = [];
        foreach ($issue['labels'] ?? [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = $label['name'];
            }
        }
        $issue['_labels'] = $labels;

        return $issue;
    }

    public function branchHead(string $repository, string $branch): ?string
    {
        $response = $this->call('branch', $repository, ['branch' => $branch]);
        $sha = $response['object']['sha'] ?? $response['sha'] ?? null;

        return is_string($sha) && $sha !== '' ? strtolower($sha) : null;
    }

    public function findPullRequest(string $repository, string $branch): ?array
    {
        $response = $this->call('find_pr', $repository, ['branch' => $branch]);
        $open = null;
        foreach ($response as $pr) {
            if (!is_array($pr) || ($pr['head']['ref'] ?? null) !== $branch) {
                continue;
            }
            $open ??= $pr;
            if (($pr['state'] ?? null) === 'open') {
                return $pr;
            }
        }

        return $open;
    }

    public function pullRequest(string $repository, int $number): ?array
    {
        $response = self::object($this->call('pr', $repository, ['number' => $number]));

        return isset($response['number']) ? $response : null;
    }

    public function comments(string $repository, int $issueNumber): array
    {
        $response = $this->call('comments', $repository, ['number' => $issueNumber]);

        return array_values(array_filter($response, is_array(...)));
    }

    public function push(string $repository, string $branch, string $worktree, string $expectedHead): void
    {
        $this->call('push', $repository, ['branch' => $branch, 'worktree' => $worktree, 'head' => $expectedHead], true);
    }

    public function deleteBranch(string $repository, string $branch, string $expectedHead): void
    {
        $this->call('delete_branch', $repository, ['branch' => $branch, 'head' => $expectedHead]);
    }

    public function createDraftPullRequest(string $repository, string $branch, string $base, string $title, string $body): array
    {
        return self::object($this->call('create_pr', $repository, ['branch' => $branch, 'base' => $base, 'title' => $title, 'body' => $body]));
    }

    public function updateDraftPullRequest(string $repository, int $number, string $title, string $body): array
    {
        return self::object($this->call('update_pr', $repository, ['number' => $number, 'title' => $title, 'body' => $body]));
    }

    public function markReady(string $repository, int $number): void
    {
        $this->call('ready', $repository, ['number' => $number]);
    }

    public function addLabel(string $repository, int $issueNumber, string $label): void
    {
        $this->call('add_label', $repository, ['number' => $issueNumber, 'label' => $label]);
    }

    public function removeLabel(string $repository, int $issueNumber, string $label): void
    {
        $this->call('remove_label', $repository, ['number' => $issueNumber, 'label' => $label]);
    }

    public function comment(string $repository, int $issueNumber, string $body): array
    {
        return self::object($this->call('comment', $repository, ['number' => $issueNumber, 'body' => $body]));
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $response
     * @return array<string, mixed>
     */
    private static function object(array $response): array
    {
        $object = [];
        foreach ($response as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('GitHub returned a list where an object was required.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function call(string $action, string $repository, array $parameters = [], bool $push = false): array
    {
        return $this->transport->call(
            $action,
            $repository,
            $parameters,
            $push ? $this->credentials->pushEnvironment() : $this->credentials->apiEnvironment(),
            $this->timeoutSeconds,
        );
    }
}
