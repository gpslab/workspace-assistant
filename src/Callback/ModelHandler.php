<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Callback;

use GpsLab\WorkspaceAssistant\ModelSwitcher;

/** `model:` keyboards of /model. Stateless: the topic is carried in the data, the decision record is not touched. */
final readonly class ModelHandler implements Handler
{
    public function __construct(private ModelSwitcher $switcher)
    {
    }

    public function supports(string $data): bool
    {
        return str_starts_with($data, 'model:');
    }

    public function handle(string $callbackId, int $userId, int $chatId, array $message, string $data): void
    {
        $this->switcher->handleCallback($callbackId, $chatId, $message, $data);
    }
}
