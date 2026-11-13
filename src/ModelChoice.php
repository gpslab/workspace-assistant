<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Which Claude model a run gets (`claude -p --model`). Two stored levels, topic then all topics;
 * "nothing" means no flag and the CLI uses its own setting. Only values of CLAUDE_MODELS apply;
 * a stored value outside the list is skipped with a log line. A `--model` in CLAUDE_EXTRA_ARGS
 * pins the model for the whole instance: the bot resolves nothing then.
 */
final readonly class ModelChoice
{
    /** The keyword that clears a level, in text and in callback_data. */
    public const string VALUE_DEFAULT = 'default';

    public const string LEVEL_TOPIC = 'topic';
    public const string LEVEL_ALL = 'all';
    public const string LEVEL_DEFAULT = 'default';

    public function __construct(private Config $config, private Database $db)
    {
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->config->claudeModels;
    }

    /** Canonical list value for a user-typed name (case-insensitive), null when it is not in the list. */
    public function match(string $name): ?string
    {
        foreach ($this->config->claudeModels as $model) {
            if (strcasecmp($model, $name) === 0) {
                return $model;
            }
        }

        return null;
    }

    public function isAllowed(string $model): bool
    {
        return in_array($model, $this->config->claudeModels, true);
    }

    /** The model `--model` in CLAUDE_EXTRA_ARGS sets ('' when the flag has no value); null when it is absent. */
    public function pinnedByExtraArgs(): ?string
    {
        return ClaudeRunner::modelFromArgs($this->config->claudeExtraArgs);
    }

    /**
     * What a new queue item pins: topic model, else the model for all topics, else null.
     * A stored level outside the list is skipped (log line) and the next level is tried.
     */
    public function resolve(int $chatId, int $threadId): ?string
    {
        if ($this->pinnedByExtraArgs() !== null) {
            return null;
        }

        return $this->applicable($this->db->getTopicModel($chatId, $threadId), "topic (chat {$chatId}, thread {$threadId})")
            ?? $this->resolveForAll();
    }

    /** Epic runs: only the model for all topics. */
    public function resolveForAll(): ?string
    {
        if ($this->pinnedByExtraArgs() !== null) {
            return null;
        }

        return $this->applicable($this->db->getDefaultModel(), 'all topics');
    }

    /**
     * Run start: a model pinned in the queue item that is no longer in the list is dropped, with no
     * fallback to the other level.
     */
    public function checkPinned(?string $model): ?string
    {
        if ($model === null || $this->pinnedByExtraArgs() !== null) {
            return null;
        }

        return $this->applicable($model, 'queue item');
    }

    /**
     * The effective level of a topic: [model, level], where level is one of the LEVEL_* constants
     * (model null with LEVEL_DEFAULT). Does not log.
     *
     * @return array{0: ?string, 1: string}
     */
    public function effective(int $chatId, int $threadId): array
    {
        $topic = $this->db->getTopicModel($chatId, $threadId);
        if ($topic !== null && $this->isAllowed($topic)) {
            return [$topic, self::LEVEL_TOPIC];
        }
        $all = $this->db->getDefaultModel();
        if ($all !== null && $this->isAllowed($all)) {
            return [$all, self::LEVEL_ALL];
        }

        return [null, self::LEVEL_DEFAULT];
    }

    /**
     * "sonnet (all topics)" / "Claude Code default", plus (unless $markNotApplied is off) a mark for every stored value that is not applied.
     */
    public function describe(Locale $locale, int $chatId, int $threadId, bool $markNotApplied = true): string
    {
        $pinned = $this->pinnedByExtraArgs();
        if ($pinned !== null) {
            return $locale->t('model.level_pinned', ['model' => $pinned !== '' ? $pinned : '--model']);
        }

        [$model, $level] = $this->effective($chatId, $threadId);
        $text = match ($level) {
            self::LEVEL_TOPIC => $locale->t('model.level_topic', ['model' => (string) $model]),
            self::LEVEL_ALL => $locale->t('model.level_all', ['model' => (string) $model]),
            default => $locale->t('model.level_default'),
        };
        foreach ([$this->db->getTopicModel($chatId, $threadId), $this->db->getDefaultModel()] as $stored) {
            if ($markNotApplied && $stored !== null && !$this->isAllowed($stored)) {
                $text .= ' ['.$locale->t('model.not_applied', ['model' => $stored]).']';
            }
        }

        return $text;
    }

    private function applicable(?string $model, string $where): ?string
    {
        if ($model === null) {
            return null;
        }
        if ($this->isAllowed($model)) {
            return $model;
        }
        Log::line("model: '{$model}' ({$where}) is not in CLAUDE_MODELS - ignored.");

        return null;
    }
}
