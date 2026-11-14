<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * The git hooks directory of the bot (`hooks/git/`) and the environment that points git at it.
 *
 * The Claude process gets `core.hooksPath` through `GIT_CONFIG_COUNT/KEY_n/VALUE_n`, so every git
 * it starts (also from a script or `sh -c`) runs the bot's `pre-push` scan. It is a safety net:
 * `git push --no-verify` and pushes not made through git bypass it. Every other hook name in the
 * directory dispatches to the repository's own hook.
 */
final class GitHooks
{
    /** Names of the standard git hooks that dispatch to the repository's own hook. */
    public const array DISPATCHED = [
        'applypatch-msg', 'pre-applypatch', 'post-applypatch', 'pre-commit', 'pre-merge-commit',
        'prepare-commit-msg', 'commit-msg', 'post-commit', 'pre-rebase', 'post-checkout', 'post-merge',
        'post-rewrite', 'pre-auto-gc', 'reference-transaction', 'fsmonitor-watchman',
        'post-index-change', 'sendemail-validate',
    ];

    /** The PHP binary that runs `bin/pre-push-scan.php`. */
    public const string PHP_ENV = 'WORKSPACE_ASSISTANT_PHP';

    /** Overrides the `.env` file the scan reads (tests); the bot's own `.env` by default. */
    public const string ENV_FILE_ENV = 'WORKSPACE_ASSISTANT_ENV_FILE';

    public static function dir(): string
    {
        return dirname(__DIR__).'/hooks/git';
    }

    /**
     * Adds `core.hooksPath` after the entries `GIT_CONFIG_COUNT` already has, so it is the last
     * one and wins.
     *
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    public static function apply(array $env): array
    {
        $count = isset($env['GIT_CONFIG_COUNT']) && ctype_digit($env['GIT_CONFIG_COUNT']) ? (int) $env['GIT_CONFIG_COUNT'] : 0;
        $env['GIT_CONFIG_KEY_'.$count] = 'core.hooksPath';
        $env['GIT_CONFIG_VALUE_'.$count] = self::dir();
        $env['GIT_CONFIG_COUNT'] = (string) ($count + 1);
        $env[self::PHP_ENV] = PHP_BINARY;

        return $env;
    }
}
