<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Album buffer (a group of files sent together, `media_group_id`). Telegram
 * sends an album as N separate updates sharing a `media_group_id` (the caption is on
 * one of them; order and placement are not guaranteed), so files accumulate here
 * until `$debounceSec` passes with no new files of the same group; then the group
 * is considered "ripe" and handed to the caller as a single batch.
 *
 * Time is supplied from outside (`$now`) rather than taken via `time()` inside, so the class
 * stays a pure state function and is unit-tested without real waiting
 * and without the network. The caller (`Bot::tick`) must call `collectReady` on
 * every polling tick; there is no separate timer (the bot is single-threaded).
 */
final class AlbumBuffer
{
    /**
     * @var array<string, array{
     *     chat_id: int,
     *     thread_id: int,
     *     user_id: int,
     *     caption: string|null,
     *     files: list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}>,
     *     message_ids: list<int>,
     *     last_seen_at: int,
     * }>
     */
    private array $groups = [];

    /**
     * Adds a file to the `$mediaGroupId` group, creating it on the first file.
     * The caption is updated only if the file has one, so a caption from
     * any file of the group (not just the first to arrive) is not lost.
     *
     * @param array{file_id: string, extension: string|null, name: string|null, file_size: int|null} $file
     */
    public function add(string $mediaGroupId, int $chatId, int $threadId, ?string $caption, array $file, int $now, int $messageId = 0, int $userId = 0): void
    {
        if (!isset($this->groups[$mediaGroupId])) {
            $this->groups[$mediaGroupId] = [
                'chat_id' => $chatId,
                'thread_id' => $threadId,
                'user_id' => $userId,
                'caption' => $caption,
                'files' => [],
                'message_ids' => [],
                'last_seen_at' => $now,
            ];
        }

        $this->groups[$mediaGroupId]['files'][] = $file;
        $this->groups[$mediaGroupId]['message_ids'][] = $messageId;
        $this->groups[$mediaGroupId]['last_seen_at'] = $now;
        if ($caption !== null) {
            $this->groups[$mediaGroupId]['caption'] = $caption;
        }
    }

    /**
     * Removes and returns all groups that have received no new files for at least
     * `$debounceSec` seconds as of `$now`. Returned groups are removed from
     * the buffer; a repeated call will not return them again.
     *
     * @return list<array{
     *     chat_id: int,
     *     thread_id: int,
     *     user_id: int,
     *     caption: string|null,
     *     files: list<array{file_id: string, extension: string|null, name: string|null, file_size: int|null}>,
     *     message_ids: list<int>,
     * }>
     */
    public function collectReady(int $now, float $debounceSec): array
    {
        $ready = [];

        foreach ($this->groups as $mediaGroupId => $group) {
            if (($now - $group['last_seen_at']) < $debounceSec) {
                continue;
            }

            $ready[] = [
                'chat_id' => $group['chat_id'],
                'thread_id' => $group['thread_id'],
                'user_id' => $group['user_id'],
                'caption' => $group['caption'],
                'files' => $group['files'],
                'message_ids' => $group['message_ids'],
            ];
            unset($this->groups[$mediaGroupId]);
        }

        return $ready;
    }

    /**
     * True if the buffer has no incomplete group. Used by the
     * caller (`Bot::tick`) to avoid entering a long `getUpdates` long-poll
     * while album files are waiting for debounce; otherwise a ripe
     * group would only be discovered after the current long-poll.
     */
    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
