<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Log;

/**
 * Bounded standalone issue intake. This controller is the only owner of polling cursors;
 * the execution worker only leases the durable jobs created here.
 */
final class IntakeController
{
    private const int ISSUE_PAGE_SIZE = 50;
    private const string LABEL_DO_IT = 'openronin:do-it';

    private float $lastPollAt = 0.0;
    /** @var \Closure():float */
    private readonly \Closure $clock;

    /** @param \Closure():float|null $clock */
    public function __construct(
        private readonly IntakeConfig $config,
        private readonly Store $store,
        private readonly IntakeApi $github,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Unix time of the next scheduled pass, or null when the explicit allowlist is empty. */
    public function pollDueAt(): ?int
    {
        if ($this->config->routing->coderRepositories() === []) {
            return null;
        }

        return (int) $this->lastPollAt + $this->config->pollSeconds;
    }

    /**
     * Poll at most the configured number of issues and never start a worker.
     * Returns the number of issue records examined in this pass.
     */
    public function poll(): int
    {
        $repositories = $this->config->routing->coderRepositories();
        $now = ($this->clock)();
        if ($repositories === [] || $now < $this->lastPollAt + $this->config->pollSeconds) {
            return 0;
        }
        $this->lastPollAt = $now;
        $deadline = $now + $this->config->tickBudgetSeconds;
        $after = $this->store->intakeSchedule();
        $start = 0;
        if ($after !== null) {
            foreach ($repositories as $index => $repository) {
                if (strcasecmp($repository, $after) === 0) {
                    $start = ($index + 1) % count($repositories);
                    break;
                }
            }
        }

        $examined = 0;
        $attempted = 0;
        $count = count($repositories);
        for ($i = 0; $i < $count; ++$i) {
            if ($attempted > 0 && ($attempted >= $this->config->workBudget || $examined >= $this->config->workBudget || ($this->clock)() >= $deadline)) {
                break;
            }
            $repository = $repositories[($start + $i) % $count];
            ++$attempted;
            try {
                $examined += $this->pollRepository($repository, $this->config->workBudget - $examined, $deadline);
            } catch (\Throwable $e) {
                // One repository never prevents another repository or the Telegram loop from progressing.
                Log::line("coder intake {$repository}: ".$e->getMessage());
            } finally {
                $this->store->saveIntakeSchedule($repository);
            }
            if ($attempted >= $this->config->workBudget || $examined >= $this->config->workBudget || ($this->clock)() >= $deadline) {
                break;
            }
        }

        return $examined;
    }

    private function pollRepository(string $repository, int $workLeft, float $deadline): int
    {
        if ($workLeft < 1 || ($this->clock)() >= $deadline) {
            return 0;
        }
        $cursor = $this->store->intakeCursor($repository);
        $issues = $this->github->openIssues(
            $repository,
            $cursor['page'],
            self::ISSUE_PAGE_SIZE,
            $this->requestTimeout($deadline),
        );
        if ($issues === []) {
            $this->store->saveIntakeCursor($repository, 1, 0);

            return 0;
        }

        $examined = 0;
        $offset = $cursor['offset'];
        $count = count($issues);
        while ($offset < $count && $examined < $workLeft) {
            if (($this->clock)() >= $deadline) {
                break;
            }
            $issue = $issues[$offset];
            ++$offset;
            ++$examined;
            $this->admit($repository, $issue, $deadline);
            $this->store->saveIntakeCursor($repository, $cursor['page'], $offset);
        }
        if ($offset >= $count) {
            $this->store->saveIntakeCursor($repository, $count < self::ISSUE_PAGE_SIZE ? 1 : $cursor['page'] + 1, 0);
        }

        return $examined;
    }

    /** @param array<string, mixed> $issue */
    private function admit(string $repository, array $issue, float $deadline): void
    {
        $number = $issue['number'] ?? null;
        if ((!is_int($number) && !(is_string($number) && ctype_digit($number)))
            || ($issue['state'] ?? 'open') !== 'open'
            || isset($issue['pull_request'])
            || !$this->hasDoItLabel($issue['labels'] ?? [])
            || ($this->clock)() >= $deadline) {
            return;
        }
        $number = (int) $number;
        $events = $this->github->issueEvents($repository, $number, $this->requestTimeout($deadline));
        // One bounded page is fail-closed: without later events the current label's provenance is unknown.
        if (count($events) >= 100) {
            return;
        }
        $approval = $this->currentApproval($events);
        if ($approval === null) {
            return;
        }
        $eventId = $approval['id'];
        $title = is_string($issue['title'] ?? null) ? $issue['title'] : "Issue #{$number}";
        $body = is_string($issue['body'] ?? null) ? $issue['body'] : '';
        $url = is_string($issue['html_url'] ?? null) ? $issue['html_url'] : "https://github.com/{$repository}/issues/{$number}";
        $prompt = "Implement GitHub issue {$repository}#{$number}: {$title}\n\n{$body}\n\nIssue URL: {$url}\n"
            .'Work in the isolated local checkout. Do not push, open a pull request, mutate labels, or comment on GitHub.';
        $this->store->enqueueIntake(
            repository: $repository,
            eventKey: "standalone-issue:{$number}:label-event:{$eventId}",
            branch: "coder/issue-{$number}",
            issueNumber: $number,
            diagnostics: [
                'prompt' => $prompt,
                'source' => 'standalone_issue_intake',
                'issue_url' => $url,
                'label_event_id' => (string) $eventId,
                'approved_by' => $this->config->trustedAuthor,
            ],
        );
    }

    /**
     * The latest label/unlabel event must be the trusted approval. An older approval cannot
     * authorize a later re-label by somebody else.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>|null
     */
    private function currentApproval(array $events): ?array
    {
        $latest = null;
        foreach ($events as $event) {
            if (($event['label']['name'] ?? null) === self::LABEL_DO_IT
                && in_array($event['event'] ?? null, ['labeled', 'unlabeled'], true)) {
                $latest = $event;
            }
        }
        if ($latest === null || $latest['event'] !== 'labeled') {
            return null;
        }
        $eventId = $latest['id'] ?? null;
        $actor = $latest['actor']['login'] ?? null;

        return (is_int($eventId) || (is_string($eventId) && $eventId !== ''))
            && is_string($actor)
            && strcasecmp($actor, $this->config->trustedAuthor) === 0
            ? $latest
            : null;
    }

    private function hasDoItLabel(mixed $labels): bool
    {
        if (!is_array($labels)) {
            return false;
        }
        foreach ($labels as $label) {
            if (is_array($label) && ($label['name'] ?? null) === self::LABEL_DO_IT) {
                return true;
            }
        }

        return false;
    }

    private function requestTimeout(float $deadline): int
    {
        $remaining = (int) floor($deadline - ($this->clock)());
        if ($remaining < 1) {
            throw new \RuntimeException('The coder intake time budget is exhausted.');
        }

        return min($this->config->requestTimeoutSeconds, $remaining);
    }
}
