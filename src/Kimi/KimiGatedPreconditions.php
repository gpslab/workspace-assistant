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
 * failure (null when it passed). {@see KimiGateGuard} supplies them from the running instance and is
 * what the gated modes and the epics are refused by; the `$HOME/.agents` check is shared with the
 * startup warning of the bot.
 */
final class KimiGatedPreconditions
{
    /**
     * Why a present `$HOME/.agents` is a problem, shared by {@see self::check()} and the startup warning
     * of the bot: one sentence, so the operator reads the same thing in the log and in the refusal of a
     * gated run.
     */
    public const string HOME_AGENTS_REASON = 'the home directory has .agents: Kimi reads agents, skills and AGENTS.md from it even with a relocated home';

    public static function check(string $homeDir, ?string $protocolVersion, ?string $selfTestFailure): KimiGatedPreconditionResult
    {
        $failed = [];
        $details = [];

        if (self::homeAgentsPresent($homeDir)) {
            $failed[] = KimiGateReason::HomeAgents;
        }
        if (KimiVersionGuard::check(null, $protocolVersion)->protocol === KimiFormatStatus::Unknown) {
            $failed[] = KimiGateReason::ProtocolUnknown;
            $details[KimiGateReason::ProtocolUnknown->value] = (string) $protocolVersion;
        }
        if ($selfTestFailure !== null) {
            $failed[] = KimiGateReason::SelfTestFailed;
            $details[KimiGateReason::SelfTestFailed->value] = $selfTestFailure;
        }

        return $failed === [] ? KimiGatedPreconditionResult::ok() : KimiGatedPreconditionResult::failed($failed, $details);
    }

    /** Whether the user's home has the `.agents` directory Kimi reads regardless of the run home. */
    public static function homeAgentsPresent(string $homeDir): bool
    {
        $home = rtrim($homeDir, '/');

        return $home !== '' && is_dir($home.'/.agents');
    }
}
