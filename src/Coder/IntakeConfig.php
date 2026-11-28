<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Env;

/** Bounded, explicitly configured standalone issue intake. */
final readonly class IntakeConfig
{
    public function __construct(
        public Routing $routing = new Routing(),
        public string $trustedAuthor = '',
        public int $pollSeconds = 30,
        public int $tickBudgetSeconds = 10,
        public int $workBudget = 4,
        public int $requestTimeoutSeconds = 5,
    ) {
        if ($pollSeconds < 1 || $tickBudgetSeconds < 1 || $workBudget < 1 || $requestTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Coder intake intervals, budgets and timeouts must be positive.');
        }
        if ($requestTimeoutSeconds > $tickBudgetSeconds) {
            throw new \InvalidArgumentException('CODER_INTAKE_REQUEST_TIMEOUT cannot exceed CODER_INTAKE_TICK_BUDGET.');
        }
        if ($routing->coderRepositories() !== [] && trim($trustedAuthor) === '') {
            throw new \InvalidArgumentException('CODER_INTAKE_AUTHOR is required when a repository is routed to coder.');
        }
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            routing: Routing::fromEnv($env),
            trustedAuthor: trim((string) $env->get('CODER_INTAKE_AUTHOR', '')),
            pollSeconds: max(5, (int) $env->get('CODER_INTAKE_POLL_SECONDS', '30')),
            tickBudgetSeconds: max(1, (int) $env->get('CODER_INTAKE_TICK_BUDGET', '10')),
            workBudget: min(100, max(1, (int) $env->get('CODER_INTAKE_WORK_BUDGET', '4'))),
            requestTimeoutSeconds: max(1, (int) $env->get('CODER_INTAKE_REQUEST_TIMEOUT', '5')),
        );
    }
}
