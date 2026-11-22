<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

use GpsLab\WorkspaceAssistant\ClaudeErrorText;

/**
 * Classifies the failure of a Kimi run into a {@see KimiErrorClass}.
 *
 * Pure: it takes the facts the caller already has — the exit code, stderr, the parsed stdout
 * ({@see KimiStream}) and the `error` of `wire.jsonl` ({@see KimiTurnError}) — and performs no I/O.
 *
 * A failed run prints only "Prompt turn ended with reason: failed" on stderr, which tells the
 * classes apart not at all, so the real error is taken from `wire.jsonl` first. The wire error is
 * classified by its `code` and `name` before its `message` text: the code alone does not separate a
 * quota 429 from an HTTP 500 (both are `provider.api_error`, and only the `name`
 * `APIProviderQuotaExhaustedError` marks the quota), and the message text of the synthetic
 * `30-mock-*` fixtures itself carries the words "quota" and "rate limit", so a text-first
 * classifier would answer on those words rather than on the code and the name.
 *
 * The text classification is {@see ClaudeErrorText::foreignLimit()}, reused and not copied. It sees
 * the `message` of a wire error only when the code and the name give no class, and stderr only when
 * the run left no wire error at all: the generic stderr line must not override a classified wire
 * error.
 */
final class KimiErrorClassifier
{
    /** `code` of a 403 refusal of the account. */
    private const string CODE_AUTH = 'provider.auth_error';

    /** `code` of a provider rate limit. */
    private const string CODE_RATE_LIMIT = 'provider.rate_limit';

    /** `name` of an exhausted balance; its `code` is the shared `provider.api_error`. */
    private const string NAME_QUOTA_EXHAUSTED = 'APIProviderQuotaExhaustedError';

    /** `code` of the stdout `error` event of a session held by another instance. */
    private const string CODE_SESSION_OCCUPIED = 'session.occupied';

    /** Exit code of a run whose session is in use; stderr is empty and the error is on stdout. */
    private const int EXIT_SESSION_OCCUPIED = 4;

    /** Exit code of every other Kimi failure. */
    private const int EXIT_FAILURE = 1;

    /** Tail of the stderr line of a model alias missing from `config.toml`, shown verbatim. */
    private const string MODEL_NOT_CONFIGURED_MARKER = 'is not configured in config.toml.';

    /** The stderr line of `-S` with an unknown session id, shown verbatim. */
    private const string SESSION_NOT_FOUND_PATTERN = '/Session "[^"]*" not found\./';

    public static function classify(
        int $exitCode,
        string $stderr,
        KimiStream $stream,
        ?KimiTurnError $wireError,
        ClaudeErrorText $text,
    ): KimiErrorClass {
        // The wire error by its code and name first: the messages of some fixtures carry the words
        // "quota" and "rate limit" themselves, so only the code and the name decide reliably.
        if ($wireError !== null) {
            if ($wireError->code === self::CODE_AUTH) {
                return KimiErrorClass::Auth;
            }
            if ($wireError->code === self::CODE_RATE_LIMIT) {
                return KimiErrorClass::Rate;
            }
            if ($wireError->name === self::NAME_QUOTA_EXHAUSTED) {
                return KimiErrorClass::Quota;
            }
        }

        // A session held by another instance: exit 4 with the error event on stdout and no wire error.
        if ($exitCode === self::EXIT_SESSION_OCCUPIED
            && ($stream->error['code'] ?? null) === self::CODE_SESSION_OCCUPIED
        ) {
            return KimiErrorClass::Occupied;
        }

        if ($wireError !== null) {
            // The code and the name gave no class: the message of the wire error is the last wire
            // signal. stderr is not consulted here — it only repeats "Prompt turn ended with
            // reason: failed", and must not override a classified wire error.
            $class = self::byLimitText($wireError->message, $text);
        } else {
            $class = self::byLimitText($stderr, $text);
        }

        if ($class !== null) {
            return $class;
        }

        return self::byStderr($exitCode, $stderr);
    }

    /**
     * The class of an explicit stderr line: a model alias missing from `config.toml`, an unknown
     * session id, and (for every other exit 1) the generic failure of the run.
     */
    private static function byStderr(int $exitCode, string $stderr): KimiErrorClass
    {
        if (str_contains($stderr, self::MODEL_NOT_CONFIGURED_MARKER)) {
            return KimiErrorClass::ModelNotConfigured;
        }

        if (preg_match(self::SESSION_NOT_FOUND_PATTERN, $stderr) === 1) {
            return KimiErrorClass::SessionNotFound;
        }

        return $exitCode === self::EXIT_FAILURE ? KimiErrorClass::Failed : KimiErrorClass::Unknown;
    }

    /**
     * The class of a limit the text names, as {@see ClaudeErrorText::foreignLimit()} reads it:
     * `rate` for a 429 / "rate limit" text and `quota` for a quota or balance one; null when the
     * text names no limit. The text classification is reused from the Claude error text, not copied.
     */
    private static function byLimitText(?string $body, ClaudeErrorText $text): ?KimiErrorClass
    {
        if ($body === null || $body === '') {
            return null;
        }

        return match ($text->foreignLimit($body)) {
            'rate' => KimiErrorClass::Rate,
            'quota' => KimiErrorClass::Quota,
            default => null,
        };
    }
}
