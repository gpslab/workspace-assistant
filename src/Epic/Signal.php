<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

use GpsLab\WorkspaceAssistant\GithubOwners;

/**
 * Epic run signal — the file `<stateDir>/epics/<slug>/signal.json`, the run's output.
 *
 * All fields are optional; a missing field means "no change":
 *  - added   — [{"task": "repo#N", "after": ["repo#M"]}], extra tasks and tails;
 *  - hold    — ["repo#N"], the FULL current list of tasks that must not be handed to
 *              openronin (an empty list clears all holds);
 *  - message — text to the author (a question or a fork);
 *  - blocked — the reason, if the whole epic is stuck;
 *  - done    — true: the final check is passed, the report is in the outbox;
 *  - verdict — approve | request_changes: the published verdict of the hot review;
 *  - cold_findings — [{"id": "C1", "verdict": "fixed|open|refuted", "where": "…"}]:
 *              a verdict on each cold reviewer finding (review run only).
 *
 * An invalid signal is an \InvalidArgumentException (the run failed).
 */
final class Signal
{
    public const string VERDICT_APPROVE = 'approve';
    public const string VERDICT_REQUEST_CHANGES = 'request_changes';

    public const string FINDING_FIXED = 'fixed';
    public const string FINDING_OPEN = 'open';
    public const string FINDING_REFUTED = 'refuted';

    /**
     * @param list<array{task: string, after: list<string>}>          $added
     * @param list<string>|null                                       $hold         null — the field was not passed
     * @param list<array{id: string, verdict: string, where: string}> $coldFindings
     */
    private function __construct(
        public readonly array $added,
        public readonly ?array $hold,
        public readonly ?string $message,
        public readonly ?string $blocked,
        public readonly bool $done,
        public readonly ?string $verdict = null,
        public readonly array $coldFindings = [],
    ) {
    }

    public static function parse(string $json, GithubOwners $owners): self
    {
        if ($owners->isEmpty()) {
            throw new \InvalidArgumentException(GithubOwners::NOT_SET);
        }
        $norm = static fn (string $ref): string => $owners->canonical($ref);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('not JSON: '.$e->getMessage());
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException('the signal must be a JSON object');
        }

        $added = [];
        $rawAdded = $data['added'] ?? [];
        if (!is_array($rawAdded) || !array_is_list($rawAdded)) {
            throw new \InvalidArgumentException('added must be a list');
        }
        foreach ($rawAdded as $i => $item) {
            $ref = is_array($item) ? ($item['task'] ?? null) : null;
            if (!is_string($ref) || !Plan::isTaskRef($ref)) {
                throw new \InvalidArgumentException("added[{$i}]: task must look like repo#N");
            }
            $added[] = ['task' => $norm($ref), 'after' => self::refs($item['after'] ?? [], "added[{$i}].after", $norm)];
        }

        $hold = array_key_exists('hold', $data) ? self::refs($data['hold'], 'hold', $norm) : null;

        return new self(
            $added,
            $hold,
            self::text($data, 'message'),
            self::text($data, 'blocked'),
            self::flag($data['done'] ?? false),
            self::verdict($data['verdict'] ?? null),
            self::coldFindings($data['cold_findings'] ?? []),
        );
    }

    private static function verdict(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value !== self::VERDICT_APPROVE && $value !== self::VERDICT_REQUEST_CHANGES) {
            throw new \InvalidArgumentException('verdict must be approve or request_changes');
        }

        return $value;
    }

    /**
     * @return list<array{id: string, verdict: string, where: string}>
     */
    private static function coldFindings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('cold_findings must be a list');
        }
        $out = [];
        foreach ($value as $i => $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($id) || preg_match('/^C\d+$/', $id) !== 1) {
                throw new \InvalidArgumentException("cold_findings[{$i}]: id must look like C1");
            }
            $verdict = $item['verdict'] ?? null;
            if (!in_array($verdict, [self::FINDING_FIXED, self::FINDING_OPEN, self::FINDING_REFUTED], true)) {
                throw new \InvalidArgumentException("cold_findings[{$i}]: verdict must be fixed, open or refuted");
            }
            $where = $item['where'] ?? '';
            if (!is_string($where)) {
                throw new \InvalidArgumentException("cold_findings[{$i}]: where must be a string");
            }
            if ($verdict === self::FINDING_FIXED && trim($where) === '') {
                throw new \InvalidArgumentException("cold_findings[{$i}]: fixed requires where (the place in the diff)");
            }
            $out[] = ['id' => $id, 'verdict' => $verdict, 'where' => $where];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function refs(mixed $value, string $where, \Closure $norm): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$where} must be a list of repo#N");
        }
        foreach ($value as $ref) {
            if (!is_string($ref) || !Plan::isTaskRef($ref)) {
                throw new \InvalidArgumentException("{$where}: an element must look like repo#N");
            }
        }

        return array_values(array_unique(array_map($norm, $value)));
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string");
        }

        return trim($value) === '' ? null : $value;
    }

    private static function flag(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('done must be true/false');
        }

        return $value;
    }
}
