<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\GithubOwners;

/**
 * Epic plan: JSON in the first fenced ```json block of the md file `<slug>.md`.
 *
 * Block format:
 *
 *     {
 *       "tasks": [{"task": "plugins#12", "after": []}, {"task": "other-owner/lib#3", "after": []}],
 *       "releases": [{"repo": "plugins", "version": "v1.2.0", "after": ["plugins#12"]}]
 *     }
 *
 * `tasks[].task` is `owner/repo#N` or the short `repo#N`; `releases[].repo` is `owner/repo` or the
 * short `repo`. The short forms belong to the default owner (the first of `GITHUB_OWNERS`).
 *
 * Pure parsing with no DB or network access. Any plan error is an \InvalidArgumentException
 * with a human-readable reason (it goes to the author in reply to /epic start).
 */
final class Plan
{
    /** `owner/repo#N` or the short `repo#N` (the default owner). */
    private const string TASK_RE = '~^(?:[A-Za-z0-9][A-Za-z0-9._-]*/)?[A-Za-z0-9][A-Za-z0-9._-]*#[1-9][0-9]*$~';

    /** `owner/repo` or the short `repo` (the default owner). */
    private const string REPO_RE = '~^(?:[A-Za-z0-9][A-Za-z0-9._-]*/)?[A-Za-z0-9][A-Za-z0-9._-]*$~';

    private const string BLOCK_RE = '/^```json[ \t]*\R(.*?)\R```[ \t]*$/ms';

    /**
     * @param list<array{task: string, after: list<string>}>                  $tasks
     * @param list<array{repo: string, version: string, after: list<string>}> $releases
     */
    private function __construct(
        public readonly array $tasks,
        public readonly array $releases,
    ) {
    }

    public static function isTaskRef(string $value): bool
    {
        return preg_match(self::TASK_RE, $value) === 1;
    }

    public static function isRepoRef(string $value): bool
    {
        return preg_match(self::REPO_RE, $value) === 1;
    }

    /**
     * @param GithubOwners $owners references are normalized (`<default owner>/repo#N`
     *                             becomes `repo#N`, so both forms are one task) and the plan is
     *                             rejected while `GITHUB_OWNERS` is not set
     */
    public static function load(string $path, GithubOwners $owners): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("plan file not found: {$path}");
        }
        $markdown = file_get_contents($path);
        if ($markdown === false) {
            throw new \InvalidArgumentException("plan file is not readable: {$path}");
        }

        return self::parse($markdown, $owners);
    }

    /**
     * @see load()
     */
    public static function parse(string $markdown, GithubOwners $owners): self
    {
        if ($owners->isEmpty()) {
            throw new \InvalidArgumentException(GithubOwners::NOT_SET);
        }
        $norm = static fn (string $ref): string => $owners->canonical($ref);

        if (preg_match(self::BLOCK_RE, $markdown, $m) !== 1) {
            throw new \InvalidArgumentException('the plan has no ```json block');
        }
        try {
            $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('the ```json block cannot be parsed: '.$e->getMessage());
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new \InvalidArgumentException('the ```json block must be an object with a tasks field');
        }

        $rawTasks = $data['tasks'] ?? null;
        if (!is_array($rawTasks) || $rawTasks === [] || !array_is_list($rawTasks)) {
            throw new \InvalidArgumentException('tasks must be a non-empty list of tasks');
        }

        $tasks = [];
        foreach ($rawTasks as $i => $raw) {
            $ref = is_array($raw) ? ($raw['task'] ?? null) : null;
            if (!is_string($ref) || !self::isTaskRef($ref)) {
                throw new \InvalidArgumentException("tasks[{$i}]: a task must look like repo#N or owner/repo#N");
            }
            $ref = $norm($ref);
            if (isset($tasks[$ref])) {
                throw new \InvalidArgumentException("task {$ref} is listed twice in the plan");
            }
            $tasks[$ref] = ['task' => $ref, 'after' => self::refList($raw['after'] ?? [], "tasks[{$i}].after", $norm)];
        }
        foreach ($tasks as $task) {
            foreach ($task['after'] as $dep) {
                if (!isset($tasks[$dep])) {
                    throw new \InvalidArgumentException("{$task['task']}: after refers to {$dep}, which is not in the plan");
                }
            }
        }
        self::assertAcyclic($tasks);

        $rawReleases = $data['releases'] ?? [];
        if (!is_array($rawReleases) || !array_is_list($rawReleases)) {
            throw new \InvalidArgumentException('releases must be a list');
        }
        $releases = [];
        foreach ($rawReleases as $i => $raw) {
            $repo = is_array($raw) ? ($raw['repo'] ?? null) : null;
            $version = is_array($raw) ? ($raw['version'] ?? null) : null;
            if (!is_string($repo) || !self::isRepoRef($repo)) {
                throw new \InvalidArgumentException("releases[{$i}]: repo is required — a repository name like repo or owner/repo");
            }
            $repo = $norm($repo);
            if (!is_string($version) || trim($version) === '') {
                throw new \InvalidArgumentException("releases[{$i}]: version is required");
            }
            if (preg_match('/^v\d+\.\d+\.\d+$/', trim($version)) !== 1) {
                throw new \InvalidArgumentException("releases[{$i}]: version must look like vX.Y.Z (as repository tags do), got '{$version}'");
            }
            if (!isset($raw['after'])) {
                throw new \InvalidArgumentException("releases[{$i}]: after is required — the tasks the release follows");
            }
            $after = self::refList($raw['after'], "releases[{$i}].after", $norm);
            if ($after === []) {
                throw new \InvalidArgumentException("releases[{$i}]: after must not be empty");
            }
            foreach ($after as $dep) {
                if (!isset($tasks[$dep])) {
                    throw new \InvalidArgumentException("release {$repo} {$version}: after refers to {$dep}, which is not in the plan");
                }
            }
            $releases[] = ['repo' => $repo, 'version' => trim($version), 'after' => $after];
        }

        return new self(array_values($tasks), $releases);
    }

    /**
     * @return list<string>
     */
    private static function refList(mixed $value, string $where, \Closure $norm): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$where} must be a list of tasks repo#N or owner/repo#N");
        }
        foreach ($value as $ref) {
            if (!is_string($ref) || !self::isTaskRef($ref)) {
                throw new \InvalidArgumentException("{$where}: an element must look like repo#N or owner/repo#N");
            }
        }

        return array_values(array_unique(array_map($norm, $value)));
    }

    /**
     * @param array<string, array{task: string, after: list<string>}> $tasks
     */
    private static function assertAcyclic(array $tasks): void
    {
        $state = [];
        $visit = static function (string $ref, array $path) use (&$visit, &$state, $tasks): void {
            if (($state[$ref] ?? 0) === 2) {
                return;
            }
            if (($state[$ref] ?? 0) === 1) {
                $cycle = array_slice($path, (int) array_search($ref, $path, true));
                throw new \InvalidArgumentException('cycle in after: '.implode(' → ', [...$cycle, $ref]));
            }
            $state[$ref] = 1;
            foreach ($tasks[$ref]['after'] as $dep) {
                $visit($dep, [...$path, $ref]);
            }
            $state[$ref] = 2;
        };
        foreach (array_keys($tasks) as $ref) {
            $visit($ref, []);
        }
    }
}
