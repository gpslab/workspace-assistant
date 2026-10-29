<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Entry point. Run: php bin/bot.php.
 *
 * Can be daemonized via systemd / supervisor / tmux.
 */

require __DIR__.'/../bootstrap.php';

$envPath = __DIR__.'/../.env';

try {
    $config = Config::load($envPath);
    date_default_timezone_set($config->timezone);
    foreach ($config->startupWarnings() as $warning) {
        Log::line('warning: '.$warning);
    }
    $db = new Database($config->stateDir);
    $locale = Locale::fromConfig($config);
    $tg = new TelegramClient($config->botToken, $locale);
    $git = new GitManager($config->repoPath, $config->gitTimeout, $locale);
    $sessionFiles = new SessionFiles();

    $bot = new Bot($config, $db, $tg, $git, $sessionFiles, $config->epicsAvailable() ? new GhClient($config->forgeOwners, openroninLogins: $config->openroninLogins) : null, $locale);
    $bot->run();
} catch (\Throwable $e) {
    Log::line('fatal error: '.$e->getMessage());
    exit(1);
}
