<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Deletion of Claude Code session transcripts from disk.
 *
 * Claude stores each session as `<projects>/<slug>/<session_id>.jsonl` (+
 * a same-named helper directory `<session_id>/`). `/new` and `/compact`
 * detach the old session in the DB, but the file on disk stays as dead weight;
 * it can only be returned to via `claude --resume` from the console. Here we delete it
 * at the moment of an explicit reset, when `session_id` is known for certain.
 *
 * The slug (encoded cwd) is NOT reconstructed: it is brittle against the encoding algorithm;
 * the file is found by UUID via a glob across all projects (a UUID is globally unique).
 */
final class SessionFiles
{
    private readonly string $projectsDir;

    public function __construct(?string $projectsDir = null)
    {
        if ($projectsDir !== null) {
            $this->projectsDir = $projectsDir;

            return;
        }

        // Resolve the path THE SAME WAY claude itself does: the environment variable
        // CLAUDE_CONFIG_DIR (an OS variable of Claude Code, read from the PROCESS
        // environment, NOT from the bot's .env; it should not be in .env), otherwise
        // $HOME/.claude. The bot launches claude inheriting its own environment
        // (proc_open env=null), so claude and we see the same thing. Usually
        // the variable is unset, giving the $HOME/.claude branch; getenv here is not dead
        // code but compatibility in case claude's config is moved via this
        // standard variable, in which case transcripts must be looked up there too.
        $configDir = getenv('CLAUDE_CONFIG_DIR');
        if (!is_string($configDir) || $configDir === '') {
            $home = getenv('HOME');
            $configDir = (is_string($home) ? $home : '').'/.claude';
        }

        $this->projectsDir = $configDir.'/projects';
    }

    /**
     * Best-effort deletion of a session transcript by its UUID: the file
     * `<projects>/<slug>/<id>.jsonl` and the same-named directory `<id>/`.
     * Throws no exceptions: the calling flow (/new, /compact) must not fail
     * because of filesystem problems. A non-UUID is ignored as protection against a stray glob.
     */
    public function delete(string $sessionId): void
    {
        if (!$this->isUuid($sessionId)) {
            return;
        }

        foreach (glob($this->projectsDir.'/*/'.$sessionId.'.jsonl') ?: [] as $file) {
            @unlink($file);

            $dir = substr($file, 0, -\strlen('.jsonl'));
            if (is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
    }

    private function isUuid(string $s): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            // Delete a symlink as a link and do NOT recurse through it: is_dir()
            // follows symlinks, otherwise the target's contents outside the
            // session directory could be wiped.
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
