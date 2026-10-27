<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;

final readonly class VerboseCommand implements Command
{
    public function __construct(
        private Database $db,
        private TelegramClient $tg,
        private Locale $locale,
    ) {
    }

    public function name(): string
    {
        return 'verbose';
    }

    public function emoji(): string
    {
        return '🔍';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(CommandRequest $request): void
    {
        $on = $this->db->toggleVerbose($request->chatId, $request->threadId);
        $this->tg->sendText($request->chatId, $on ? $this->locale->t('verbose.on') : $this->locale->t('verbose.off'), null, $request->threadId);
    }
}
