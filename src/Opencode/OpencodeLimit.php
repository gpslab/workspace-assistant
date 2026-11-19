<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Opencode;

use GpsLab\WorkspaceAssistant\ClaudeErrorText;

/**
 * Classification of an OpenCode error into the foreign-provider limit class of
 * {@see ClaudeErrorText::foreignLimit()}.
 *
 * `rate` pauses the queue (an unrecognised 429 / "rate limit", no reset time), `quota` does not
 * (waiting does not refill a balance). null means not a limit. The text classification is reused,
 * not copied: only the OpenCode-specific signals are added here — `provider.quota` `error.type`
 * (an exhausted balance, HTTP 402) and, as a fallback when the message text gives no answer, the
 * `error.status` (402 → quota, 429 → rate). OpenCode's message text alone does not always match
 * {@see ClaudeErrorText::foreignLimit()}.
 */
final class OpencodeLimit
{
    /**
     * @param array<string, mixed>|null $error the `error` object of an OpenCode `error` event
     *
     * @return 'rate'|'quota'|null
     */
    public static function classify(?array $error, ClaudeErrorText $claudeErrorText): ?string
    {
        if ($error === null) {
            return null;
        }

        if (($error['type'] ?? null) === 'provider.quota') {
            return 'quota';
        }

        $message = $error['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $limit = $claudeErrorText->foreignLimit($message);
            if ($limit !== null) {
                return $limit;
            }
        }

        // The text gave no answer; fall back on the HTTP status, which the fixtures show OpenCode
        // always sends (402 for provider.quota, 429 for a rate limit).
        $status = $error['status'] ?? null;
        if (is_int($status) || is_float($status)) {
            return match ((int) $status) {
                429 => 'rate',
                402 => 'quota',
                default => null,
            };
        }

        return null;
    }
}
