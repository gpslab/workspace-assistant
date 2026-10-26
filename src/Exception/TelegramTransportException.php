<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

/**
 * There was no response from Telegram at all: a curl-level failure (DNS, TCP,
 * TLS, connection drop).
 *
 * The outcome is UNKNOWN in general, and {@see $requestSent} tells the cases apart:
 *
 * - `false`: the connection was not established, the request never left the
 *   machine. The message was definitely not created, retrying is safe.
 * - `true`: the request went out, no response came back. Telegram MAY have
 *   executed it. Do not retry: that would produce a duplicate.
 *
 * The second case is what duplicated a reply into both the topic and the chat
 * root on 2026-08-22: the old code treated any failure as "not delivered".
 */
class TelegramTransportException extends TelegramException
{
    /**
     * @param bool $requestSent the connection was established and the request sent,
     *                          so Telegram could have executed it
     */
    public function __construct(
        string $message,
        public readonly int $curlErrno,
        public readonly bool $requestSent,
    ) {
        parent::__construct($message);
    }
}
