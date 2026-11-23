<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * One condition a gated Kimi run (a `pm` or `review` mode run, and an epic run) has to pass, as the
 * reason of its refusal.
 *
 * Each reason has two texts: an English one for `bot.log` ({@see self::logText()}, never localized,
 * the same sentence the startup warning uses) and one per locale for the chat, which
 * {@see KimiGateGuard::refusal()} renders from the catalog keys `gate.kimi_*`.
 */
enum KimiGateReason: string
{
    /** `$HOME/.agents` exists: every Kimi run reads agents, skills and `AGENTS.md` from it. */
    case HomeAgents = 'home_agents';

    /** The `protocol_version` of the sessions is not one the bot's readers were verified against. */
    case ProtocolUnknown = 'protocol_unknown';

    /** The gate shim fails its self-test, so it cannot guarantee that a call was gated. */
    case SelfTestFailed = 'self_test_failed';

    /**
     * The reason as `bot.log` carries it. `$detail` is the value the reason is about — the protocol
     * version, the self-test failure text — and is ignored by the reasons that have none.
     */
    public function logText(string $detail = ''): string
    {
        return match ($this) {
            self::HomeAgents => KimiGatedPreconditions::HOME_AGENTS_REASON,
            self::ProtocolUnknown => 'the wire protocol version '.$detail.' is unknown to the bot',
            self::SelfTestFailed => 'the gate shim self-test failed'.($detail !== '' ? ': '.$detail : ''),
        };
    }
}
