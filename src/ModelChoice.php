<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\Run;

/**
 * Which Claude model a run gets (`claude -p --model`). Two stored levels, topic then all topics, and an
 * epic run-kind key (MODEL_EPIC_<RUN>) above them; "nothing" means no flag and the CLI uses its own
 * setting. Only values of CLAUDE_MODELS apply; a stored value outside the list is skipped with a log
 * line. A `--model` in CLAUDE_EXTRA_ARGS pins the model for the whole instance, and so does the model
 * variable of an engine that takes its model from the instance (`OPENCODE_MODEL`, `KIMI_MODEL`): the bot
 * resolves nothing then.
 */
final readonly class ModelChoice
{
    /** The keyword that clears a level, in text and in callback_data. */
    public const string VALUE_DEFAULT = 'default';

    public const string LEVEL_TOPIC = 'topic';
    public const string LEVEL_ALL = 'all';
    public const string LEVEL_DEFAULT = 'default';

    /** What `/stats` and `/model` show instead of an empty OPENCODE_MODEL: OpenCode falls back to its own default. */
    private const string OPENCODE_DEFAULT_MODEL = 'default model of OpenCode';

    /** What `/stats` and `/model` show instead of an empty KIMI_MODEL: Kimi takes the model of its own config. */
    private const string KIMI_DEFAULT_MODEL = 'default model of Kimi';

    /** The source phrase of the pin for `model.level_pinned` on a Claude instance (a `--model` in CLAUDE_EXTRA_ARGS). */
    private const string SOURCE_LEVEL_EXTRA_ARGS = 'CLAUDE_EXTRA_ARGS';

    /** The source of the pin on an OpenCode instance. */
    private const string SOURCE_OPENCODE = 'OPENCODE_MODEL';

    /** The source of the pin on a Kimi instance. */
    private const string SOURCE_KIMI = 'KIMI_MODEL';

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
     * True when the instance pins the model for every run: a `--model` in CLAUDE_EXTRA_ARGS, or the model
     * variable of an engine that takes it from the instance (`OPENCODE_MODEL`, `KIMI_MODEL`). No stored
     * `/model` choice survives the pin.
     */
    public function isPinned(): bool
    {
        return $this->pinnedByExtraArgs() !== null || $this->pinnedSource() !== null;
    }

    /**
     * What a new queue item pins: topic model, else the model for all topics, else null.
     * A stored level outside the list is skipped (log line) and the next level is tried.
     */
    public function resolve(int $chatId, int $threadId): ?string
    {
        if ($this->isPinned()) {
            return null;
        }

        return $this->applicable($this->db->getTopicModel($chatId, $threadId), "topic (chat {$chatId}, thread {$threadId})")
            ?? $this->resolveForAll();
    }

    /** The model for all topics (or null): the last level of resolve() and the fallback of resolveEpic(). */
    public function resolveForAll(): ?string
    {
        if ($this->isPinned()) {
            return null;
        }

        return $this->applicable($this->db->getDefaultModel(), 'all topics');
    }

    /**
     * Epic runs: the run-kind key (MODEL_EPIC_<RUN>), else the model for all topics, else null.
     * The key value is validated at startup against CLAUDE_MODELS, so it is passed as it is. An instance
     * whose model variable pins it ({@see self::isPinned()}) resolves nothing there: its epic runs take
     * the same model as every other run.
     */
    public function resolveEpic(string $run): ?string
    {
        if ($this->isPinned()) {
            return null;
        }

        $keyValue = match ($run) {
            Run::TICK => $this->config->modelEpicTick,
            Run::FINAL => $this->config->modelEpicFinal,
            Run::REVIEW => $this->config->modelEpicReview,
            default => '',
        };
        if ($keyValue !== '') {
            return $keyValue;
        }

        return $this->resolveForAll();
    }

    /**
     * Run start: a model pinned in the queue item that is no longer in the list is dropped, with no
     * fallback to the other level.
     */
    public function checkPinned(?string $model): ?string
    {
        if ($model === null || $this->isPinned()) {
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
        $source = $this->pinnedSource();
        if ($source !== null) {
            return $locale->t('model.level_pinned', [
                'model' => $this->pinnedModel(),
                'source' => $source,
            ]);
        }
        $pinned = $this->pinnedByExtraArgs();
        if ($pinned !== null) {
            return $locale->t('model.level_pinned', [
                'model' => $pinned !== '' ? $pinned : '--model',
                'source' => self::SOURCE_LEVEL_EXTRA_ARGS,
            ]);
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

    /** The pinned model of an instance whose engine takes it from `.env`: the variable value, or the engine's own default when it is empty. */
    private function pinnedModel(): string
    {
        if ($this->config->isKimi()) {
            return $this->config->kimiModel !== '' ? $this->config->kimiModel : self::KIMI_DEFAULT_MODEL;
        }

        return $this->config->opencodeModel !== '' ? $this->config->opencodeModel : self::OPENCODE_DEFAULT_MODEL;
    }

    /**
     * The model variable that pins this instance, or null when the pin comes from a `--model` in
     * CLAUDE_EXTRA_ARGS instead. The variable name is language-neutral, so the text around it stays
     * translated: `KIMI_MODEL` is passed to the texts exactly like `OPENCODE_MODEL`.
     */
    private function pinnedSource(): ?string
    {
        return match (true) {
            $this->config->isOpencode() => self::SOURCE_OPENCODE,
            $this->config->isKimi() => self::SOURCE_KIMI,
            default => null,
        };
    }

    /**
     * The refusal text of a pinned model (`/model` with an argument, or a `model:` callback): names the
     * source of the pin and the pinned model (or the engine's default when the value is empty). The two
     * pins are separate keys: `model.pinned` keeps the translated "--model in CLAUDE_EXTRA_ARGS" phrase of
     * a Claude instance, while `model.pinned_instance` names the language-neutral variable of the engine.
     */
    public function pinnedRefusalText(Locale $locale): string
    {
        $source = $this->pinnedSource();
        if ($source !== null) {
            return $locale->t('model.pinned_instance', [
                'model' => $this->pinnedModel(),
                'source' => $source,
            ]);
        }
        $pinned = $this->pinnedByExtraArgs() ?? '';

        return $locale->t('model.pinned', [
            'model' => $pinned !== '' ? $pinned : '--model',
        ]);
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
