<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Command\RunControl;

/**
 * The `/model` command, its inline keyboards and the `model:` callbacks: the Claude model of a
 * topic or of all topics (see {@see ModelChoice}). The topic level behaves like `/mode`: sticky,
 * refused while the topic has a running job, closes the topic's gluing group.
 */
final class ModelSwitcher
{
    private readonly ModelChoice $models;

    public function __construct(
        Config $config,
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly Locale $locale,
        private readonly RunControl $run,
    ) {
        $this->models = new ModelChoice($config, $db);
    }

    /**
     * /model [<name>|default] [all] — without an argument: the state and the step-1 keyboard. Text
     * forms: `/model <name>` (topic), `/model <name> all`, `/model default`, `/model default all`.
     * A value outside CLAUDE_MODELS gets an error listing the allowed ones and nothing is written.
     */
    public function cmdModel(int $chatId, int $threadId, string $argument): void
    {
        $argument = trim($argument);

        if ($argument === '') {
            $markup = $this->models->pinnedByExtraArgs() === null
                ? Keyboards::model($this->models->allowed(), $this->models->effective($chatId, $threadId)[0], $threadId)->markup
                : null;
            $this->tg->sendText($chatId, $this->stateText($chatId, $threadId), $markup, $threadId);

            return;
        }

        $pinned = $this->models->pinnedByExtraArgs();
        if ($pinned !== null) {
            $this->tg->sendText($chatId, $this->pinnedText($pinned), null, $threadId);

            return;
        }

        $tokens = preg_split('/\s+/', $argument) ?: [$argument];
        $level = ModelChoice::LEVEL_TOPIC;
        if (count($tokens) === 2 && strtolower($tokens[1]) === ModelChoice::LEVEL_ALL) {
            $level = ModelChoice::LEVEL_ALL;
            array_pop($tokens);
        }
        $name = implode(' ', $tokens);
        $value = strtolower($name) === ModelChoice::VALUE_DEFAULT ? ModelChoice::VALUE_DEFAULT : $this->models->match($name);
        if ($value === null) {
            $this->tg->sendText($chatId, $this->locale->t('model.unknown', ['model' => $name, 'available' => implode(', ', $this->models->allowed())]), null, $threadId);

            return;
        }

        if ($level === ModelChoice::LEVEL_TOPIC && $this->run->topicHasActiveJob($chatId, $threadId)) {
            $this->tg->sendText($chatId, $this->locale->t('model.busy'), null, $threadId);

            return;
        }

        [$text, $warning] = $this->apply($chatId, $threadId, $level, $value);
        $this->tg->sendText($chatId, $text.$warning, null, $threadId);
    }

    /**
     * Parses `model:<step>:<thread_id>:<value>`; the value is the last segment, so a ':' inside a
     * model ID is kept. Null on a malformed format or a non-numeric thread_id.
     *
     * @return array{step: string, thread_id: int, value: string}|null
     */
    public static function parseCallback(string $data): ?array
    {
        $parts = explode(':', $data, 4);
        if (count($parts) !== 4 || $parts[0] !== 'model' || $parts[3] === '' || !ctype_digit($parts[2])) {
            return null;
        }
        if (!in_array($parts[1], ['pick', ModelChoice::LEVEL_TOPIC, ModelChoice::LEVEL_ALL], true)) {
            return null;
        }

        return ['step' => $parts[1], 'thread_id' => (int) $parts[2], 'value' => $parts[3]];
    }

