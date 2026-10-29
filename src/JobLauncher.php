<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/** Port: starts a Claude run and writes {@see RunState} synchronously. */
interface JobLauncher
{
    /**
     * Returns the start error text or null.
     *
     * @param array{run:string, pr:?string}|null $epicRun
     * @param array<string, string>              $reviewShas reviewed commit per PR (`owner/repo#N` => SHA)
     */
    public function launchJob(int $chatId, int $threadId, string $kind, string $prompt, ?string $sessionId, int $thinkingId, ?string $compactOldSessionId, string $mode, int $queueId, ?string $epicSlug = null, ?array $epicRun = null, ?string $idsPath = null, ?string $localeCode = null, array $reviewShas = []): ?string;
}
