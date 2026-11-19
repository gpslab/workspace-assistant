<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Engine-neutral contract of an asynchronous agent run.
 *
 * The public surface of the runner that {@see JobRunner}, {@see RunState} and
 * {@see CompactFlow} drive. {@see ClaudeRunner} is the Claude Code implementation;
 * a future engine (OpenCode, …) implements the same contract without touching the
 * runner wiring. `updateOnResume` is deliberately NOT here: whether a resumed
 * session whose prompt hash changed gets the instructions-update block prepended
 * is an engine detail (Claude Code needs it, OpenCode applies the delta itself).
 * The static helpers {@see ClaudeRunner::modelFromArgs()} and
 * {@see ClaudeRunner::purgeRunFiles()} are Claude Code details and stay out of the
 * contract too.
 */
interface AgentRunner
{
    /** Starts the run non-blocking; poll() advances it to completion. */
    public function start(string $prompt, ?string $sessionId): void;

    /**
     * Non-blocking state check. On {@see RunnerState::Done} the result is available
     * via {@see result()}.
     */
    public function poll(): RunnerState;

    /** Result of a finished call. Valid only after poll() === RunnerState::Done. */
    public function result(): RunAnswer;

    /** Terminates the run if it is still active. */
    public function kill(): void;

    /** session_id of the current run as soon as it is seen; null until then. */
    public function sessionId(): ?string;

    /** PID of the running process. */
    public function pid(): int;

    /**
     * Accumulated live-progress lines (tool calls) as work progresses.
     *
     * @return list<string>
     */
    public function getProgressLines(): array;

    /** Hash of the system prompt passed in argv by the last start(); null before it. */
    public function promptHash(): ?string;

    /**
     * Persona-file problems of the last start().
     *
     * @return list<string>
     */
    public function promptWarnings(): array;

    /** Reason for forced termination (for RunnerState::Timeout). */
    public function timeoutReason(): string;

    /** Path of the diagnostics file written when the run ended without a result, if any. */
    public function diagnosticsPath(): ?string;

    /** The model reported by the run's system/init event; null until it is seen. */
    public function actualModel(): ?string;

    /**
     * The neutral record of the finished turn, for a check the Bot runs after the run itself
     * ({@see TurnRecord}, used by the review status check on an engine with no Stop hooks).
     * Valid only after poll() === RunnerState::Done; null — the engine reports nothing the record
     * needs, so the check is not available there (Claude Code: its review status is enforced by the
     * Stop hook during the run, not afterwards).
     */
    public function turnRecord(): ?TurnRecord;
}
