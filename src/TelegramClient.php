<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTimeoutException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;

/**
 * Thin Telegram Bot API client on curl. No third-party dependencies.
 *
 * Messages are sent with parse_mode=HTML; MarkdownConverter converts
 * Markdown to HTML before sending.
 *
 * Errors are split by type (see `src/Exception/`): a refusal from Telegram
 * itself and a transport failure are different events, and the calling code
 * must react to them differently. The common ancestor is `\RuntimeException`.
 *
 * Not final (unlike the neighboring classes): `call()` hits the real network
 * with no transport substitution point, so the tests of the `Bot` wiring
 * (see BotTest) double it via `createMock()`, which requires non-final.
 */
class TelegramClient
{
    private const string API = 'https://api.telegram.org/bot';
    private const string FILE_API = 'https://api.telegram.org/file/bot';
    private const int TELEGRAM_MAX = 4096;

    /**
     * Timeout of a regular request. Sending a message takes a fraction of a
     * second; a long wait here does not save delivery, it only delays the
     * failure handling. The getUpdates long-poll sets its own timeout explicitly.
     */
    private const int DEFAULT_TIMEOUT = 30;

    /** Margin on top of the server-side long-poll timeout of getUpdates. */
    private const int POLL_TIMEOUT_MARGIN = 20;

    /** A file upload easily exceeds DEFAULT_TIMEOUT on a slow link. */
    private const int UPLOAD_TIMEOUT = 120;

    /** Bot API file upload limit (sendDocument and the overall ceiling). */
    public const int MAX_FILE_BYTES = 50 * 1024 * 1024;

