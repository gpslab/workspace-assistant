<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Command;

use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\TelegramClient;

/** The command menu registered with Telegram; descriptions are the `command.<name>` texts. */
final readonly class Menu
{
    /** Telegram limit for a command description, in UTF-16 code units. */
    private const int COMMAND_DESCRIPTION_MAX = 256;

    public function __construct(
        private Router $router,
        private TelegramClient $tg,
        private Locale $locale,
    ) {
    }

    /**
     * Registers the command descriptions for every available locale (with its `language_code`)
     * and once without a code, in the `BOT_LOCALE` language.
     */
    public function register(): void
    {
        foreach ($this->locale->codes() as $code) {
            try {
                $this->tg->setMyCommands($this->commandList($this->locale->with($code)), $code);
            } catch (\Throwable $e) {
                // A locale code Telegram does not accept must not stop the bot.
                Log::line("setMyCommands for locale {$code} failed: ".$e->getMessage());
            }
        }
        $this->tg->setMyCommands($this->commandList($this->locale));
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    private function commandList(Locale $locale): array
    {
        $commands = [];
        foreach ($this->router->commands() as $item) {
            $command = $item->name();
            $emoji = $item->emoji();
            $text = self::cutUtf16($locale->t("command.{$command}"), self::COMMAND_DESCRIPTION_MAX - self::utf16Length($emoji) - 1);
            $commands[] = ['command' => $command, 'description' => "{$emoji} {$text}"];
        }

        return $commands;
    }

    /** Length in UTF-16 code units, the way Telegram counts text length. */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Cuts the text to at most $max UTF-16 code units, dropping whole characters and any dangling joiner or selector. */
    private static function cutUtf16(string $text, int $max): string
    {
        if (self::utf16Length($text) <= $max) {
            return $text;
        }
        $cut = '';
        $used = 0;
        foreach (mb_str_split($text) as $char) {
            $used += self::utf16Length($char);
            if ($used > $max) {
                break;
            }
            $cut .= $char;
        }

        return preg_replace('/[\x{200D}\x{FE0F}\x{FE0E}\x{1F3FB}-\x{1F3FF}]+$/u', '', $cut) ?? $cut;
    }
}
