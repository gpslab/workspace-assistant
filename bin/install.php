<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Writes .env from command-line options. Run: php bin/install.php --bot-token-file=... (see README "Installation").
 */

require __DIR__.'/../bootstrap.php';

$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
$userName = null;
if ($euid !== null && function_exists('posix_getpwuid')) {
    $entry = posix_getpwuid($euid);
    $userName = $entry !== false ? $entry['name'] : null;
}

/** @var list<string> $argv */
$installer = new Installer(dirname(__DIR__), getenv(), $euid, $userName);

exit($installer->run(array_slice($argv, 1), STDOUT, STDERR));
