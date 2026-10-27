<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Queue;

use GpsLab\WorkspaceAssistant\Locale;

/** Texts about queue items and their topics, shared by `Bot` and the commands. */
final readonly class QueueTexts
{
    public const string ITEM_NEW = 'new';
    public const string ITEM_COMPACT = 'compact';
    public const string ITEM_EPIC = 'epic';

    public function __construct(private Locale $locale)
    {
    }

    public function topicLabel(int $chatId, int $threadId): string
    {
        return $threadId === 0
            ? $this->locale->t('topic.general', ['chat' => $chatId])
            : $this->locale->t('topic.thread', ['thread' => $threadId, 'chat' => $chatId]);
    }

    /**
     * @param array{kind:string, parts:list<array<string, mixed>>} $item
     */
    public function describeItem(array $item): string
    {
        if ($item['kind'] === self::ITEM_NEW) {
            return '/new';
        }
        if ($item['kind'] === self::ITEM_COMPACT) {
            return '/compact';
        }
        if ($item['kind'] === self::ITEM_EPIC) {
            return $this->locale->t('queue.epic_run', ['slug' => (string) ($item['parts'][0]['slug'] ?? ''), 'run' => (string) ($item['parts'][0]['run'] ?? '')]);
        }

        foreach ($item['parts'] as $part) {
            $text = $part['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return '«'.mb_substr(trim(strtok($text, "\n") ?: $text), 0, 40).'»'.(count($item['parts']) > 1 ? ' (+'.(count($item['parts']) - 1).')' : '');
            }
        }

        return $this->locale->t('queue.files');
    }
}
