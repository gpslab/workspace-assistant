<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Locale;

interface LocaleResolver
{
    /** Catalog with the locale fixed for a queue item or a user selected (`null` means the bot locale). */
    public function localeFor(?string $localeCode): Locale;

    /** Chat language of a user: chosen with /lang, otherwise `BOT_LOCALE`. */
    public function userLocale(int $userId): string;
}
