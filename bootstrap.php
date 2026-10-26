<?php

declare(strict_types=1);

// Log times and any date() default to UTC; the TIMEZONE setting from .env is
// applied by the entry points (bin/bot.php, bin/review-stop-hook.php). See Log.
date_default_timezone_set('UTC');

/**
 * Minimal PSR-4 autoloader (without Composer — it is absent in the environment).
 * Maps the GpsLab\WorkspaceAssistant\* namespace to src/*.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'GpsLab\\WorkspaceAssistant\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
