<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * What kind of error a Kimi run ended with, as decided by {@see KimiErrorClassifier}.
 *
 * The kind drives three different reactions: an error that is shown in the chat, a queue pause
 * ({@see limitClass()}) and whether the stored session id is dropped. A refused account, a session
 * held by another instance and a rate limit are handled in different ways, which is why they are
 * separate cases rather than one "the run failed".
 */
enum KimiErrorClass: string
{
    /** The account may not use Kimi Code (`provider.auth_error`, HTTP 403): shown, no pause, no session reset. */
    case Auth = 'auth';

    /** Another instance holds the session (exit 4, `session.occupied`): shown, no pause, no session reset. */
    case Occupied = 'occupied';

    /** A provider rate limit (`provider.rate_limit`, or 429 / "rate limit" text): pauses the queue. */
    case Rate = 'rate';

    /** An exhausted balance (`APIProviderQuotaExhaustedError`): shown, no pause — waiting does not refill a balance. */
    case Quota = 'quota';

    /** The model alias is not in `config.toml`: the stderr line is shown verbatim. */
    case ModelNotConfigured = 'model_not_configured';

    /** `-S` with a session id that does not exist: shown verbatim, and the stored id is dropped. */
    case SessionNotFound = 'session_not_found';

    /** Exit code 1 with no recognised cause (a stream without events included). */
    case Failed = 'failed';

    /** Anything else: shown in the chat, no pause. */
    case Unknown = 'unknown';

    /**
     * The value of `RunAnswer::$limitClass`: `rate` pauses the queue, `quota` and every other case
     * do not, `null` means not a provider limit.
     *
     * @return 'rate'|'quota'|null
     */
    public function limitClass(): ?string
    {
        return match ($this) {
            self::Rate => 'rate',
            self::Quota => 'quota',
            default => null,
        };
    }
}
