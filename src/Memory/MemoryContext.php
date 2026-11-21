<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Memory;

use GpsLab\WorkspaceAssistant\Log;
use GpsLab\WorkspaceAssistant\PromptFiles;

/**
 * The memory context injected into the system prompt of an engine that does not read the user scope.
 *
 * `memory-3layer context` prints the PATHS of the instance's memory files (L0, the project's
 * SESSION.md and project.md); the data itself lives outside the managed repository, where a run of such
 * an engine cannot read it. The bot therefore reads those files itself and injects their CONTENT, so the
 * run needs no permission to read outside the repository.
 *
 * Every call is bounded and best-effort: a missing, failing or hung client gives an empty block and a
 * line in the log, never an exception and never a failed run. The client is off unless
 * `MEMORY_3LAYER_BIN` is set ({@see \GpsLab\WorkspaceAssistant\Config::memoryBin()}); on the engine that
 * reads the user scope the block is empty by construction, because that scope already delivers memory.
 */
final class MemoryContext
{
    /** Bounded wait for `memory-3layer context`: a hung client must not delay a run for long. */
    public const int DEFAULT_TIMEOUT_SEC = 10;

    /**
     * @param string $bin        path of the `memory-3layer` client; '' turns the feature off
     * @param string $repoPath   working directory of the client: the memory of a project is resolved
     *                           from the repository the run works in
     * @param int    $maxBytes   ceiling of the injected part of ONE file; the newest bytes win
     * @param int    $timeoutSec ceiling of one client call
     */
    public function __construct(
        private readonly string $bin,
        private readonly string $repoPath,
        private readonly int $maxBytes,
        private readonly int $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
    ) {
    }

    /** Whether the feature is on for this run. */
    public function enabled(): bool
    {
        return $this->bin !== '' && $this->maxBytes > 0;
    }

    /**
     * The block appended to the system prompt: the content of every readable file the client names,
     * each under a heading of its own. '' when the feature is off, the client fails or there is nothing
     * to inject (a project without memory yet). Prompt-file problems (an unfilled placeholder of a local
     * override) are added to $warnings, as in every other prompt text.
     *
     * @param list<string> $warnings
     */
    public function block(array &$warnings = []): string
    {
        if (!$this->enabled()) {
            return '';
        }

        $files = [];
        foreach ($this->paths() as $path) {
            $content = $this->content($path);
            if ($content === null) {
                continue;
            }
            // A long-lived project has a SESSION.md of hundreds of KB: only its newest part is
            // injected, and the heading says so, so the model does not read a cut as the whole file.
            $cut = strlen($content) > $this->maxBytes;
            $files[] = '### '.basename($path).($cut ? ' (cut: the newest part only)' : '')
                ."\n\n".($cut ? self::tail($content, $this->maxBytes) : $content);
        }
        if ($files === []) {
            return '';
        }

        return PromptFiles::render('memory_context', ['memory_context' => implode("\n\n", $files)], $warnings);
    }

    /**
     * The readable files `memory-3layer context` names, in the order the client printed them.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        [$out, $error] = $this->client('context');
        if ($error !== '') {
            Log::line('memory-3layer context: '.$error);

            return [];
        }

        $paths = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // The client labels what it names (`L0: <path>`, `session: <path>`); only the path-like
            // tokens are read, whatever the labels are and whether they share a line. A token that is
            // not a readable file is dropped later, silently: a project without memory yet is normal.
            preg_match_all('#(?:~|/)\S*#', $line, $m);
            foreach ($m[0] as $path) {
                $path = $this->expandHome($path);
                if ($path !== '' && !in_array($path, $paths, true)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /** `~/x` as the client may report its data directory; an unset HOME leaves the path as it is. */
    private function expandHome(string $path): string
    {
        if (!str_starts_with($path, '~/')) {
            return $path;
        }
        $home = (string) getenv('HOME');

        return $home === '' ? $path : rtrim($home, '/').substr($path, 1);
    }

    /** The content of one memory file, or null when it is missing, unreadable or empty. */
    private function content(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $content = trim(str_replace("\r\n", "\n", $content));

        return $content === '' ? null : $content;
    }

    /** The newest $bytes of the text, never starting in the middle of a UTF-8 character. */
    private static function tail(string $content, int $bytes): string
    {
        $tail = substr($content, -$bytes);
        while ($tail !== '' && (ord($tail[0]) & 0xC0) === 0x80) {
            $tail = substr($tail, 1);
        }

        return $tail;
    }

    /**
     * One bounded run of the client: its stdout and the error text ('' on success). The call never
     * throws and never blocks longer than the timeout — a killed client counts as a failure like any
     * other, the caller only reports it.
     *
     * @return array{0: string, 1: string}
     */
    private function client(string $verb): array
    {
        // The command is an argument array: no shell, no injection. The client is a path from the
        // settings, the verb is one of the bot's own constants.
        $proc = @proc_open(
            [$this->bin, $verb],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoPath,
        );
        if (!is_resource($proc)) {
            return ['', 'the client '.$this->bin.' could not be started'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + max(1, $this->timeoutSec);
        $status = ['running' => true, 'exitcode' => 0];
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if ($status['running'] !== true) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9); // SIGKILL
                self::close($proc, $pipes);

                return ['', 'the client did not answer within '.max(1, $this->timeoutSec).'s and was killed'];
            }
            // Non-blocking reads: the pipes cannot fill up and block the client while we wait.
            usleep(20000);
        }
        // Bytes written between the last read and the exit.
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $exit = (int) $status['exitcode'];
        self::close($proc, $pipes);

        if ($exit !== 0) {
            return ['', 'the client exited with code '.$exit.($err !== '' ? ': '.trim($err) : '')];
        }

        return [$out, ''];
    }

    /**
     * @param resource             $proc
     * @param array<int, resource> $pipes
     */
    private static function close($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($proc);
    }
}
