<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Secrets in the added lines of a patch (`git diff -p` / `git log -p` with `--format=commit:<hash>`),
 * for the commit gate and the `pre-push` hook. Shape patterns do not fire in `SECRET_SCAN_EXCLUDE` paths.
 */
final readonly class PatchSecrets
{
    /**
     * @param list<string> $scanExclude `SECRET_SCAN_EXCLUDE`: path globs where only known values are looked for
     */
    public function __construct(private array $scanExclude = [])
    {
    }

    /**
     * @param bool $withCommits the patch comes from `git log` with `commit:<hash>` lines
     *
     * @return list<OutgoingSecret>
     */
    public function scan(string $patch, bool $withCommits): array
    {
        /** @var array<string, array{commit: ?string, path: string, numbers: list<int>, lines: list<string>}> $files */
        $files = [];
        $commit = null;
        $path = null;
        $inHunk = false;
        $number = 0;
        foreach (explode("\n", $patch) as $line) {
            if ($withCommits && str_starts_with($line, 'commit:')) {
                $commit = substr($line, 7);
                $path = null;
                $inHunk = false;
            } elseif (str_starts_with($line, 'diff --git ')) {
                $path = null;
                $inHunk = false;
            } elseif (!$inHunk && str_starts_with($line, '+++ ')) {
                $path = self::patchPath(substr($line, 4));
            } elseif (str_starts_with($line, '@@ ')) {
                $inHunk = $path !== null;
                $number = preg_match('/^@@ -\S+ \+(\d+)/', $line, $m) === 1 ? (int) $m[1] : 1;
            } elseif ($inHunk && $path !== null && str_starts_with($line, '+')) {
                $key = $commit."\0".$path;
                $files[$key] ??= ['commit' => $commit, 'path' => $path, 'numbers' => [], 'lines' => []];
                $files[$key]['numbers'][] = $number++;
                $files[$key]['lines'][] = substr($line, 1);
            }
        }

        $scanner = SecretScanner::shared();
        $found = [];
        foreach ($files as $file) {
            $shapes = !$this->isExcluded($file['path']);
            foreach ($scanner->scan(implode("\n", $file['lines']), $shapes)['findings'] as $finding) {
                $secret = new OutgoingSecret($finding->type, $file['path'], $file['numbers'][$finding->line - 1] ?? 0, $file['commit']);
                $found[$secret->location().' '.$secret->type] = $secret;
            }
        }

        return array_values($found);
    }

    /** Path of a `+++ ` header: `b/<path>`, quoted by git when it has odd characters, null for a deleted file. */
    private static function patchPath(string $header): ?string
    {
        $header = rtrim($header, "\t");
        if (str_starts_with($header, '"') && str_ends_with($header, '"')) {
            $header = stripcslashes(substr($header, 1, -1));
        }

        return str_starts_with($header, 'b/') ? substr($header, 2) : null;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->scanExclude as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
        }

        return false;
    }
}