    /** sendPhoto is limited separately and additionally recompresses the image. */
    public const int MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    /** MIME types that go through sendPhoto rather than sendDocument. */
    private const array IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * The single source of truth for "is this an image or not": used both
     * here to pick the method (sendPhoto/sendDocument) and by the calling code
     * (see Bot::sendOutboxFile) to pick the applicable size limit. If the
     * limit were decided by some other criterion (e.g. the `image/*` prefix),
     * the two would diverge on MIME types like `image/svg+xml`, which are not
     * in IMAGE_MIME_TYPES.
     */
    public static function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::IMAGE_MIME_TYPES, true);
    }

    private readonly MarkdownConverter $markdown;

    public function __construct(private readonly string $token, private readonly Locale $locale = new Locale())
    {
        $this->markdown = new MarkdownConverter();
    }

    /**
     * @param array<string, mixed> $params
     * @param int|null             $timeout request timeout, sec; null — DEFAULT_TIMEOUT
     *
     * @return array<string, mixed>
     *
     * @throws TelegramApiException       Telegram replied with a refusal; the outcome is known
     * @throws TelegramTimeoutException   the request timed out
     * @throws TelegramTransportException any other transport failure
     */
    public function call(string $method, array $params = [], ?int $timeout = null): array
    {
        $ch = curl_init(self::API.$this->token.'/'.$method);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $hasFile = false;
        foreach ($params as $value) {
            if ($value instanceof \CURLFile) {
                $hasFile = true;
                break;
            }
        }

        // Encode non-file values: arrays (reply_markup) become JSON.
        $payload = $params;
        if (!$hasFile) {
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    $payload[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout ?? self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            // The connection was established, so the request went out and Telegram
            // MAY have executed it even if the response never reached us. Zero
            // means no TCP session was made (DNS, connect-timeout) and the request
            // definitely did not leave the machine.
            $requestSent = ((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME)) > 0.0;
            curl_close($ch);

            $message = "Telegram API curl error in {$method}: {$err}";

            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TelegramTimeoutException($message, $errno, $requestSent);
            }

            throw new TelegramTransportException($message, $errno, $requestSent);
        }
        curl_close($ch);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $desc = is_array($decoded) ? ($decoded['description'] ?? 'unknown') : 'invalid response';
            throw new TelegramApiException($method, is_string($desc) ? $desc : 'unknown');
        }

        $resultRaw = $decoded['result'] ?? [];

        // Some methods (deleteMessage, answerCallbackQuery, etc.) return
        // "result": true; normalize to [], the boolean result is not used anywhere.
        if (!is_array($resultRaw)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $resultRaw;

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout): array
    {
        /** @var array<int, array<string, mixed>> $updates */
        $updates = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + self::POLL_TIMEOUT_MARGIN);

        return $updates;
    }

    /**
     * Send text. Long text (>4096) or text with a markdown table is sent as
     * a .md file.
     *
     * $threadId is the forum topic (message_thread_id); 0 = no topic (1:1/General).
     * Needed only for NEW messages: edit/delete address by message_id and do
     * not use the topic.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param string|null               $overflowCaption caption of the file if the reply does not
     *                                                   fit the chat limit by LENGTH and is sent
     *                                                   as .md (see sendMarkdownFile); null is the
     *                                                   default. Modes other than `arch` must pass
     *                                                   an explicit "mode violated" note here,
     *                                                   otherwise packing into a file looks like
     *                                                   normal outbox operation rather than a mode
     *                                                   failure. A short reply with a table is sent
     *                                                   as a file as usual (readability on a
     *                                                   phone) and did not violate the limit, so
     *                                                   the note is NOT applied to it.
     *
     * @return int message_id (0 for a document)
     */
    public function sendText(int $chatId, string $text, ?array $replyMarkup = null, int $threadId = 0, ?string $overflowCaption = null, bool $silent = false): int
    {
        $html = $this->markdown->convert($text);
        $tooLong = mb_strlen($html) > self::TELEGRAM_MAX;

        // A table in <pre> is unreadable on a phone (line wrapping breaks the
        // column alignment), so the whole reply is sent as a .md file regardless
        // of length. The $overflowCaption note applies only if the reply also
        // did not fit by length: a table by itself does not violate the chat limit.
        if ($this->markdown->hasTable($text)) {
            $this->sendMarkdownFile($chatId, $text, ($tooLong ? $overflowCaption : null) ?? $this->locale->t('tg.table_caption'), $threadId);

            return 0;
        }

        if ($tooLong) {
            $this->sendMarkdownFile($chatId, $text, $overflowCaption ?? $this->locale->t('tg.full_caption'), $threadId);

            return 0;
        }

        $params = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML'];
        if ($threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        if ($silent) {
            $params['disable_notification'] = true;
        }

        return (int) ($this->call('sendMessage', $params)['message_id'] ?? 0);
    }

    /**
     * Send text as is (no parse_mode) so that it matches what {@see editMessageText()}
     * writes later. Long text is truncated.
     *
     * @return int message_id
     */
    public function sendPlain(int $chatId, string $text, int $threadId = 0, bool $silent = false): int
    {
        $params = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, self::TELEGRAM_MAX)];
        if ($threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        if ($silent) {
            $params['disable_notification'] = true;
        }

        return (int) ($this->call('sendMessage', $params)['message_id'] ?? 0);
    }

    public function sendMarkdownFile(int $chatId, string $content, ?string $caption = null, int $threadId = 0): void
    {
        $caption ??= $this->locale->t('tg.full_caption');
        $tmp = tempnam(sys_get_temp_dir(), 'tg_') ?: sys_get_temp_dir().'/tg_'.uniqid('', true);
        $file = $tmp.'.md';
        rename($tmp, $file);
        file_put_contents($file, $content);

        try {
            $this->sendFile($chatId, $file, $caption, 'text/markdown', $threadId, 'response.md');
        } finally {
            @unlink($file);
        }
    }

    /**
     * Send an arbitrary file. Images (by MIME) go through sendPhoto,
     * everything else through sendDocument. Size limits (see MAX_FILE_BYTES /
     * MAX_PHOTO_BYTES) are the caller's concern and are not checked here: the
     * client merely executes the request, the outbox policy lives in Bot.
     *
     * $filename is the name under which the recipient sees the file; defaults
     * to basename($path) (for temp files with a random on-disk name it must be
     * given explicitly, see sendMarkdownFile).
     */
    public function sendFile(int $chatId, string $path, string $caption, string $mimeType, int $threadId = 0, ?string $filename = null): void
    {
        $isImage = self::isImageMimeType($mimeType);
        $method = $isImage ? 'sendPhoto' : 'sendDocument';
        $field = $isImage ? 'photo' : 'document';

        $params = [
            'chat_id' => $chatId,
            $field => new \CURLFile($path, $mimeType, $filename ?? basename($path)),
            'caption' => mb_substr($caption, 0, 1000),
        ];
        if ($threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        $this->call($method, $params, self::UPLOAD_TIMEOUT);
    }

    /**
     * The `file_path` of a file by its `file_id` (the `getFile` method). Bot API
     * refuses files larger than 20 MB ("file is too big"); the calling code must
     * recognize this by the {@see TelegramApiException} text and not confuse it
     * with other download failures.
     *
     * @throws TelegramApiException       Telegram refused (including a file > 20 MB)
     * @throws TelegramTimeoutException   the request timed out
     * @throws TelegramTransportException any other transport failure
     */
    public function getFilePath(string $fileId): string
    {
        $result = $this->call('getFile', ['file_id' => $fileId]);
        $path = $result['file_path'] ?? null;

        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('Telegram getFile returned an empty file_path.');
        }

        return $path;
    }

    /**
     * Downloads the file at `$filePath` (from {@see getFilePath}) to `$destPath`.
     * The URL contains the bot token, so only `$filePath` ends up in the error
     * message, never the full URL, to keep the token out of the log/chat.
     */
    public function downloadFile(string $filePath, string $destPath): void
    {
        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open {$destPath} for writing.");
        }

        $ch = curl_init(self::FILE_API.$this->token.'/'.$filePath);
        if ($ch === false) {
            fclose($fp);

            throw new \RuntimeException('curl_init failed for file download');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => self::UPLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $httpCode !== 200) {
            @unlink($destPath);

            throw new \RuntimeException("Failed to download file {$filePath}: ".($err !== '' ? $err : "HTTP {$httpCode}"));
        }
    }

    public function deleteMessage(int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }
        try {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (\RuntimeException) {
            // The message may already be deleted; not critical.
        }
    }

    /**
     * Overwrite the message text (for live progress and editing the /mode
     * keyboard). Plain text, no parse_mode. Errors are not thrown but returned
     * as a status ({@see EditStatus}): the queue needs to know that the
     * message/topic was deleted.
     *
     * @param array<string, mixed>|null $replyMarkup null — leave the keyboard untouched
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): EditStatus
    {
        if ($messageId <= 0) {
            return EditStatus::Gone;
        }
        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ];
            if ($replyMarkup !== null) {
                $params['reply_markup'] = $replyMarkup;
            }
            $this->call('editMessageText', $params);

            return EditStatus::Ok;
        } catch (TelegramApiException $e) {
            return EditStatus::fromApiDescription($e->description);
        } catch (\RuntimeException) {
            return EditStatus::Failed;
        }
    }

    /**
     * Pin a message without a notification. Missing rights is not an operational error: returns false.
     */
    public function pinChatMessage(int $chatId, int $messageId): bool
    {
        if ($messageId <= 0) {
            return false;
        }
        try {
            $this->call('pinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_notification' => true]);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Registers the command list in Telegram (shown when typing "/").
     *
     * @param array<array{command: string, description: string}> $commands
     * @param string|null                                        $languageCode two-letter language code the list applies to; null — the default list
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $params = ['commands' => $commands];
        if ($languageCode !== null) {
            $params['language_code'] = $languageCode;
        }
        $this->call('setMyCommands', $params);
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        try {
            $this->call('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $text,
            ]);
        } catch (\RuntimeException) {
            // An expired callback is ignored.
        }
    }
}
