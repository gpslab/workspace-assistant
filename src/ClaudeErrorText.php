<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Text of a Claude error for the chat and parsing of its usage-limit message (the reset moment
 * sets the queue pause).
 */
final class ClaudeErrorText
{
    private const int LIMIT_RESET_GRACE_SEC = 300;

    /** Substring Claude uses to flag `--resume` of a session whose transcript is already deleted. */
    private const string SESSION_EXPIRED_MARKER = 'No conversation found with session ID';

    public function __construct(private readonly Locale $locale)
    {
    }

    public function format(string $text): string
    {
        $limit = $this->parseLimit($text);
        if ($limit !== null) {
            return $limit['message'];
        }

        if (self::isSessionExpired($text)) {
            return $this->locale->t('error.session_expired');
        }

        return $this->locale->t('error.claude', ['text' => $text !== '' ? $text : $this->locale->t('error.no_text')]);
    }

    /**
     * Parse a Claude limit error: the user message and the reset time (unix),
     * which sets the queue pause. reset_at = null if the time could not be
     * parsed (the pause is then Bot::LIMIT_PAUSE_FALLBACK_SEC). null means not a limit.
     *
     * @return array{message: string, reset_at: int|null}|null
     */
    public function parseLimit(string $text): ?array
    {
        // "You've hit your weekly limit · resets Jun 30, 10pm (Europe/Moscow)"
        if (preg_match('/weekly limit.*resets\s+(\w+ \d+),\s*(\d{1,2})(?::(\d{2}))?(am|pm)/i', $text, $m)) {
            $h = (int) $m[2] % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);
            $min = $m[3] !== '' ? $m[3] : '00';

            return [
                'message' => $this->locale->t('limit.weekly', ['date' => $m[1], 'hour' => $h, 'minute' => $min]),
                'reset_at' => self::resetTimestamp($m[1], $h, (int) $min, $text),
            ];
        }

        // "You've hit your session limit · resets 3pm (Europe/Moscow)"
        // "You've hit your session limit · resets 5:20pm (Europe/Moscow)"
        if (preg_match('/session limit.*resets\s+(\d{1,2})(?::(\d{2}))?(am|pm)/i', $text, $m)) {
            $h = (int) $m[1] % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);
            $min = $m[2] !== '' ? $m[2] : '00';

            return [
                'message' => $this->locale->t('limit.session', ['hour' => $h, 'minute' => $min]),
                'reset_at' => self::resetTimestamp(null, $h, (int) $min, $text),
            ];
        }

        // A limit without a recognizable reset time.
        if (preg_match('/hit your .*limit/i', $text)) {
            return ['message' => $this->locale->t('error.claude', ['text' => $text]), 'reset_at' => null];
        }

        return null;
    }

    /**
     * Classification of a foreign-provider limit error. Only consulted when `ANTHROPIC_BASE_URL`
     * points to a non-Anthropic host (see {@see Config::usesForeignProvider()}); Anthropic's own
     * limit text stays in {@see parseLimit()}. `rate` pauses the queue, `quota` does not (waiting
     * does not refill a balance); null means not a provider limit.
     *
     * @return 'rate'|'quota'|null
     */
    public function foreignLimit(string $text): ?string
    {
        // A quota/balance word wins over a bare 429: aggregators report "429 insufficient credits"
        // for an exhausted balance, and pausing cannot refill it.
        if (preg_match('/quota|balance|insufficient/i', $text) === 1) {
            return 'quota';
        }
        if (preg_match('/\b429\b/', $text) === 1 || preg_match('/rate limit/i', $text) === 1) {
            return 'rate';
        }

        return null;
    }

    /**
     * Nearest limit reset moment (unix) in the timezone from the error's
     * parentheses (defaults to the bot's timezone); null if it could not be parsed.
     * $nowTs is the "now" moment (unix), the current time by default.
     */
    public static function resetTimestamp(?string $date, int $hour, int $minute, string $text, ?int $nowTs = null): ?int
    {
        try {
            $tz = self::zoneFromText($text) ?? new \DateTimeZone(date_default_timezone_get());
            $now = (new \DateTimeImmutable('@'.($nowTs ?? time())))->setTimezone($tz);

            if ($date === null) {
                $at = $now->setTime($hour, $minute);
                if ($at <= $now) {
                    if ($now->getTimestamp() - $at->getTimestamp() <= self::LIMIT_RESET_GRACE_SEC) {
                        return null; // the reset has just happened — short fallback pause
                    }
                    $at = $at->modify('+1 day');
                }

                return $at->getTimestamp();
            }

            $at = \DateTimeImmutable::createFromFormat('!M j H:i', sprintf('%s %d:%02d', $date, $hour, $minute), $tz);
            if ($at === false) {
                return null;
            }
            $at = $at->setDate((int) $now->format('Y'), (int) $at->format('n'), (int) $at->format('j'));
            if ($at <= $now) {
                if ($now->getTimestamp() - $at->getTimestamp() <= self::LIMIT_RESET_GRACE_SEC) {
                    return null;
                }
                $at = $at->modify('+1 year');
            }

            return $at->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Timezone from the error's parentheses: `(Europe/Moscow)`, `(UTC)`,
     * `(America/Argentina/Buenos_Aires)`, `(Etc/GMT+3)`. Parentheses without a
     * valid zone are skipped; null means no zone.
     */
    private static function zoneFromText(string $text): ?\DateTimeZone
    {
        if (preg_match_all('/\(([A-Za-z0-9_+\-\/]+)\)/', $text, $matches) === false) {
            return null;
        }
        foreach ($matches[1] as $candidate) {
            try {
                return new \DateTimeZone($candidate);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private static function isSessionExpired(string $text): bool
    {
        return str_contains($text, self::SESSION_EXPIRED_MARKER);
    }
}
