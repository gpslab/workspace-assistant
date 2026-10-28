<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Command\LocaleResolver;
use GpsLab\WorkspaceAssistant\Command\RunControl;

/**
 * The `/mode` and `/lang` commands and their inline keyboards: the working mode of a topic
 * and the chat language of a user.
 */
final class ModeAndLang
{
    public function __construct(
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly Locale $locale,
        private readonly RunControl $run,
        private readonly LocaleResolver $locales,
    ) {
    }

    /**
     * /mode [arch|review|pm] — without an argument it sends via direct sendText() (not through
     * deliver(), see the affected places in gotchas.md) a message with
     * the current mode and an inline keyboard of three buttons; tapping a button
     * switches the mode and edits the same message (see handleCallback()). With an
     * argument — switches by text; an unknown value — an error message, the mode does not change.
     * Both mutating forms (button and text with an
     * argument) are closed by the active-task gate — showing the keyboard without an
     * argument does not mutate and is not gated. The mode is sticky (stored in
     * sessions, resetSession does not touch it) and by itself does not reset
     * the session — it only warns, if the session is non-empty, that the style of earlier
     * turns stays in the context.
     */
    public function cmdMode(int $chatId, int $threadId, string $argument): void
    {
        $argument = strtolower(trim($argument));

        if ($argument === '') {
            $current = $this->db->getMode($chatId, $threadId);
            $this->tg->sendText($chatId, $this->modeMessage($current), Keyboards::mode($current, $threadId)->markup, $threadId);

            return;
        }

        if ($this->run->topicHasActiveJob($chatId, $threadId)) {
            $this->tg->sendText($chatId, $this->locale->t('mode.busy'), null, $threadId);

            return;
        }

        $available = implode(', ', SystemPrompt::MODES);
        if (!SystemPrompt::isValidMode($argument)) {
            $this->tg->sendText($chatId, $this->locale->t('mode.unknown', ['mode' => $argument, 'available' => $available]), null, $threadId);

            return;
        }

        $hadSession = $this->db->getSessionId($chatId, $threadId) !== null;
        $this->db->setMode($chatId, $threadId, $argument);
        $this->db->queueCloseGroup($chatId, $threadId);

        $message = $this->locale->t('mode.switched', ['mode' => $argument]);
        if ($hadSession) {
            $message .= $this->locale->t('mode.session_warning');
        }
        $this->tg->sendText($chatId, $message, null, $threadId);
    }

    /**
     * /lang [code] — the chat language of the calling user (the language of Claude's final
     * reply). Stored per user; applies from the next queue item: the running job and the items
     * queued earlier keep the locale fixed at queueing. The reply is already in the new language.
     */
    public function cmdLang(int $chatId, int $threadId, int $userId, string $argument): void
    {
        $argument = trim($argument);
        $current = $this->locales->userLocale($userId);
        $available = implode(', ', array_map(
            fn (string $code): string => $code.' ('.$this->locale->languageName($code).')',
            $this->locale->codes(),
        ));

        if ($argument === '') {
            $this->tg->sendText($chatId, $this->locales->localeFor($current)->t('lang.current', [
                'code' => $current,
                'language' => $this->locale->languageName($current),
                'available' => $available,
            ]), $this->buildLangKeyboard($current), $threadId);

            return;
        }

        $code = null;
        foreach ($this->locale->codes() as $candidate) {
            if (strcasecmp($candidate, $argument) === 0) {
                $code = $candidate;
            }
        }
        if ($code === null) {
            $this->tg->sendText($chatId, $this->locales->localeFor($current)->t('lang.unknown', ['code' => $argument, 'available' => $available]), null, $threadId);

            return;
        }

        $this->db->setUserLocale($userId, $code);
        $this->tg->sendText($chatId, $this->langSwitchedText($code), null, $threadId);
    }

    /** The reply to a language switch, in the new language; shared by the text form and the keyboard. */
    private function langSwitchedText(string $code): string
    {
        return $this->locales->localeFor($code)->t('lang.switched', [
            'code' => $code,
            'language' => $this->locale->languageName($code),
        ]).$this->locales->localeFor($code)->t('lang.session_warning');
    }

