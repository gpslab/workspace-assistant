<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Locale;

/**
 * The refusal predicate of the gated modes (`pm`, `review`) and of the epics on Kimi: a gated run
 * starts while the preconditions of {@see KimiGatedPreconditions} hold and is refused with the
 * failing reason when they do not.
 *
 * The reason the refusal exists at all: a Kimi hook that is killed, times out or is missing does not
 * block a call (only exit code 2 does), so the gate of a Kimi run is verified **after** the run
 * ({@see KimiRunAudit}). A run that passes without its gate looks normal and is not, so the modes
 * that carry one are refused unless the bot can tell that the gate holds.
 *
 * Where the inputs come from:
 * - `$HOME/.agents` and the protocol version are read on every call. Both change while the bot runs —
 *   the operator removes the directory, the engine updates itself — and both are cheap file checks.
 *   The protocol version is the one of the newest session on the source home: Kimi writes
 *   `metadata.protocol_version` once per session, so the session the running engine created last
 *   tells the format it speaks. No session yet (a fresh instance) is not an unknown version.
 * - The self-test of the gate shim is cached only when it passes: the shim belongs to the installation,
 *   so a change of it comes with a restart. A failure is re-taken (with a short backoff), because the
 *   self-test also probes state that can heal — the state directory's writability — or can be a single
 *   slow call under load. The self-test of {@see \GpsLab\WorkspaceAssistant\JobRunner} stays the
 *   authoritative one before a gated run.
 */
final class KimiGateGuard
{
    /** Complete lines of the head of a `wire.jsonl` read for the protocol version. */
    private const int WIRE_HEAD_BYTES = 65536;

    /** A failed self-test is re-taken no more often than this: a broken shim must not cost its 5 s timeout per message. */
    private const int SELF_TEST_RETRY_SEC = 60;

    private ?string $selfTestFailure = null;
    private bool $selfTestPassed = false;
    private float $selfTestFailedAt = 0.0;

    /**
     * @param string|null $homeDir          home of the user the bot runs as; null = the real `$HOME`
     * @param string|null $selfTestShim     shim to self-test; null = the one of this installation (test seam)
     * @param int         $selfTestRetrySec how long a failed self-test is trusted before it is re-taken (test seam)
     */
    public function __construct(
        private readonly Config $config,
        private readonly Locale $locale,
        private readonly ?string $homeDir = null,
        private readonly ?string $selfTestShim = null,
        private readonly int $selfTestRetrySec = self::SELF_TEST_RETRY_SEC,
    ) {
    }

    /**
     * The refusal as the chat reads it, in the locale of the bot, or null when a gated run may start
     * (on this engine or on any other).
     */
    public function refusal(): ?string
    {
        $result = $this->check();
        if ($result === null || $result->isOk()) {
            return null;
        }
        $texts = [];
        foreach ($result->failed as $reason) {
            $detail = $result->detail($reason);
            $texts[] = match ($reason) {
                KimiGateReason::HomeAgents => $this->locale->t('gate.kimi_home_agents'),
                KimiGateReason::ProtocolUnknown => $this->locale->t('gate.kimi_protocol_unknown', ['version' => $detail]),
                KimiGateReason::SelfTestFailed => $this->locale->t('gate.kimi_self_test_failed', ['detail' => $detail]),
            };
        }

        return implode('; ', $texts);
    }

    /**
     * The same refusal for `bot.log`: English, one sentence per failing condition, or null when a
     * gated run may start. Logs never carry a locale.
     */
    public function refusalLog(): ?string
    {
        $result = $this->check();
        if ($result === null || $result->isOk()) {
            return null;
        }

        return implode('; ', $result->reasons);
    }

    /** The preconditions of a gated run right now; null on an engine whose gate this is not about. */
    private function check(): ?KimiGatedPreconditionResult
    {
        if (!$this->config->isKimi()) {
            return null;
        }

        return KimiGatedPreconditions::check(
            $this->home(),
            $this->protocolVersion(),
            $this->selfTestFailure(),
        );
    }

    /** Home of the user the bot runs as. */
    private function home(): string
    {
        return $this->homeDir ?? (string) getenv('HOME');
    }

    /**
     * `metadata.protocol_version` of the newest session on the source home, or null when the home has
     * no session with a readable wire yet. A version that is not there is not an unknown one: the
     * readers are verified against a format the bot has not seen, not against a format it cannot read.
     */
    private function protocolVersion(): ?string
    {
        $path = $this->newestWirePath();
        if ($path === null) {
            return null;
        }

        return KimiWireReader::read($this->readHeadLines($path))->protocolVersion;
    }

    /**
     * `wire.jsonl` of the session the engine wrote last: the file is written from the start of a
     * session, so the newest one belongs to the version of the engine that is installed now.
     */
    private function newestWirePath(): ?string
    {
        $paths = glob($this->sourceHome().'/sessions/*/session_*/agents/main/wire.jsonl') ?: [];
        $newest = null;
        $newestAt = -1;
        foreach ($paths as $path) {
            $at = @filemtime($path);
            if ($at !== false && $at > $newestAt) {
                $newestAt = $at;
                $newest = $path;
            }
        }

        return $newest;
    }

    /** Home of the logged-in Kimi CLI, the one every run links its credentials and sessions from. */
    private function sourceHome(): string
    {
        if ($this->config->kimiHome !== '') {
            return rtrim($this->config->kimiHome, '/');
        }

        return rtrim($this->home(), '/').'/.kimi-code';
    }

    /**
     * The complete lines of the head of a `wire.jsonl` (at most {@see self::WIRE_HEAD_BYTES} bytes):
     * `metadata` and `profile.bind` are written there once, at the start of the session.
     *
     * @return list<string>
     */
    private function readHeadLines(string $path): array
    {
        $h = @fopen($path, 'r');
        if ($h === false) {
            return [];
        }
        $body = (string) fread($h, self::WIRE_HEAD_BYTES);
        fclose($h);
        // The last line of the head is likely truncated: only whole lines are parsed.
        $end = strrpos($body, "\n");
        if ($end === false) {
            return [];
        }

        return explode("\n", substr($body, 0, $end));
    }

    /**
     * The self-test failure of the shim. A pass is cached for the process — the shim belongs to the
     * installation, so a change of it comes with a restart. A failure is not: it is re-taken, but no
     * more often than {@see self::SELF_TEST_RETRY_SEC}, because a single slow call or a transiently
     * unwritable state directory must not block the gated modes until a restart. The self-test of
     * {@see \GpsLab\WorkspaceAssistant\JobRunner} still runs before every gated run.
     */
    private function selfTestFailure(): ?string
    {
        if ($this->selfTestPassed) {
            return null;
        }
        if ($this->selfTestFailure === null || microtime(true) - $this->selfTestFailedAt >= $this->selfTestRetrySec) {
            $failure = (new KimiGateSelfTest($this->selfTestShim))->run($this->config->stateDir);
            if ($failure === null) {
                $this->selfTestPassed = true;
                $this->selfTestFailure = null;
            } else {
                $this->selfTestFailure = $failure;
                $this->selfTestFailedAt = microtime(true);
            }
        }

        return $this->selfTestFailure;
    }
}
