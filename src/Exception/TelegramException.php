<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

/**
 * Base for all Telegram Bot API failures.
 *
 * Extends \RuntimeException on purpose: before this hierarchy existed the
 * client threw exactly that, and calling code catches `\RuntimeException` in
 * several places. Existing catch blocks keep working unchanged.
 *
 * The subclasses need to be told apart where the decision depends on whether
 * the request REACHED Telegram:
 * - {@see TelegramApiException}: Telegram answered "no", the outcome is known;
 * - {@see TelegramTransportException}: there was no response, the outcome is unknown;
 * - {@see TelegramTimeoutException}: a special case of a transport failure.
 */
class TelegramException extends \RuntimeException
{
}
