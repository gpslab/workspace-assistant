<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * The secret guard of an OpenCode run: a shell call whose command carries a secret is denied.
 *
 * OpenCode loads neither the user scope of Claude Code nor the project configuration of the managed
 * repository (the bot passes `OPENCODE_DISABLE_PROJECT_CONFIG=1` and its own per-run config), so the
 * guard a Claude Code run gets from the user or the project scope has no counterpart there. The bot's
 * OpenCode plugin ({@see ../opencode-plugin/index.js}) runs this guard on every run: alone in a run with
 * no gate kind (`arch`, `/compact`), and before the PM gate and the review commit hook in a gated run
 * (`pm`, `review`, an epic run), where a guard deny is final and stops the gate.
 *
 * The detector is {@see SecretScanner}, the one the masking and the commit gate use: the shape patterns
 * plus the known values of the bot's `.env` and of `SECRET_ENV_FILES`. Policy: block, never mask; the
 * deny reason names the types and never a value ({@see SecretFinding}), like the commit gate.
 *
 * The guard inspects `shell` calls only, so a body with a token-shaped string reaches a command such as
 * `gh issue create` through a file (`--body-file`) without being seen. That is the accepted cost of
 * running the guard before the gate: a guard deny has no skip.
 */
final class SecretGuard
{
    /** What the model sees when the guard itself fails; the hook prints it on stderr and exits 2. */
    public const string FAILURE_REASON = 'The command was blocked: the secret guard failed.';

    /**
     * The reason to deny a command, or null when it carries no secret.
     *
     * @param SecretScanner $scanner the scanner of the process, with the known values of the run
     */
    public static function decide(string $command, SecretScanner $scanner): ?string
    {
        $types = [];
        foreach ($scanner->scan($command)['findings'] as $finding) {
            $types[$finding->type] = true;
        }
        if ($types === []) {
            return null;
        }

        // Sorted and unique: the same secret twice is one type, and the order does not depend on where
        // in the command the matches were found.
        $names = array_keys($types);
        sort($names);

        return 'The command was blocked: it carries a secret ('.implode(', ', $names).'). '
            .'A secret value never belongs in a command: read it from the file or the environment that holds it.';
    }
}
