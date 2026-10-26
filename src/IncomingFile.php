<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Parses an incoming Telegram update for a file: no network, pure
 * functions over the `message` array (unit-testable).
 *
 * Accepted: `photo` (the size with the largest area is taken; Telegram sends
 * an array of downscaled copies, all JPEG), `document` with any MIME, as well
 * as `audio`, `video`, `voice`, `video_note` and `animation` (Telegram
 * duplicates animation in `document`: it is one file, taken once). `sticker`
 * is not considered a file.
 *
 * The on-disk file name is built from the sender's `file_name`, so it is
 * sanitized (see diskName); otherwise `../` would take the file out of the
 * inbox. The extension of images is derived from the MIME type, not from
 * `file_name`: Claude's Read decides "image or not" by extension, and a
 * spoofed `.png` on a non-image breaks reading. For other types the extension
 * is taken from `file_name` (Telegram often gives `application/octet-stream`
 * for .yaml/.md/.json).
 */
final class IncomingFile
{
    /** Maximum length of the on-disk name base (without the extension). */
    private const int MAX_BASE_LENGTH = 80;

    /** Image MIME type → extension; the extension from file_name is discarded for them. */
    private const array IMAGE_MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Small MIME type → extension map for non-images (rule 3). */
    private const array OTHER_MIME_EXTENSIONS = [
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
    ];

    /**
     * Extracts the file description from a message. Returns null if the
     * message has no accepted file (text, sticker, etc.).
     *
     * @param array<string, mixed> $message
     *
     * @return array{file_id: string, extension: string|null, name: string|null, file_size: int|null}|null
     */
    public static function extractFrom(array $message): ?array
    {
        $photo = $message['photo'] ?? null;
        if (is_array($photo) && $photo !== []) {
            $best = self::largestPhoto($photo);

            // Telegram re-encodes all photo sizes to JPEG on its side.
            return $best !== null ? self::build($best, null, true) : null;
        }

        // animation is duplicated in document: the same file.
        foreach (['document', 'animation', 'audio', 'video', 'voice', 'video_note'] as $type) {
            $media = $message[$type] ?? null;
            if (is_array($media) && (string) ($media['file_id'] ?? '') !== '') {
                $fallback = match ($type) {
                    'voice' => 'ogg',
                    'video_note' => 'mp4',
                    default => null,
                };

                return self::build($media, $fallback, false);
            }
        }

        return null;
    }

    /**
     * True if the message is a sticker (the bot replies "not supported" to it).
     *
     * @param array<string, mixed> $message
     */
    public static function isSticker(array $message): bool
    {
        return isset($message['sticker']);
    }

    /**
     * On-disk file name: `<prefix>-<base>.<ext>`; without a name, `<prefix>.<ext>`;
     * without an extension, no trailing dot. `$prefix` is a random hex from the caller.
     *
     * @param array{file_id: string, extension: string|null, name: string|null, file_size: int|null} $file
     */
    public static function diskName(array $file, string $prefix): string
    {
        $base = self::splitName($file['name'])['base'];
        $base = rtrim(substr($base, 0, self::MAX_BASE_LENGTH), '.');

        $name = $prefix.($base !== '' ? '-'.$base : '');
        $ext = $file['extension'];

        return $ext !== null && $ext !== '' ? $name.'.'.$ext : $name;
    }

    /**
     * @param array<mixed, mixed> $media
     *
     * @return array{file_id: string, extension: string|null, name: string|null, file_size: int|null}
     */
    private static function build(array $media, ?string $fallbackExtension, bool $isPhoto): array
    {
        $mime = strtolower((string) ($media['mime_type'] ?? ''));
        $name = isset($media['file_name']) && is_string($media['file_name']) && $media['file_name'] !== ''
            ? $media['file_name']
            : null;

        if ($isPhoto) {
            $extension = 'jpg';
        } elseif (isset(self::IMAGE_MIME_EXTENSIONS[$mime])) {
            $extension = self::IMAGE_MIME_EXTENSIONS[$mime];
        } else {
            $nameExt = self::splitName($name)['ext'];
            // An image extension on a non-image (by MIME) is a spoof: Read would fail.
            if ($nameExt !== null && in_array($nameExt, [...array_values(self::IMAGE_MIME_EXTENSIONS), 'jpeg'], true)) {
                $nameExt = null;
            }
            $extension = $nameExt
                ?? self::OTHER_MIME_EXTENSIONS[$mime]
                ?? $fallbackExtension;
        }

        return [
            'file_id' => (string) $media['file_id'],
            'extension' => $extension,
            'name' => $name,
            'file_size' => isset($media['file_size']) ? (int) $media['file_size'] : null,
        ];
    }

    /**
     * Sanitized name split into base and extension. The extension is
     * `[a-z0-9]{1,10}` after the last dot, otherwise null (then the whole
     * string is the base). The base has no leading/trailing dots; an empty
     * base means "no name".
     *
     * @return array{base: string, ext: string|null}
     */
    private static function splitName(?string $name): array
    {
        if ($name === null) {
            return ['base' => '', 'ext' => null];
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        $ext = null;
        $base = $name;
        $dot = strrpos($name, '.');
        if ($dot !== false) {
            $candidate = strtolower(substr($name, $dot + 1));
            if (preg_match('/^[a-z0-9]{1,10}$/', $candidate) === 1) {
                $ext = $candidate;
                $base = substr($name, 0, $dot);
            }
        }

        return ['base' => trim($base, '.'), 'ext' => $ext];
    }

    /**
     * The size with the largest area (width × height) from the `photo` array.
     * Area rather than position: Telegram usually sends ascending order, but
     * the order is not documented as a guarantee.
     *
     * @param array<mixed, mixed> $sizes
     *
     * @return array<mixed, mixed>|null
     */
    private static function largestPhoto(array $sizes): ?array
    {
        $best = null;
        $bestArea = -1;

        foreach ($sizes as $size) {
            if (!is_array($size) || !isset($size['file_id'])) {
                continue;
            }
            $area = ((int) ($size['width'] ?? 0)) * ((int) ($size['height'] ?? 0));
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $size;
            }
        }

        return $best;
    }

    /**
     * The message's `media_group_id` (album) or null for a single message.
     *
     * @param array<string, mixed> $message
     */
    public static function mediaGroupId(array $message): ?string
    {
        $id = $message['media_group_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The message's `caption`, trimmed and normalized to null when empty, so
     * the caller does not have to check `isset`+`is_string`+`trim() !== ''`
     * three times.
     *
     * @param array<string, mixed> $message
     */
    public static function captionOf(array $message): ?string
    {
        $caption = $message['caption'] ?? null;
        $trimmed = is_string($caption) ? trim($caption) : '';

        return $trimmed !== '' ? $trimmed : null;
    }
}
