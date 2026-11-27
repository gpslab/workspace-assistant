<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Coder\ImplementationChecks;

/* Fixed targeted-check tool invoked by the coder engine during implementation. */

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }
    $arguments = $_SERVER['argv'] ?? [];
    if (!is_array($arguments)) {
        throw new \RuntimeException('The targeted check arguments are unavailable.');
    }
    $exit = ImplementationChecks::execute(array_slice($arguments, 1));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Targeted implementation checks could not run: '.$e->getMessage()."\n");
    exit(2);
}

exit($exit);