    /**
     * The /lang keyboard (see Keyboards::language()); a locale whose code does not fit Telegram's
     * 64-byte limit gets no button (it stays selectable with '/lang <code>').
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function buildLangKeyboard(string $current): array
    {
        $locales = [];
        foreach ($this->locale->codes() as $code) {
            $label = $this->locale->nativeName($code);
            $flag = $this->locale->flag($code);
            if ($flag !== null) {
                $label = $flag.' '.$label;
            }
            $locales[] = ['code' => $code, 'label' => $label];
        }

        return Keyboards::language($locales, $current)->markup;
    }

    /**
     * The /lang keyboard branch. Stateless: the language is stored per user, so it is changed for
     * the user who pressed the button (not for the one who called /lang) and no topic is needed.
     * A code that is no longer in the catalog gets an error toast and nothing is written. The
     * message is edited to the same text as the text form (plain text: no Markdown).
     *
     * @param array<string, mixed> $message
     */
    public function handleLangCallback(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        $code = substr($data, strlen('lang:'));
        if (!in_array($code, $this->locale->codes(), true)) {
            $this->tg->answerCallback($callbackId, $this->locale->t('mode.bad_button'));

            return;
        }

        try {
            $this->db->setUserLocale($userId, $code);
        } catch (\Throwable) {
            $this->tg->answerCallback($callbackId, $this->locale->t('lang.save_failed'));

            return;
        }

        $this->tg->answerCallback($callbackId, $this->locales->localeFor($code)->t('lang.switched', [
            'code' => $code,
            'language' => $this->locale->languageName($code),
        ]));
        $this->tg->editMessageText($chatId, (int) ($message['message_id'] ?? 0), $this->langSwitchedText($code), $this->buildLangKeyboard($code));
    }

    private function modeMessage(string $mode): string
    {
        return $this->locale->t('mode.current', ['mode' => $mode]);
    }

    /**
     * Parses the /mode keyboard callback_data: 'mode:<mode>:<thread_id>'.
     * Returns null on a malformed format, an unknown mode or a
     * non-numeric thread_id - the caller must not touch anything in that case.
     * Database::setMode().
     *
     * @return array{mode: string, thread_id: int}|null
     */
    public static function parseModeCallback(string $data): ?array
    {
        $parts = explode(':', $data);
        if (count($parts) !== 3 || $parts[0] !== 'mode') {
            return null;
        }

        [, $mode, $threadIdRaw] = $parts;
        if (!SystemPrompt::isValidMode($mode) || !ctype_digit($threadIdRaw)) {
            return null;
        }

        return ['mode' => $mode, 'thread_id' => (int) $threadIdRaw];
    }

    /**
     * The /mode keyboard branch. The topic is taken from callback_data (see
     * parseModeCallback()), not from the message object — for a sufficiently
     * old message Telegram sends a truncated message without topic markers,
     * and threadIdOf() would then silently return 0. chat_id is still taken
     * from the update — it is present in the truncated object.
     *
     * On success the order is: check whether the session was non-empty (before
     * writing the mode — the write itself does not touch the session) → write
     * the mode → toast → best-effort message edit. For a non-empty session the
     * edited text gets the same MODE_SESSION_WARNING warning as the text form of
     * cmdMode() — a divergence would hit exactly where it matters most: the
     * button is the main way to switch mid-way through a live session. On a
     * write failure — an error toast, no edit.
     *
     * @param array<string, mixed> $message
     */
    public function handleModeCallback(string $callbackId, int $chatId, array $message, string $data): void
    {
        $parsed = self::parseModeCallback($data);
        if ($parsed === null) {
            $this->tg->answerCallback($callbackId, $this->locale->t('mode.bad_button'));

            return;
        }

        $mode = $parsed['mode'];
        $threadId = $parsed['thread_id'];

        if ($this->run->topicHasActiveJob($chatId, $threadId)) {
            $this->tg->answerCallback($callbackId, $this->locale->t('mode.busy'));

            return;
        }

        $hadSession = $this->db->getSessionId($chatId, $threadId) !== null;

        try {
            $this->db->setMode($chatId, $threadId, $mode);
            $this->db->queueCloseGroup($chatId, $threadId);
        } catch (\Throwable) {
            $this->tg->answerCallback($callbackId, $this->locale->t('mode.save_failed'));

            return;
        }

        $this->tg->answerCallback($callbackId, $this->locale->t('mode.switched', ['mode' => $mode]));

        $text = $this->modeMessage($mode);
        if ($hadSession) {
            $text .= $this->locale->t('mode.session_warning');
        }

        $messageId = (int) ($message['message_id'] ?? 0);
        $this->tg->editMessageText($chatId, $messageId, $text, Keyboards::mode($mode, $threadId)->markup);
    }
}
