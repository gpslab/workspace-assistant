<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/*
 * The scan of the bot's git `pre-push` hook (hooks/git/pre-push): php bin/pre-push-scan.php, with the
 * hook's stdin. Exit 1 with the places and types of the findings on stderr, never a value; exit 1 also
 * when the scan cannot run (fail-closed). Reads the bot's `.env` for the known secret values,
 * `SECRET_ENV_FILES` and `SECRET_SCAN_EXCLUDE`.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }
    $envPath = getenv(GitHooks::ENV_FILE_ENV) ?: __DIR__.'/../.env';
    $env = is_file($envPath) ? Env::load($envPath) : new Env();
    SecretScanner::setShared(new SecretScanner(new SecretSources($envPath, array_values($env->list('SECRET_ENV_FILES')))));

    $cwd = getcwd();
    $findings = (new PrePushScan($cwd === false ? '.' : $cwd, array_values($env->list('SECRET_SCAN_EXCLUDE'))))->scan((string) stream_get_contents(STDIN));
} catch (\Throwable $e) {
    fwrite(STDERR, 'workspace-assistant pre-push: the secret scan failed, the push is blocked: '.$e->getMessage()."\n");
    exit(1);
}

if ($findings === []) {
    exit(0);
}
fwrite(STDERR, "workspace-assistant pre-push: the push is blocked, the commits contain secrets:\n");
foreach ($findings as $finding) {
    fwrite(STDERR, '  '.$finding->location().' ('.$finding->type.")\n");
}
fwrite(STDERR, "Remove the secret and rewrite the commit that added it, then push again.\n");
exit(1);
