<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

/**
 * The request to Telegram timed out (curl `CURLE_OPERATION_TIMEDOUT`).
 *
 * A separate type, because the reaction to a timeout differs from the reaction
 * to other transport failures: for the long-poll `getUpdates` a timeout is a
 * normal event and must not make noise in the log, while for sending it means
 * "outcome unknown".
 *
 * Extends {@see TelegramTransportException}, so a `catch` on the parent also
 * catches timeouts; a specialized catch is only needed where the behavior
 * really diverges.
 */
final class TelegramTimeoutException extends TelegramTransportException
{
}
