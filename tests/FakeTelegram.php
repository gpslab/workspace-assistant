<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\EditStatus;
use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\TelegramClient;

/**
 * A recording double of TelegramClient for queue tests: sends nothing over the
 * network, records what was sent and returns a configurable edit status.
 */
final class FakeTelegram extends TelegramClient
{
    /** @var list<array{chat: int, text: string, markup: array<string, mixed>|null, thread: int, id: int, silent: bool}> */
    public array $sent = [];

    /** @var list<array{chat: int, thread: int, name: string}> */
    public array $files = [];

    /** @var list<array{chat: int, message_id: int, text: string, markup: array<string, mixed>|null}> */
    public array $edits = [];

    /** @var list<int> */
    public array $deleted = [];

    /** @var list<string> */
    public array $answers = [];

    /** @var list<int> */
    public array $pinned = [];

    /** Pinning fails with an exception (like Telegram without admin rights). */
    public bool $pinOk = true;

    /** Sending the card (sendPlain) fails, regular sendText replies go through as usual. */
    public bool $plainFails = false;

    /** @var list<array{language_code: string|null, commands: array<array{command: string, description: string}>}> */
    public array $commandLists = [];

    /** @var list<int> getUpdates timeouts, in call order */
    public array $pollTimeouts = [];

    public EditStatus $editStatus = EditStatus::Ok;

    public ?\Throwable $downloadError = null;

    /** A topic where sendText gets a Telegram refusal (as for a deleted topic). */
    public ?int $failThread = null;

    /** @var list<\Throwable> failures thrown by the next sendText calls, in order */
    public array $sendFailures = [];

    /** @var list<int> thread of every sendText call, including failed ones */
    public array $sendAttempts = [];

    /** sendFile fails with a Telegram refusal. */
    public bool $fileFails = false;

    private int $nextId = 1000;

    public function __construct()
    {
        parent::__construct('test-token');
    }

    public function getUpdates(int $offset, int $timeout): array
    {
        $this->pollTimeouts[] = $timeout;

        return [];
    }

    public function sendText(int $chatId, string $text, ?array $replyMarkup = null, int $threadId = 0, ?string $overflowCaption = null, bool $silent = false): int
    {
        $this->sendAttempts[] = $threadId;
        $failure = array_shift($this->sendFailures);
        if ($failure !== null) {
            throw $failure;
        }
        if ($this->failThread !== null && $threadId === $this->failThread) {
            throw new TelegramApiException('sendMessage', 'Bad Request: message thread not found');
        }
        $id = $this->nextId++;
        $this->sent[] = ['chat' => $chatId, 'text' => $text, 'markup' => $replyMarkup, 'thread' => $threadId, 'id' => $id, 'silent' => $silent];

        return $id;
    }

    public function sendPlain(int $chatId, string $text, int $threadId = 0, bool $silent = false): int
    {
        if ($this->plainFails) {
            throw new TelegramApiException('sendMessage', 'Bad Request: chat not found');
        }
        if ($this->failThread !== null && $threadId === $this->failThread) {
            throw new TelegramApiException('sendMessage', 'Bad Request: message thread not found');
        }
        $id = $this->nextId++;
        $this->sent[] = ['chat' => $chatId, 'text' => $text, 'markup' => null, 'thread' => $threadId, 'id' => $id, 'silent' => $silent];

        return $id;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): EditStatus
    {
        $this->edits[] = ['chat' => $chatId, 'message_id' => $messageId, 'text' => $text, 'markup' => $replyMarkup];

        return $this->editStatus;
    }

    public function pinChatMessage(int $chatId, int $messageId): bool
    {
        if (!$this->pinOk) {
            throw new TelegramApiException('pinChatMessage', 'Bad Request: not enough rights to pin a message');
        }
        $this->pinned[] = $messageId;

        return true;
    }

    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $this->commandLists[] = ['language_code' => $languageCode, 'commands' => $commands];
    }

    public function deleteMessage(int $chatId, int $messageId): void
    {
        $this->deleted[] = $messageId;
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->answers[] = $text;
    }

    public function sendFile(int $chatId, string $path, string $caption, string $mimeType, int $threadId = 0, ?string $filename = null): void
    {
        if ($this->fileFails) {
            throw new TelegramApiException('sendDocument', 'Bad Request: boom');
        }
        $this->files[] = ['chat' => $chatId, 'thread' => $threadId, 'name' => $filename ?? basename($path)];
    }

    public function getFilePath(string $fileId): string
    {
        if ($this->downloadError !== null) {
            throw $this->downloadError;
        }

        return 'files/'.$fileId;
    }

    public function downloadFile(string $filePath, string $destPath): void
    {
        file_put_contents($destPath, 'x');
    }

    /**
     * @return list<string> texts of messages sent to the topic
     */
    public function textsTo(int $chatId, int $threadId): array
    {
        $out = [];
        foreach ($this->sent as $m) {
            if ($m['chat'] === $chatId && $m['thread'] === $threadId) {
                $out[] = $m['text'];
            }
        }

        return $out;
    }
}
