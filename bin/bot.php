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
    SecretScanner::setShared(new SecretScanner(new SecretSources($envPath, $config->secretEnvFiles)));
    $db = new Database($config->stateDir);
    $locale = Locale::fromConfig($config);
    $tg = new TelegramClient($config->botToken, $locale);
    $git = new GitManager($config->repoPath, $config->gitTimeout, $locale, $config->forge, $config->forgeHost, $config->secretScanExclude);
    $sessionFiles = new SessionFiles();
    $github = $config->epicsAvailable() ? new GhClient($config->forgeOwners, openroninLogins: $config->openroninLogins) : null;
    $coderIntake = $github === null || $config->coderIntake->routing->coderRepositories() === [] ? null : new Coder\IntakeController(
        $config->coderIntake,
        new Coder\Store($config->stateDir),
        new GhClient($config->forgeOwners, timeout: $config->coderIntake->requestTimeoutSeconds),
    );

    $bot = new Bot($config, $db, $tg, $git, $sessionFiles, $github, $locale, coderIntake: $coderIntake);
    $bot->run();
} catch (\Throwable $e) {
    Log::line('fatal error: '.$e->getMessage());
    exit(1);
}
