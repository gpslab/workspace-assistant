<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\EditStatus;
use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Choice of the caption for an .md file in sendText(): the "mode violated" mark
 * ($overflowCaption) — only on length overflow, not for the mere fact of a
 * table. The network is replaced: call()/sendFile() are mocked, the real code is only the
 * sendText() branching.
 */
#[CoversClass(TelegramClient::class)]
final class TelegramClientTest extends TestCase
{
    private const string VIOLATION = '⚠️ Mode review was violated — the reply did not fit the chat limit';

    private const string TABLE = "| a | b |\n|---|---|\n| 1 | 2 |";

    public function testShortTableGoesAsFileWithoutViolationCaption(): void
    {
        // Deliberately Cyrillic: proves the passed locale is used (en is the built-in default)
        self::assertSame('Ответ с таблицей', $this->captionFor("Verdict\n\n".self::TABLE, self::VIOLATION, 'ru'));
    }

    public function testDefaultCaptionsAreEnglish(): void
    {
        self::assertSame('Answer with a table', $this->captionFor("Verdict\n\n".self::TABLE, self::VIOLATION, null));
        self::assertSame('Full answer', $this->captionFor(str_repeat('x', 5000), null, null));
    }

    public function testLongTableKeepsViolationCaption(): void
    {
        self::assertSame(self::VIOLATION, $this->captionFor(str_repeat('x', 5000)."\n\n".self::TABLE, self::VIOLATION));
    }

    public function testLongTextKeepsViolationCaption(): void
    {
        self::assertSame(self::VIOLATION, $this->captionFor(str_repeat('x', 5000), self::VIOLATION));
    }

    public function testLongTextWithoutOverrideUsesDefaultCaption(): void
    {
        self::assertSame('Full answer', $this->captionFor(str_repeat('x', 5000), null));
    }

    public function testShortTextGoesAsMessage(): void
    {
        $tg = $this->getMockBuilder(TelegramClient::class)
            ->setConstructorArgs(['token'])
            ->onlyMethods(['call', 'sendFile'])
            ->getMock();
        $tg->expects(self::never())->method('sendFile');
        $tg->expects(self::once())->method('call')->with('sendMessage')->willReturn(['message_id' => 42]);

        self::assertSame(42, $tg->sendText(1, 'short', null, 0, self::VIOLATION));
    }

    public function testPinChatMessageTurnsApiRefusalIntoFalse(): void
    {
        $tg = $this->getMockBuilder(TelegramClient::class)
            ->setConstructorArgs(['token'])
            ->onlyMethods(['call'])
            ->getMock();
        $tg->method('call')->willThrowException(new TelegramApiException('pinChatMessage', 'Bad Request: not enough rights to pin a message'));

        self::assertFalse($tg->pinChatMessage(1, 42));
    }

    private function captionFor(string $text, ?string $overflowCaption, ?string $locale = 'en'): string
    {
        $caption = null;
        $tg = $this->getMockBuilder(TelegramClient::class)
            ->setConstructorArgs($locale === null ? ['token'] : ['token', new Locale($locale)])
            ->onlyMethods(['call', 'sendFile'])
            ->getMock();
        $tg->expects(self::never())->method('call');
        $tg->expects(self::once())->method('sendFile')
            ->willReturnCallback(function (int $chatId, string $path, string $c) use (&$caption): void {
                $caption = $c;
            });

        self::assertSame(0, $tg->sendText(1, $text, null, 0, $overflowCaption));

        return (string) $caption;
    }

    public function testEditMessageTextReturnsStatusFromApiError(): void
    {
        $make = static fn (?string $description): TelegramClient => new class($description) extends TelegramClient {
            public function __construct(private readonly ?string $description)
            {
                parent::__construct('t');
            }

            public function call(string $method, array $params = [], ?int $timeout = null): array
            {
                if ($this->description === null) {
                    return [];
                }

                throw new TelegramApiException($method, $this->description);
            }
        };

        self::assertSame(EditStatus::Ok, $make(null)->editMessageText(1, 2, 'x'));
        self::assertSame(EditStatus::Ok, $make('Bad Request: message is not modified')->editMessageText(1, 2, 'x'));
        self::assertSame(EditStatus::Gone, $make('Bad Request: message to edit not found')->editMessageText(1, 2, 'x'));
        self::assertSame(EditStatus::Gone, $make('Bad Request: message thread not found')->editMessageText(1, 2, 'x'));
        self::assertSame(EditStatus::Failed, $make('Too Many Requests: retry after 5')->editMessageText(1, 2, 'x'));
    }

    public function testEditStatusRecognisesDeletedTopicMarkers(): void
    {
        self::assertSame(EditStatus::Gone, EditStatus::fromApiDescription('Bad Request: TOPIC_DELETED'));
        self::assertSame(EditStatus::Gone, EditStatus::fromApiDescription('Bad Request: message_thread_not_found'));
        self::assertSame(EditStatus::Failed, EditStatus::fromApiDescription('boom'));
    }
}
