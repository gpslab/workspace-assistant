<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The conditions a gated mode (a PM or review run, and an epic run) needs on Kimi, as one predicate.
 *
 * Three things make a gated Kimi run unsafe:
 * - `$HOME/.agents` exists. Kimi reads agents, skills and `AGENTS.md` from it even when the run home
 *   is relocated, so the bot does not control what the model is told;
 * - the wire `protocol_version` is unknown to {@see KimiVersionGuard}: the audit reads a private
 *   format the reader was not verified against;
 * - the gate shim fails its self-test, so it cannot guarantee that a call was gated.
 *
 * The predicate is pure. The caller supplies the values: {@see self::check()} takes the home
 * directory, the protocol version read for the session (null when not known) and the self-test
 * failure (null when it passed). It is deliberately not connected to the refusal of gated modes
 * yet: the task that lifts that refusal does so. The `$HOME/.agents` check lives here so the
 * enabling task can reuse it for its start-up warning.
 */
final class KimiGatedPreconditions
{
    public static function check(string $homeDir, ?string $protocolVersion, ?string $selfTestFailure): KimiGatedPreconditionResult
    {
        $reasons = [];

        if (self::homeAgentsPresent($homeDir)) {
            $reasons[] = 'the home directory has .agents: Kimi reads agents, skills and AGENTS.md from it even with a relocated home';
        }
        if (KimiVersionGuard::check(null, $protocolVersion)->protocol === KimiFormatStatus::Unknown) {
            $reasons[] = 'the wire protocol version '.(string) $protocolVersion.' is unknown to the bot';
        }
        if ($selfTestFailure !== null) {
            $reasons[] = 'the gate shim self-test failed'.($selfTestFailure !== '' ? ': '.$selfTestFailure : '');
        }

        return $reasons === [] ? KimiGatedPreconditionResult::ok() : KimiGatedPreconditionResult::failed($reasons);
    }

    /** Whether the user's home has the `.agents` directory Kimi reads regardless of the run home. */
    public static function homeAgentsPresent(string $homeDir): bool
    {
        $home = rtrim($homeDir, '/');

        return $home !== '' && is_dir($home.'/.agents');
    }
}
