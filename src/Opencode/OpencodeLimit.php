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
 * not copied: only the `provider.quota` `error.type` is added here, because OpenCode reports an
 * exhausted balance as that type (HTTP 402) while its message text alone does not match
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
        if (!is_string($message) || $message === '') {
            return null;
        }

        return $claudeErrorText->foreignLimit($message);
    }
}
