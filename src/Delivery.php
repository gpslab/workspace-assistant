<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;

/**
 * Sending replies to Telegram (with the topic fallback) and the outbox: files Claude
 * leaves in the outbox directory during a run.
 */
final class Delivery
{
    /** Budget of the change summary in a message with buttons (characters after escaping) — leaves headroom under the Telegram 4096 limit. */
    private const int SUMMARY_HTML_BUDGET = 2500;
    /** Cap on outbox files per run — otherwise a hundred files turn into a hundred messages in a row. */
    private const int OUTBOX_MAX_FILES = 10;

    /** Extension → MIME for outbox files so that images go out via sendPhoto. */
    private const array OUTBOX_IMAGE_MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly TelegramClient $tg,
        private readonly Config $config,
        private readonly Locale $locale,
    ) {
    }

    /**
     * Send to a topic with a fallback to the chat root. The topic could have been
     * closed/deleted while the job was running — then sendMessage with
     * message_thread_id fails and the reply (already paid for by Claude's work,
     * the commit pushed) would be lost silently. A fallback to the chat root
     * (General/1:1) preserves the reply. For thread_id = 0 there is no fallback
     * — a failure of the root chat means the chat itself is unavailable.
     *
     * The fallback is allowed ONLY when it is known for certain that the message
     * was not created. Otherwise a duplicate appears in two places, and worse — in
     * two DIFFERENT Claude sessions (the topic and the chat root are keyed
     * separately), i.e. the dialog splits. Exactly this happened on 2026-08-22:
     * the old code took a 120-second timeout waiting for Telegram's response as
     * non-delivery, although the message was already in the topic. So the
     * decision is made by the exception type, not by the mere fact of one:
     *
     * - {@see TelegramApiException} — Telegram answered with a refusal, there is
     *   no message → fallback to the chat root;
     * - a transport failure with `requestSent = false` — the request did not go
     *   out, there is no message → retry into the SAME topic (the recipient does
     *   not change, no split);
     * - a transport failure with `requestSent = true` (including a timeout) — the
     *   outcome is unknown → do not forward anywhere, write the text to the log
     *   and rethrow.
     *
     * Returns the thread_id where the message ACTUALLY landed (0 on fallback).
     * Whoever binds subsequent state to this message (the commit/discard decision
     * record) MUST key by the returned topic, not the requested one: otherwise a
     * callback from the fallback topic will not match the decision record.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param string|null               $overflowCaption see {@see TelegramClient::sendText()}
     */
    public function deliver(int $chatId, int $threadId, string $text, ?array $replyMarkup = null, ?string $overflowCaption = null): int
    {
        return $this->deliverMessage($chatId, $threadId, $text, $replyMarkup, $overflowCaption)['thread_id'];
    }

    /**
     * Same as {@see deliver()}, but additionally returns the message_id of the
     * sent message (needed to reconcile the decision buttons).
     *
     * @param array<string, mixed>|null $replyMarkup
     *
     * @return array{thread_id: int, message_id: int}
     */
    public function deliverMessage(int $chatId, int $threadId, string $text, ?array $replyMarkup = null, ?string $overflowCaption = null): array
    {
        try {
            $messageId = $this->tg->sendText($chatId, $text, $replyMarkup, $threadId, $overflowCaption);

            return ['thread_id' => $threadId, 'message_id' => $messageId];
        } catch (TelegramTransportException $e) {
            if ($e->requestSent) {
                // The reply may have already reached the recipient. We must not duplicate,
                // but must not silently lose the text either — put it in the log, from where we can fetch it by hand.
                Log::line('delivery to topic '.$threadId.' with unknown outcome ('.$e->getMessage().'), not retrying; reply text: '.$text);

                throw $e;
            }

            // The request did not leave the machine — there is no message, retry is safe.
            Log::line('delivery request to topic '.$threadId.' did not go out ('.$e->getMessage().'), retrying into the same topic');
            $messageId = $this->tg->sendText($chatId, $text, $replyMarkup, $threadId, $overflowCaption);

            return ['thread_id' => $threadId, 'message_id' => $messageId];
        } catch (TelegramApiException $e) {
            if ($threadId === 0) {
                throw $e;
            }
            Log::line('delivery to topic '.$threadId.' rejected by Telegram ('.$e->getMessage().'), fallback to chat root');
            $messageId = $this->tg->sendText($chatId, $text, $replyMarkup, 0, $overflowCaption);

            return ['thread_id' => 0, 'message_id' => $messageId];
        }
    }

    public function safeDeliver(int $chatId, int $threadId, string $text): void
    {
        try {
            $this->deliver($chatId, $threadId, $text);
        } catch (\Throwable $e) {
            Log::line('failed to send message: '.$e->getMessage());
        }
    }

    /** Deletes the outbox files: after /cancel and before every launchJob they belong to someone else. */
    public function clearOutbox(): void
    {
        foreach (glob($this->config->outboxDir.'/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Caption for the .md file when the final reply does not fit the chat length
     * limit (see TelegramClient::sendText; a short reply with a table goes out as
     * a file normally and gets no caption). In arch it is null (default caption):
     * packing into a file is normal there. Outside arch "up to three lines" is a
     * prompt goal, not a code contract, but violating it must be visible —
     * otherwise packing into a file looks like regular outbox work rather than
     * a mode failure.
     */
    public function overflowCaption(string $mode): ?string
    {
        return $mode === SystemPrompt::MODE_ARCH
            ? null
            : $this->locale->t('overflow.caption', ['mode' => $mode]);
    }

    /**
     * Summary for the message with buttons: by whole lines, so that after HTML
     * conversion (escaping &, <, > inflates the text) the message is guaranteed
     * to fit the Telegram limit.
     */
    public function truncateSummary(string $summary): string
    {
        $budget = self::SUMMARY_HTML_BUDGET;
        $out = [];
        $used = 0;
        $lines = explode("\n", $summary);
        foreach ($lines as $i => $line) {
            $cost = mb_strlen(htmlspecialchars($line)) + 1;
            if ($used + $cost > $budget) {
                $out[] = $this->locale->t('decision.more_lines', ['count' => count($lines) - $i]);

                return implode("\n", $out);
            }
            $out[] = $line;
            $used += $cost;
        }

        return $summary;
    }

    /**
     * Processes the outbox directory (<STATE_DIR>/outbox): Claude puts files there
     * (e.g. screenshots) during a run, the bot sends them to the same chat and
     * topic as the text reply — by file name, as separate messages before the
     * text. The path is fixed: files outside outbox are not sent, paths from the
     * reply text are not used.
     *
     * The directory is always cleared, including files skipped due to the size or
     * count limit and files whose sending failed — otherwise the next run would
     * send them again. An error on one file does not interrupt processing of the
     * rest and does not prevent the subsequent text delivery (the caller invokes
     * flushOutbox BEFORE deliver()).
     *
     * The whole method is wrapped in try/catch: it is called AFTER
     * finishJobState(), so the run result lives only in the caller's local
     * variable — an unhandled exception from here would bubble up to the run()
     * loop and take the text reply with it (already paid for by Claude's work,
     * the commit already pushed) without a single chance to retry. The summary is
     * also sent via deliver(), not directly via sendText() — so that a
     * deleted/closed forum topic does not silently lose it (see deliver()).
     */
    public function flushOutbox(int $chatId, int $threadId): void
    {
        try {
            $files = glob($this->config->outboxDir.'/*') ?: [];
            $files = array_values(array_filter($files, 'is_file'));
            sort($files, SORT_STRING);

            if ($files === []) {
                return;
            }

            $toSend = array_slice($files, 0, self::OUTBOX_MAX_FILES);
            $overLimit = array_slice($files, self::OUTBOX_MAX_FILES);

            $notes = [];
            foreach ($toSend as $path) {
                $note = $this->sendOutboxFile($chatId, $threadId, $path);
                if ($note !== null) {
                    $notes[] = $note;
                }
            }

            foreach ($overLimit as $path) {
                @unlink($path);
            }
            if ($overLimit !== []) {
                $notes[] = $this->locale->t('outbox.over_limit', ['count' => count($overLimit), 'limit' => self::OUTBOX_MAX_FILES]);
            }

            if ($notes !== []) {
                $this->deliver($chatId, $threadId, $this->locale->t('outbox.notes_header')."\n".implode("\n", $notes));
            }
        } catch (\Throwable $e) {
            Log::line('outbox: directory processing failed: '.$e->getMessage());
        }
    }

    /**
     * Sends one outbox file and deletes it regardless of the outcome. Returns a
     * note for the summary (size exceeded / send failure) or null if everything
     * went smoothly.
     */
    private function sendOutboxFile(int $chatId, int $threadId, string $path): ?string
    {
        $name = basename($path);

        try {
            $mimeType = $this->resolveOutboxMimeType($path);
            $limit = TelegramClient::isImageMimeType($mimeType) ? TelegramClient::MAX_PHOTO_BYTES : TelegramClient::MAX_FILE_BYTES;
            $size = (int) (filesize($path) ?: 0);

            if ($size > $limit) {
                return $this->locale->t('outbox.too_big', ['name' => $name, 'size' => sprintf('%.1f', $size / 1_048_576), 'limit' => intdiv($limit, 1_048_576)]);
            }

            $this->tg->sendFile($chatId, $path, $name, $mimeType, $threadId);

            return null;
        } catch (\Throwable $e) {
            Log::line("outbox: failed to send {$name}: ".$e->getMessage());

            return $this->locale->t('outbox.send_failed', ['name' => $name, 'error' => $e->getMessage()]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * MIME by file extension. Known image formats are sent as sendPhoto;
     * everything else (including an unknown extension) as sendDocument.
     */
    private function resolveOutboxMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset(self::OUTBOX_IMAGE_MIME_TYPES[$ext])) {
            return self::OUTBOX_IMAGE_MIME_TYPES[$ext];
        }

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }
}
