<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Parsed result of an agent run (`claude -p --output-format json`, engine-neutral shape).
 */
final class RunAnswer
{
    public function __construct(
        public readonly bool $isError,
        public readonly string $text,
        public readonly ?string $sessionId,
        /** The engine's totals from the last `result` event (a session snapshot); null when it returned none. */
        public readonly ?UsageTotals $totals = null,
        /** The process exited with code 0 but the stream carried no result event. */
        public readonly bool $exitedWithoutResult = false,
        /** The text is the last assistant message, taken instead of the missing result. */
        public readonly bool $recovered = false,
        /**
         * Limit classification reported by the engine's own parser (`rate` or `quota`), null when the
         * engine reported none. {@see JobRunner} prefers it over the text classification, which only
         * matches the wording of a foreign Anthropic-compatible provider.
         */
        public readonly ?string $limitClass = null,
        /**
         * The engine parser's error class (`KimiErrorClass` value), null when the engine reported
         * none or the run is not an error. {@see JobRunner} reacts to `session_not_found` (drop the
         * stored session and start a new one); every other class is only shown in the chat.
         */
        public readonly ?string $errorClass = null,
    ) {
    }

    public static function fromJson(string $stdout, string $stderr, int $exitCode, Locale $locale = new Locale()): self
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode(trim($stdout), true);

        if (!is_array($data)) {
            // No JSON arrived: the process died before emitting a result.
            $msg = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            if ($msg === '') {
                $msg = $locale->t('claude.no_output', ['code' => $exitCode]);
            }

            return new self(true, $msg, null);
        }

        $sessionId = $data['session_id'] ?? null;

        $text = (string) ($data['result'] ?? '');
        if ($text === '') {
            $text = self::errorsToText($data['errors'] ?? null);
        }

        return new self(
            isError: (bool) ($data['is_error'] ?? false),
            text: $text,
            sessionId: is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            totals: UsageTotals::fromResultEvent($data),
        );
    }

    /** The same result with other totals (a per-run figure instead of the snapshot, or the last result's). */
    public function withTotals(?UsageTotals $totals): self
    {
        return new self($this->isError, $this->text, $this->sessionId, $totals, $this->exitedWithoutResult, $this->recovered, $this->limitClass, $this->errorClass);
    }

    /** The same result with other text (an extra line appended to the reply). */
    public function withText(string $text): self
    {
        return new self($this->isError, $text, $this->sessionId, $this->totals, $this->exitedWithoutResult, $this->recovered, $this->limitClass, $this->errorClass);
    }

    /**
     * With `is_error: true` the engine sometimes returns an empty `result` and puts the reason in
     * the top-level `errors` array (for example on `--resume` of a session whose
     * transcript was already deleted by the retention policy). Joined line by line
     * so that reason is not lost.
     */
    private static function errorsToText(mixed $errors): string
    {
        if (!is_array($errors) || $errors === []) {
            return '';
        }

        return implode("\n", array_map(
            static fn (mixed $error): string => is_scalar($error) ? (string) $error : (json_encode($error) ?: ''),
            $errors,
        ));
    }
}
