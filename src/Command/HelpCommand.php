<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;

final readonly class HelpCommand implements Command
{
    public function __construct(
        private TelegramClient $tg,
        private Locale $locale,
    ) {
    }

    public function name(): string
    {
        return 'help';
    }

    public function emoji(): string
    {
        return '❓';
    }

    public function aliases(): array
    {
        return ['start'];
    }

    public function execute(CommandRequest $request): void
    {
        $this->tg->sendText($request->chatId, $this->locale->t('help'), null, $request->threadId);
    }
}
