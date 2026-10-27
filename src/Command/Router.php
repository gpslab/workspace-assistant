<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\TelegramClient;

/** Parses `/cmd@bot argument` and runs the registered command; registration order is the menu order. */
final class Router
{
    /** @var list<Command> */
    private array $commands = [];

    /** @var array<string, Command> '/name' or '/alias' => command */
    private array $byName = [];

    public function __construct(
        private readonly TelegramClient $tg,
        private readonly Locale $locale,
    ) {
    }

    public function register(Command $command): void
    {
        $this->commands[] = $command;
        foreach ([$command->name(), ...$command->aliases()] as $name) {
            $this->byName['/'.$name] = $command;
        }
    }

    /**
     * @return list<Command> in menu order
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function handle(int $chatId, int $threadId, string $text, int $messageId = 0, int $userId = 0): void
    {
        // /mode is the first command with an argument: we parse the tail separately from
        // the command itself (lowercasing and trimming are done inside
        // the command — here we only split "/mode" from "review").
        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower($parts[0]);
        $argument = $parts[1] ?? '';
        // In groups/forums the Telegram client appends the suffix
        // @botusername to a command (/new@YourBot); in a 1:1 chat there is none. Without cutting it off,
        // the lookup fails and any command in a topic is reported as unknown. Only our
        // bot receives these updates, so we do not check the name — we cut any @-tail.
        if (($at = strpos($command, '@')) !== false) {
            $command = substr($command, 0, $at);
        }

        if (isset($this->byName[$command])) {
            $this->byName[$command]->execute(new CommandRequest($chatId, $threadId, $argument, $messageId, $userId));

            return;
        }
        $this->tg->sendText($chatId, $this->locale->t('command.unknown', ['command' => $command]), null, $threadId);
    }
}
