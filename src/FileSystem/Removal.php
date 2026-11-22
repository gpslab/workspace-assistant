<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\FileSystem;

/**
 * Removal of a file or a directory tree.
 *
 * A symlink is removed as a link, never followed: a run home holds symlinks into the user's Kimi
 * home (`credentials/`, `sessions/`, `oauth/`), and removing its target tree would delete the
 * login and the sessions of every topic.
 */
final class Removal
{
    public static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }

    private function __construct()
    {
    }
}
