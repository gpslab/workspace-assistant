<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** Read-only GitHub issue and issue-event queries used by standalone intake. */
interface IntakeApi
{
    /** @return list<array<string, mixed>> */
    public function openIssues(string $repository, int $page, int $perPage, int $timeoutSeconds): array;

    /** @return list<array<string, mixed>> */
    public function issueEvents(string $repository, int $issueNumber, int $timeoutSeconds): array;
}
