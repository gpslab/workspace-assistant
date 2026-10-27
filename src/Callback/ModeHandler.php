<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

use GpsLab\WorkspaceAssistant\ModeAndLang;

/** `mode:` keyboard of /mode. Stateless: the topic is carried in the data, the decision record is not touched. */
final readonly class ModeHandler implements Handler
{
    public function __construct(private ModeAndLang $modeAndLang)
    {
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'mode:');
    }

    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        $this->modeAndLang->handleModeCallback($callbackId, $chatId, $message, $data);
    }
}
