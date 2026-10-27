<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

use GpsLab\WorkspaceAssistant\ModeAndLang;

/** `lang:` keyboard of /lang. Stateless: the language is stored per user. */
final readonly class LangHandler implements Handler
{
    public function __construct(private ModeAndLang $modeAndLang)
    {
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'lang:');
    }

    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        $this->modeAndLang->handleLangCallback($callbackId, $userId, $chatId, $message, $data);
    }
}
