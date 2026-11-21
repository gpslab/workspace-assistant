<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/*
 * The secret guard of the OpenCode plugin (`opencode-plugin/`): php bin/secret-guard-hook.php, with the
 * engine-neutral input of the plugin (`{command, engine}`) on stdin. The plugin starts it on every run:
 * alone in a run with no gate kind (`arch`, `/compact`), and before the PM gate and the review commit
 * hook in a gated run — see SecretGuard. It is not called by the bot.
 *
 * A command that carries a secret is denied with the deny JSON of PmGateHook::denyJson() on stdout
 * (exit 0); no output with exit 0 allows the call. The reason names the finding types, never a value.
 *
 * Fail-CLOSED, like bin/pm-gate-hook.php: any failure (including the bootstrap not loading) exits 2 with
 * a reason on stderr, which the plugin turns into a deny. It runs on every shell call of every run, so
 * a guard that cannot decide blocks the call instead of letting it through.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }
    // The known values come from the bot's `.env`, exactly as in bin/pre-push-scan.php.
    $envPath = getenv(GitHooks::ENV_FILE_ENV) ?: __DIR__.'/../.env';
    $env = is_file($envPath) ? Env::load($envPath) : new Env();
    SecretScanner::setShared(new SecretScanner(new SecretSources($envPath, array_values($env->list('SECRET_ENV_FILES')))));

    $input = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($input)) {
        throw new \RuntimeException('stdin is not JSON');
    }
    $command = PmGateHook::toolCommand($input);
    if ($command === null) {
        throw new \RuntimeException('the input carries no shell command');
    }
} catch (\Throwable $e) {
    fwrite(STDERR, SecretGuard::FAILURE_REASON.' '.$e->getMessage()."\n");
    exit(2);
}

$reason = SecretGuard::decide($command, SecretScanner::shared());
if ($reason !== null) {
    echo PmGateHook::denyJson($reason);
}

exit(0);
