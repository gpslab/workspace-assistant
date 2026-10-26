<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Incoming files and the prompts built from them: downloading into the inbox
 * directory, the file/queue prompts, and cleanup of inbox files.
 */
final class Inbox
{
    /** Inbox files older than this are swept as orphans (see sweepInbox). */
    private const int INBOX_MAX_AGE_SEC = 3600;

    public function __construct(
        private readonly TelegramClient $tg,
        private readonly Config $config,
        private readonly Locale $locale,
    ) {
    }

    /**
     * @param array<string, mixed> $part
     *
     * @return list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}>
     */
    public static function partFiles(array $part): array
    {
        /** @var list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}> $files */
        $files = $part['files'] ?? [];

        return $files;
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return list<string>
     */
    public function fileNames(array $parts): array
    {
        $names = [];
        foreach ($parts as $part) {
            foreach (self::partFiles($part) as $file) {
                $names[] = $file['name'] ?? $this->locale->t('file.unnamed');
            }
        }

        return $names;
    }

    /**
     * Item prompt: the messages in order; files carry the caption of their own
     * message. A single file item without merging is built as before
     * ({@see buildFilePrompt()}).
     *
     * @param list<array<string, mixed>> $parts
     * @param array<int, list<string>>   $pathsByPart downloaded paths by part index
     */
    public static function buildQueuePrompt(array $parts, array $pathsByPart): string
    {
        if (count($parts) === 1) {
            $files = self::partFiles($parts[0]);
            $text = is_string($parts[0]['text'] ?? null) ? $parts[0]['text'] : null;
            if ($files === []) {
                return (string) $text;
            }

            return self::buildFilePrompt($text, $pathsByPart[0] ?? [], array_column($files, 'name'));
        }

        $blocks = [];
        $hasText = false;
        foreach ($parts as $i => $part) {
            $lines = [];
            if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                $lines[] = $part['text'];
                $hasText = true;
            }
            $paths = $pathsByPart[$i] ?? [];
            if ($paths !== []) {
                if ($lines !== []) {
                    $lines[] = '';
                }
                $lines[] = count($paths) === 1 ? 'File:' : 'Files:';
                $names = array_column(self::partFiles($part), 'name');
                foreach ($paths as $j => $path) {
                    $lines[] = self::fileLine($path, $names[$j] ?? null);
                }
            }
            $blocks[] = implode("\n", $lines);
        }

        $prompt = implode("\n\n", $blocks);

        return $hasText ? $prompt : PromptFiles::load('default_file_prompt')."\n\n".$prompt;
    }

    public static function fileLine(string $path, mixed $name): string
    {
        return is_string($name) && $name !== '' ? $path.' (original name: '.$name.')' : $path;
    }

    /**
     * Downloads a single file into INBOX_DIR under a safe name (random
     * prefix + sanitized `file_name`, see IncomingFile::diskName).
     * Returns the absolute path.
     *
     * @param array{file_id: string, extension: string|null, name: string|null, file_size: int|null} $file
     */
    public function downloadIncomingFile(array $file): string
    {
        $filePath = $this->tg->getFilePath($file['file_id']);
        $dest = $this->config->inboxDir.'/'.IncomingFile::diskName($file, bin2hex(random_bytes(8)));
        $this->tg->downloadFile($filePath, $dest);

        return $dest;
    }

    /**
     * Final download refusal: explicit cases only; 429/5xx and network
     * failures are transient (the item stays at the head, retry).
     */
    public static function isPermanentDownloadError(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        foreach (['file is too big', 'wrong file_id', 'wrong file id', 'invalid file_id', 'invalid file id', 'file not found', 'wrong file identifier'] as $marker) {
            if (str_contains($m, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download failure message. The Bot API getFile limit is 20 MB; Telegram
     * rejects such a file with a dedicated error ("file is too big"), which we
     * recognize by its text and turn into a readable wording instead of the raw
     * API error text.
     */
    public function formatDownloadError(\Throwable $e): string
    {
        if (stripos($e->getMessage(), 'file is too big') !== false) {
            return $this->locale->t('file.download_too_big');
        }

        return $this->locale->t('file.download_failed', ['error' => $e->getMessage()]);
    }

    /**
     * Prompt for a task with file(s): the caption (or the default) + absolute
     * paths of the downloaded files (with the original name, if any). Everything
     * is assembled into ONE PHP string (no shell involved) and passed on as a
     * single argv element, like any other prompt (see ClaudeRunner::start),
     * so injection via the caption/file name is ruled out.
     *
     * @param list<string>      $paths
     * @param array<int, mixed> $names original names in the same order as $paths
     */
    public static function buildFilePrompt(?string $caption, array $paths, array $names = []): string
    {
        $lines = [$caption ?? PromptFiles::load('default_file_prompt'), ''];
        $lines[] = count($paths) === 1 ? 'File:' : 'Files:';
        foreach ($paths as $i => $path) {
            $lines[] = self::fileLine($path, $names[$i] ?? null);
        }

        return implode("\n", $lines);
    }

    /**
     * Best-effort cleanup of old files in INBOX_DIR (orphans left after a crash
     * in the middle of a previous run; nothing persists between turns). Called
     * at the start of every task with a file; there is no separate timer/cron.
     */
    public function sweepInbox(): void
    {
        $threshold = time() - self::INBOX_MAX_AGE_SEC;
        foreach (glob($this->config->inboxDir.'/*') ?: [] as $path) {
            if (is_file($path) && (filemtime($path) ?: 0) < $threshold) {
                @unlink($path);
            }
        }
    }

    /**
     * @param list<string> $paths
     */
    public function deleteInboxFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