    /**
     * The `model:` keyboards. Step 'pick' edits the message into the level question; 'topic' / 'all'
     * write and edit it back into the state. A value no longer in the list gets an error toast and
     * nothing is written. The topic comes from callback_data (see parseCallback()).
     *
     * @param array<string, mixed> $message
     */
    public function handleCallback(string $callbackId, int $chatId, array $message, string $data): void
    {
        $parsed = self::parseCallback($data);
        $value = $parsed === null ? null : ($parsed['value'] === ModelChoice::VALUE_DEFAULT ? $parsed['value'] : ($this->models->isAllowed($parsed['value']) ? $parsed['value'] : null));
        if ($parsed === null || $value === null) {
            $this->tg->answerCallback($callbackId, $this->locale->t('mode.bad_button'));

            return;
        }

        $pinned = $this->models->pinnedByExtraArgs();
        if ($pinned !== null) {
            $this->tg->answerCallback($callbackId, $this->pinnedText($pinned));

            return;
        }

        $threadId = $parsed['thread_id'];
        $messageId = (int) ($message['message_id'] ?? 0);

        if ($parsed['step'] === 'pick') {
            $this->tg->answerCallback($callbackId);
            $this->tg->editMessageText($chatId, $messageId, $this->locale->t('model.choose_level', ['model' => $value]), Keyboards::modelLevel($threadId, $value, $this->locale->t('model.button_topic'), $this->locale->t('model.button_all'))->markup);

            return;
        }

        if ($parsed['step'] === ModelChoice::LEVEL_TOPIC && $this->run->topicHasActiveJob($chatId, $threadId)) {
            $this->tg->answerCallback($callbackId, $this->locale->t('model.busy'));

            return;
        }

        try {
            [$text, $warning] = $this->apply($chatId, $threadId, $parsed['step'], $value);
        } catch (\Throwable) {
            $this->tg->answerCallback($callbackId, $this->locale->t('model.save_failed'));

            return;
        }

        $this->tg->answerCallback($callbackId);
        $this->tg->editMessageText($chatId, $messageId, $this->stateText($chatId, $threadId)."\n\n".$text.$warning, Keyboards::model($this->models->allowed(), $this->models->effective($chatId, $threadId)[0], $threadId)->markup);
    }

    /**
     * Writes the choice and returns [what was set, the session warning]; the warning is empty unless the
     * effective model of a non-empty session changes. The topic level closes the topic's gluing group; the level
     * for all topics cannot (it is not tied to one topic), the gluing check on the pinned model does it.
     */
    /** @return array{0: string, 1: string} */
    private function apply(int $chatId, int $threadId, string $level, string $value): array
    {
        $model = $value === ModelChoice::VALUE_DEFAULT ? null : $value;
        $hadSession = $this->db->getSessionId($chatId, $threadId) !== null;
        $before = $this->models->effective($chatId, $threadId)[0];

        if ($level === ModelChoice::LEVEL_TOPIC) {
            $this->db->setTopicModel($chatId, $threadId, $model);
            $this->db->queueCloseGroup($chatId, $threadId);
            $text = $this->locale->t('model.switched_topic', ['model' => $model ?? $this->locale->t('model.unset')]);
        } else {
            $this->db->setDefaultModel($model);
            $text = $this->locale->t('model.switched_all', ['model' => $model ?? $this->locale->t('model.unset')]);
        }

        $reaches = $hadSession && $this->models->effective($chatId, $threadId)[0] !== $before;

        return [$text, $reaches ? $this->locale->t('model.session_warning') : ''];
    }

    private function stateText(int $chatId, int $threadId): string
    {
        $pinned = $this->models->pinnedByExtraArgs();
        $text = $this->locale->t('model.current', [
            'model' => $this->models->describe($this->locale, $chatId, $threadId, false),
            'topic' => $this->storedText($this->db->getTopicModel($chatId, $threadId)),
            'all' => $this->storedText($this->db->getDefaultModel()),
        ]);
        $last = $this->db->getLastModel($chatId, $threadId);
        if ($last !== null) {
            $text .= "\n".$this->locale->t('stats.last_model', ['model' => $last]);
        }
        if ($pinned !== null) {
            $text .= "\n\n".$this->pinnedText($pinned);
        }

        return $text;
    }

    private function storedText(?string $stored): string
    {
        if ($stored === null) {
            return $this->locale->t('model.unset');
        }

        return $this->models->isAllowed($stored) ? $stored : $this->locale->t('model.not_applied', ['model' => $stored]);
    }

    private function pinnedText(string $pinned): string
    {
        return $this->locale->t('model.pinned', ['model' => $pinned !== '' ? $pinned : '--model']);
    }
}
