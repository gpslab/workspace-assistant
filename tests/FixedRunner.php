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
 * example a limit classification from the engine's own parser. Several answers give a run that is
 * followed by another one (a rerun after a missing session): each start() takes the next answer,
 * the last one repeats.
 */
final class FixedRunner implements AgentRunner
{
    /** @var list<RunAnswer> */
    private array $answers;

    /** Index of the answer the current run returns; the last one repeats. */
    private int $index = 0;

    private ?RunAnswer $current = null;

    public function __construct(RunAnswer ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function start(string $prompt, ?string $sessionId): void
    {
        $this->current = $this->answers[$this->index] ?? null;
        if ($this->index < count($this->answers) - 1) {
            ++$this->index;
        }
    }

    public function poll(): RunnerState
    {
        return RunnerState::Done;
    }

    public function result(): RunAnswer
    {
        if ($this->current === null) {
            throw new \RuntimeException('result() before start().');
        }

        return $this->current;
    }

    public function kill(): void
    {
    }

    public function sessionId(): ?string
    {
        return $this->current?->sessionId;
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
