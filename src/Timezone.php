<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Timezone used by date() and Log. Configured with TIMEZONE in .env, UTC by default.
 */
final class Timezone
{
    public const DEFAULT = 'UTC';

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * Applies the timezone from the .env file (if any); falls back to UTC when it is
     * missing, unset or invalid. Does not load Config, so it is safe for the review
     * Stop hook, which runs outside the bot.
     */
    public static function applyFromEnvFile(string $envPath): void
    {
        $timezone = self::DEFAULT;
        $real = getenv('TIMEZONE');
        if ($real !== false) {
            $value = trim($real);
        } elseif (is_file($envPath)) {
            $value = trim((string) Env::load($envPath)->get('TIMEZONE', ''));
        } else {
            $value = '';
        }
        if ($value !== '' && self::isValid($value)) {
            $timezone = $value;
        }
        date_default_timezone_set($timezone);
    }
}
