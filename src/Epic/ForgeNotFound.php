<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Epic;

/**
 * The forge answered "not found" for a task or a repository: the issue does not exist (or the number
 * belongs to a PR), the repository does not exist, or the bot's token has no access to it — the forge
 * gives the same answer for all of them.
 *
 * Thrown by {@see \GpsLab\WorkspaceAssistant\GhClient} where "not found" is a fact and not a transient
 * failure: the epic poll blocks the epic ({@see Orchestrator}), the existence check of a new task
 * rejects the signal ({@see RunLifecycle}). Network, rate-limit and token errors stay \RuntimeException
 * and keep the "poll failing" path.
 */
final class ForgeNotFound extends \RuntimeException
{
    /**
     * @param string $ref  what was asked about: a task `owner/repo#N` (`repo#N`) or a repository `owner/repo`
     * @param string $repo the repository (`owner/repo`) the reference belongs to
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $repo,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "{$ref} not found");
    }
}
