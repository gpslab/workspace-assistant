<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\AgentRunner;
use GpsLab\WorkspaceAssistant\RunAnswer;
use GpsLab\WorkspaceAssistant\RunnerState;
use GpsLab\WorkspaceAssistant\TurnRecord;

/**
 * An {@see AgentRunner} that is already finished and returns a prepared answer on the first poll.
 *
 * Lets a test drive the job pipeline with an answer no real engine could produce there, for
 * example a limit classification from the engine's own parser.
 */
final class FixedRunner implements AgentRunner
{
    public function __construct(private readonly RunAnswer $answer)
    {
    }

    public function start(string $prompt, ?string $sessionId): void
    {
    }

    public function poll(): RunnerState
    {
        return RunnerState::Done;
    }

    public function result(): RunAnswer
    {
        return $this->answer;
    }

    public function kill(): void
    {
    }

    public function sessionId(): ?string
    {
        return $this->answer->sessionId;
    }

    public function pid(): int
    {
        return 0;
    }

    public function getProgressLines(): array
    {
        return [];
    }

    public function promptHash(): string
    {
        return 'fixed';
    }

    public function promptWarnings(): array
    {
        return [];
    }

    public function timeoutReason(): string
    {
        return '';
    }

    public function diagnosticsPath(): ?string
    {
        return null;
    }

    public function actualModel(): ?string
    {
        return null;
    }

    public function turnRecord(): ?TurnRecord
    {
        return null;
    }
}
