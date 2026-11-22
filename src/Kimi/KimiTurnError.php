<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The error of a failed Kimi turn, read from the `error` object of a `turn.ended` record of
 * `wire.jsonl` ({@see KimiWireReader::read()}). This is the only place a run's real error is
 * written: stdout carries a bare "Prompt turn ended with reason: failed" on stderr and nothing on
 * stdout.
 *
 * `name` is reported next to `code` because the code alone does not tell the failures apart: a
 * quota 429 and an HTTP 500 share the code `provider.api_error` and differ only by `name`
 * (`APIProviderQuotaExhaustedError` against `APIStatusError`).
 */
final class KimiTurnError
{
    /**
     * @param ?string $code       the error code (`provider.auth_error`, `provider.rate_limit`, `provider.api_error`, …)
     * @param ?string $message    the message Kimi printed for the failure
     * @param ?string $name       the error class name, the only field that separates a quota 429 from an HTTP 500
     * @param bool    $retryable  whether Kimi considered the failed step retryable; a record without a boolean
     *                            `retryable` is reported as not retryable
     * @param ?int    $statusCode the HTTP status of `details.statusCode`
     */
    public function __construct(
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?string $name,
        public readonly bool $retryable,
        public readonly ?int $statusCode,
    ) {
    }
}
