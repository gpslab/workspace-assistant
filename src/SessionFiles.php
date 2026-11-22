<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Deletion of agent session transcripts from disk.
 *
 * Claude stores each session as `<projects>/<slug>/<session_id>.jsonl` (+
 * a same-named helper directory `<session_id>/`); Kimi stores each session as the directory
 * `<kimi home>/sessions/wd_<dir>_<hash>/session_<uuid>/` (its `state.json` and the per-agent
 * `wire.jsonl`). `/new` and `/compact`
 * detach the old session in the DB, but the files on disk stay as dead weight;
 * they can only be returned to via `claude --resume` / `kimi -S` from the console. Here we delete
 * them at the moment of an explicit reset, when `session_id` is known for certain.
 *
 * Neither the Claude slug (encoded cwd) nor the Kimi `wd_<dir>_<hash>` bucket is reconstructed:
 * both are brittle against the encoding algorithm; the id is found via a glob across all projects
 * (Claude) or across all buckets of the home (Kimi), because a session id is globally unique.
 */
final class SessionFiles
{
    /** A Kimi session id is `session_<uuid>`: the prefix and 36 characters of a lowercase UUID. */
    private const string KIMI_SESSION_ID_PATTERN = '/^session_[0-9a-f-]{36}$/';

    /** Kimi keeps the sessions of every working directory in its own bucket under this subdirectory. */
    private const string KIMI_SESSIONS_SUBDIR = '/sessions/wd_*';

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
     * Best-effort deletion of a session in the layout of the engine that owns it.
     *
     * On {@see Config::ENGINE_KIMI} the session is the directory `sessions/wd_<dir>_<hash>/<id>/` of the
     * Kimi home; on every other engine (Claude and OpenCode, whose sessions have no file of this shape) it is the
     * transcript file `<projects>/<slug>/<id>.jsonl` and the same-named directory `<id>/`.
     *
     * Throws no exceptions: the calling flow (/new, /compact) must not fail
     * because of filesystem problems. An id that does not match the shape of its own layout is
     * ignored as protection against a stray glob.
     *
     * @param ?string $engine   the engine of the instance ({@see Config::$engine}); null = Claude
     * @param ?string $kimiHome the Kimi home of the instance ({@see Config::$kimiHome}); null/'' =
     *                          `KIMI_CODE_HOME` of the process, otherwise `$HOME/.kimi-code`
     */
    public function delete(string $sessionId, ?string $engine = null, ?string $kimiHome = null): void
    {
        if ($engine === Config::ENGINE_KIMI) {
            $this->deleteKimiSession($sessionId, $kimiHome);

            return;
        }

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

    /**
     * Best-effort deletion of the Kimi session directory `session_<uuid>` from every bucket of the
     * home. The bucket (`wd_<dir>_<hash>`, derived from the cwd of the run) is not reconstructed: the
     * id is unique, so it is found by a glob. A matched path that is a symlink is unlinked as a link,
     * never followed — the rule of {@see self::removeDir()} applies to the session directory itself too.
     */
    private function deleteKimiSession(string $sessionId, ?string $kimiHome): void
    {
        if (preg_match(self::KIMI_SESSION_ID_PATTERN, $sessionId) !== 1) {
            return;
        }

        $pattern = $this->resolveKimiHome($kimiHome).self::KIMI_SESSIONS_SUBDIR.'/'.$sessionId;
        foreach (glob($pattern) ?: [] as $path) {
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            }
        }
    }

    /**
     * The Kimi home the sessions are looked up in, resolved the way the engine resolves it: the home
     * of the instance when set, otherwise `KIMI_CODE_HOME` of the process (the variable the engine
     * itself relocates its sessions with), otherwise `$HOME/.kimi-code`.
     */
    private function resolveKimiHome(?string $kimiHome): string
    {
        if ($kimiHome !== null && $kimiHome !== '') {
            return rtrim($kimiHome, '/');
        }

        $fromEnv = getenv('KIMI_CODE_HOME');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $home = getenv('HOME');

        return (is_string($home) ? $home : '').'/.kimi-code';
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
