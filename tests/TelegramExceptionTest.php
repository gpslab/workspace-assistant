<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Exception\TelegramException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTimeoutException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The hierarchy matters behaviourally, not decoratively: Delivery::deliver() uses it to decide
 * whether sending can be retried. A mistake in inheritance would quietly bring back the bug of a duplicate
 * reply in two chats, so the relations are pinned by a test.
 */
#[CoversClass(TelegramException::class)]
#[CoversClass(TelegramApiException::class)]
#[CoversClass(TelegramTransportException::class)]
#[CoversClass(TelegramTimeoutException::class)]
final class TelegramExceptionTest extends TestCase
{
    /**
     * Kinship is checked via class_parents(), not assertInstanceOf: the relations are
     * statically obvious, and phpstan rejects such asserts as "always true".
     * They still need checking — the cost of a mistake is behavioural, not a type one.
     *
     * @return list<class-string>
     */
    private static function ancestorsOf(object $e): array
    {
        return array_values(class_parents($e));
    }

    /**
     * The hierarchy was introduced on top of code that caught \RuntimeException in
     * several places (Bot, TelegramClient::deleteMessage etc.). If the ancestor
     * is lost, those catches stop firing — and the bot would fail where
     * it used to degrade gracefully.
     */
    public function testAllTelegramErrorsRemainRuntimeExceptions(): void
    {
        self::assertContains(\RuntimeException::class, self::ancestorsOf(new TelegramApiException('sendMessage', 'boom')));
        self::assertContains(\RuntimeException::class, self::ancestorsOf(new TelegramTransportException('boom', 7, false)));
        self::assertContains(\RuntimeException::class, self::ancestorsOf(new TelegramTimeoutException('boom', 28, true)));
    }

    public function testTimeoutIsATransportFailure(): void
    {
        self::assertContains(TelegramTransportException::class, self::ancestorsOf(new TelegramTimeoutException('boom', 28, true)));
    }

    /**
     * A Telegram refusal and a connection drop must not be caught by one catch: for the first
     * a fallback to the chat root is acceptable, for the second it is not.
     */
    public function testApiFailureIsNotATransportFailure(): void
    {
        self::assertNotContains(TelegramTransportException::class, self::ancestorsOf(new TelegramApiException('sendMessage', 'boom')));
    }

    public function testApiFailureCarriesMethodAndDescription(): void
    {
        $e = new TelegramApiException('sendMessage', 'message thread not found');

        self::assertSame('sendMessage', $e->method);
        self::assertSame('message thread not found', $e->description);
        self::assertSame('Telegram API error in sendMessage: message thread not found', $e->getMessage());
    }

    public function testTransportFailureCarriesDeliveryVerdict(): void
    {
        $unsent = new TelegramTransportException('could not resolve host', 6, false);
        $unknown = new TelegramTimeoutException('operation timed out', 28, true);

        self::assertFalse($unsent->requestSent, 'the request was not sent — a retry is safe');
        self::assertSame(6, $unsent->curlErrno);

        self::assertTrue($unknown->requestSent, 'the request was sent — the outcome is unknown, do not retry');
        self::assertSame(28, $unknown->curlErrno);
    }
}
