<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * The scan of the `pre-push` hook: reads the hook's stdin (`<local ref> <local sha> <remote ref>
 * <remote sha>` per line) and looks for secrets in the commits that are about to be pushed.
 *
 * A ref deletion is skipped. A new branch (zero remote sha), or a remote sha this repository does
 * not have, scans `<local sha> --not --remotes`. A scan that cannot run fails closed.
 */
final readonly class PrePushScan
{
    private const string ZERO = '/^0+$/';

    private const array PATCH = [
        '-c', 'core.quotePath=false', 'log', '-p', '-U0', '--no-color', '--no-ext-diff', '--no-textconv',
        '--src-prefix=a/', '--dst-prefix=b/', '--format=commit:%h',
    ];

    /**
     * @param list<string> $scanExclude `SECRET_SCAN_EXCLUDE`
     */
    public function __construct(private string $repoDir, private array $scanExclude = [])
    {
    }

    /**
     * @return list<OutgoingSecret>
     *
     * @throws \RuntimeException when a range cannot be scanned
     */
    public function scan(string $stdin): array
    {
        $patches = new PatchSecrets($this->scanExclude);
        $found = [];
        foreach (explode("\n", $stdin) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 4) {
                continue;
            }
            [, $local, , $remote] = $fields;
            if (preg_match(self::ZERO, $local) === 1) {
                continue;
            }

            $patch = null;
            if (preg_match(self::ZERO, $remote) !== 1) {
                $patch = $this->git([...self::PATCH, $remote.'..'.$local]);
            }
            $patch ??= $this->git([...self::PATCH, $local, '--not', '--remotes']) ?? throw new \RuntimeException('git log failed for '.$local);
            foreach ($patches->scan($patch, true) as $secret) {
                $found[$secret->location().' '.$secret->type] = $secret;
            }
        }

        return array_values($found);
    }

    /**
     * @param list<string> $args
     *
     * @return string|null null when git fails
     */
    private function git(array $args): ?string
    {
        $proc = proc_open(['git', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->repoDir);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot run git');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($proc) === 0 ? $out : null;
    }
}
