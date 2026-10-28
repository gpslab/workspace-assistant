<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Locale;

/** Locale lookup for queue items and users. */
final readonly class Locales implements LocaleResolver
{
    public function __construct(private Locale $locale, private Database $db)
    {
    }

    /** Catalog with the locale fixed for a queue item or a user selected (`null` means the bot locale). */
    public function localeFor(?string $localeCode): Locale
    {
        return $localeCode === null ? $this->locale : $this->locale->with($localeCode);
    }

    /** Chat language of a user: chosen with /lang, otherwise `BOT_LOCALE` (never guessed from `language_code`). */
    public function userLocale(int $userId): string
    {
        $chosen = $userId !== 0 ? $this->db->getUserLocale($userId) : null;

        return $chosen !== null && in_array($chosen, $this->locale->codes(), true) ? $chosen : $this->locale->code();
    }
}
