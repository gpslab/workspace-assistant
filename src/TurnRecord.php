<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Opencode\OpencodeNdjsonParser;

/**
 * The neutral record of one turn: the shell calls it made (the command of each and how the call ended)
 * and the final text it produced.
 *
 * It exists so that the review status check ({@see ReviewStatusHook}) is engine-neutral: a turn of a
 * Claude Code run is read from its transcript lines and a turn of an OpenCode run from its NDJSON
 * stream ({@see OpencodeNdjsonParser::parse()}), and both produce this record. Claude Code's `Bash`
 * tool and OpenCode's `shell` tool are the same thing here.
 *
 * The status is the wording of the engine: `completed` for a call that ended without an error (Claude
 * Code's tool result without `is_error`), {@see STATUS_ERROR} for one that failed. A call the forge
 * rejected published nothing, so it is neither the status of the turn nor part of a review body.
 */
final class TurnRecord
{
    /** The call ended with an error. */
    public const string STATUS_ERROR = 'error';

    /** The call ended without an error. */
    public const string STATUS_COMPLETED = 'completed';

    /** Tool name of a shell call in the OpenCode stream (`Bash` in a Claude Code transcript). */
    private const string SHELL_TOOL = 'shell';

    /**
     * @param list<array{command: string, status: string}> $shellCalls shell calls in call order
     * @param string                                       $finalText  the last text the turn produced
     */
    public function __construct(
        public readonly array $shellCalls,
        public readonly string $finalText,
    ) {
    }

    /**
     * The record of an OpenCode run from the neutral tool calls of its parser: only the shell calls
     * that carried a command are part of it — the other tools of a run publish nothing.
     *
     * A call whose status the stream does not carry counts as {@see STATUS_COMPLETED}: only an
     * explicit {@see STATUS_ERROR} is a failure, exactly as an absent `is_error` on Claude Code.
     *
     * @param list<array{tool: string, command: string|null, status: string|null}> $toolCalls
     */
    public static function fromToolCalls(array $toolCalls, string $finalText): self
    {
        $shellCalls = [];
        foreach ($toolCalls as $call) {
            if ($call['tool'] !== self::SHELL_TOOL || $call['command'] === null || $call['command'] === '') {
                continue;
            }
            $shellCalls[] = [
                'command' => $call['command'],
                'status' => $call['status'] ?? self::STATUS_COMPLETED,
            ];
        }

        return new self($shellCalls, $finalText);
    }

    /**
     * The commands of the shell calls in call order, failed or not.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->shellCalls, 'command');
    }

    /**
     * The commands of the shell calls that did not fail, in call order: only these can have set a
     * status or published a review body.
     *
     * @return list<string>
     */
    public function successfulCommands(): array
    {
        $commands = [];
        foreach ($this->shellCalls as $call) {
            if ($call['status'] !== self::STATUS_ERROR) {
                $commands[] = $call['command'];
            }
        }

        return $commands;
    }
}
