<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Asynchronous launch of the Claude CLI in -p mode.
 *
 * The process starts non-blocking: the bot's main loop keeps polling
 * Telegram (so /cancel works), calling poll() periodically.
 *
 * Arguments are passed as an array to proc_open: no shell, no injection.
 */
final class ClaudeRunner
{
    /** How long (sec) poll() keeps reading the run files for a missing result after the process has exited. */
    private const EOF_WAIT_SEC = 5;
    private const DIAG_STDERR_BYTES = 16384;
    private const DIAG_KEEP_FILES = 20;

    /** How many trailing stream-json lines go to the no-result diagnostics file. */
    private const DIAG_LINES = 50;

    /** @var resource|null */
    private $proc;

    /**
     * Run files receiving the process stdout (1) and stderr (2); read by offset.
     *
     * @var array<int, string>
     */
    private array $files = [];

    /** @var array<int, int> */
    private array $offsets = [1 => 0, 2 => 0];

    /** Per-run temporary directory (when stateDir is empty); removed together with the run files. */
    private ?string $tmpDir = null;

    /** The per-run `--agents` file; removed together with the run files. */
    private ?string $agentsFile = null;

    private string $runId = '';

    private ?float $exitedAt = null;

    private string $stdout = '';
    private string $stderr = '';
    private int $startedAt;
    private int $lastProgressAt;
    private int $pid = 0;
    private ?ClaudeResult $result = null;
    private string $timeoutReason = '';

    /**
     * Moment of the last result after which the stream goes silent; null means no answer
     * or work continued after it (any other event arrived).
     */
    private ?int $resultAt = null;

    /** Whether a result with non-empty text has already been seen; an empty one does not overwrite it. */
    private bool $hasAnswer = false;

    /** session_id from the event stream (init/result); known already while the run is in progress. */
    private ?string $sessionId = null;

    /** Incomplete stream-json line carried over between pipe reads. */
    private string $lineBuf = '';

    /** @var list<string> last raw stream-json lines, for the no-result diagnostics file */
    private array $recentLines = [];

    /** Text of the last text-bearing assistant event; fallback answer when no result arrives. */
    private string $lastAssistantText = '';
    private int $assistantInputTokens = 0;
    private int $assistantOutputTokens = 0;
    private ?string $diagnosticsPath = null;

    /** @var list<string> human-readable lines for tool calls (live progress) */
    private array $progressLines = [];

    /**
     * @param string[]              $extraArgs
     * @param int                   $timeout         overall ceiling for a run (sec); 0 disables it, leaving
     *                                               only $stallTimeout as the limiter
     * @param int                   $stallTimeout    if the process emits no byte for longer than this
     *                                               (sec) it is considered hung and killed; 0 disables it
     * @param int                   $resultGrace     how long to tolerate silence after the result while the
     *                                               process has not exited (sec), then it is finished off; see poll()
     * @param array<string, string> $extraEnv        extra process environment variables on top of the bot's environment
     * @param string                $stateDir        where claude-no-result-<time>.log diagnostics go; empty disables them
     * @param bool                  $withAgents      hand the built-in sub-agents over via --agents (not for /compact)
     * @param string                $homeDir         home of the user the bot runs as, for ~/.claude/agents; empty skips it
     * @param bool                  $updateOnResume  on --resume, prepend the instructions-update block when the prompt hash differs from $savedPromptHash
     * @param string|null           $savedPromptHash hash of the system prompt the session was last run with; null = unknown
     */
    public function __construct(
        private readonly string $claudeBin,
        private readonly string $repoPath,
        private readonly int $timeout,
        private readonly array $extraArgs,
        private readonly int $stallTimeout = 0,
        private readonly string $appendSystemPrompt = '',
        private readonly string $mode = SystemPrompt::MODE_ARCH,
        private readonly int $resultGrace = 15,
        private readonly string $settingsJson = '',
        private readonly array $extraEnv = [],
        private readonly string $promptsDir = '',
        private readonly Locale $locale = new Locale(),
        private readonly string $stateDir = '',
        private readonly bool $withAgents = true,
        private readonly string $homeDir = '',
        private readonly bool $updateOnResume = false,
        private readonly ?string $savedPromptHash = null,
        private readonly string $forge = Config::FORGE_GITHUB,
    ) {
    }

    /** @var list<string> */
    private array $promptWarnings = [];

    private ?string $promptHash = null;

    /** Hash of the system prompt passed in argv by the last start(); null before it. */
    public function promptHash(): ?string
    {
        return $this->promptHash;
    }

    /**
     * Persona-file problems of the last start() (see {@see SystemPrompt::build()}).
     *
     * @return list<string>
     */
    public function promptWarnings(): array
    {
        return $this->promptWarnings;
    }

    /**
     * Defangs the markers of the instructions-update block in text that is not ours, so that
     * user, forwarded or fetched content cannot pass for the block when glued to the message.
     */
    public static function neutralizeUpdateMarkers(string $text): string
    {
        return (string) preg_replace(
            ['/instructions\s+of\s+this\s+session\s+have\s+been\s+updated/i', '/This\s+block\s+replaces\s+ALL\s+instructions/i', '/-{2,}\s*New\s+instructions\s*-{2,}/i'],
            '[update marker removed]',
            $text,
        );
    }

    public function start(string $prompt, ?string $sessionId): void
    {
        $this->promptHash = null;
        $prompt = self::neutralizeUpdateMarkers($prompt);
        // stream-json gives a stream of events (tool_use as work progresses): this is both
        // live progress and a heartbeat for stallTimeout. Requires --verbose with -p.
        $argv = [$this->claudeBin, '-p', $prompt, '--output-format', 'stream-json', '--verbose'];
        if ($sessionId !== null) {
            $argv[] = '--resume';
            $argv[] = $sessionId;
        }
        foreach ($this->extraArgs as $arg) {
            $argv[] = $arg;
        }
        // The system prompt comes from files (SystemPrompt, prompts/*.md.dist under git): these are the rules
        // for Claude's work in the project; forMode() assembles the common part plus the role block
        // of the current mode. An optional per-instance addition from .env
        // (CLAUDE_APPEND_SYSTEM_PROMPT) is appended AFTER it, so the base rules
        // stay unchanged. A single --append-system-prompt flag, to avoid conflicts.
        $built = SystemPrompt::build($this->mode, $this->promptsDir !== '' ? $this->repoPath.'/'.$this->promptsDir : null, $this->promptsDir !== '' ? $this->promptsDir : null, $this->forge);
        $this->promptWarnings = $built['warnings'];
        $systemPrompt = $built['text'];
        if ($this->appendSystemPrompt !== '') {
            $systemPrompt .= "\n\n".$this->appendSystemPrompt;
        }
        // The language line goes last: it must override the persona and the append text.
        $systemPrompt .= "\n\n".SystemPrompt::languageLine($this->locale->languageName(), $this->promptWarnings);
        $this->promptHash = hash('sha256', $systemPrompt);
        if ($sessionId !== null && $this->updateOnResume && $this->savedPromptHash !== $this->promptHash) {
            // A resumed session keeps the system prompt it was created with: hand the new one over in the message.
            $update = PromptFiles::render('session_update', ['prompt' => $systemPrompt], $this->promptWarnings)."\n\n".$prompt;
            $argv = [...array_slice($argv, 0, 2), $update, ...array_slice($argv, 3)];
        }
        $agents = [];
        if ($this->withAgents) {
            if ($this->hasAgentsArg()) {
                $this->promptWarnings[] = 'CLAUDE_EXTRA_ARGS contains --agents: the built-in sub-agents (critic, verifier, editor, scope-check, acceptor, security) are not passed. Remove it to get them.';
            } else {
                $agents = BuiltinAgents::build($this->repoPath, $this->homeDir, $this->promptWarnings);
            }
        }
        $argv[] = '--append-system-prompt';
        $argv[] = $systemPrompt;
        // Extra run settings (mode hooks; see ReviewStatusHook); hooks from
        // --settings are merged with the user's own, not replacing them.
        if ($this->settingsJson !== '') {
            $argv[] = '--settings';
            $argv[] = $this->settingsJson;
        }

        // stdout/stderr go to files, not pipes: a pipe holds 64 KiB, and when the main loop is busy
        // and does not read it, the claude CLI drops what did not fit on exit. A file never blocks.
        [$outFile, $errFile] = $this->openRunFiles();
        if ($agents !== []) {
            try {
                $argv[] = '--agents';
                $argv[] = $this->writeAgentsFile($agents);
            } catch (\RuntimeException $e) {
                fclose($outFile);
                fclose($errFile);
                $this->removeRunFiles();

                throw $e;
            }
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $outFile,
            2 => $errFile,
        ];

        // env = null: inherit the bot's environment (HOME, PATH, etc. that claude needs).
        // With extra variables env must be complete, so they are appended to the bot's environment.
        $env = $this->extraEnv === [] ? null : array_merge(getenv(), $this->extraEnv);
        $proc = proc_open($argv, $descriptors, $pipes, $this->repoPath, $env);
        // The child has its own copies of the descriptors.
        fclose($outFile);
        fclose($errFile);
        if (!is_resource($proc)) {
            $this->removeRunFiles();
            throw new \RuntimeException('Failed to start claude.');
        }

        $this->proc = $proc;
        fclose($pipes[0]);
        $this->offsets = [1 => 0, 2 => 0];
        $this->exitedAt = null;

        $this->startedAt = time();
        $this->lastProgressAt = time();
        $this->pid = (int) proc_get_status($proc)['pid'];
    }

    /** Reason for forced termination (for RunnerState::Timeout). */
    public function timeoutReason(): string
    {
        return $this->timeoutReason;
    }

    /**
     * session_id of the current run as soon as it is seen in the stream (init event).
     * null until the first event. Needed to persist the session while work is in progress.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * session_id from a single stream-json event (init/result carry it). A pure
     * function, for unit tests and reuse in handleLine.
     *
     * @param array<string, mixed> $event
     */
    public static function sessionIdFromEvent(array $event): ?string
    {
        $sid = $event['session_id'] ?? null;

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    /**
     * Accumulated live-progress lines (tool calls) as work progresses.
     *
     * @return list<string>
     */
    public function getProgressLines(): array
    {
        return $this->progressLines;
    }

    /** Path of the diagnostics file written when the run ended without a result event, if any. */
    public function diagnosticsPath(): ?string
    {
        return $this->diagnosticsPath;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Non-blocking state check. On RunnerState::Done the result is available
     * via result().
     */
    public function poll(): RunnerState
    {
        $proc = $this->proc;
        if (!is_resource($proc)) {
            throw new \RuntimeException('poll() without an active process.');
        }

        $this->drain();
        $status = proc_get_status($proc);

        if ($this->timeout > 0 && time() - $this->startedAt > $this->timeout) {
            $this->timeoutReason = $this->locale->t('runner.timeout_total', ['seconds' => $this->timeout]);
            $this->kill();

            return RunnerState::Timeout;
        }

        // Activity control: the process is alive but has emitted nothing for a long time, so it is hung.
        if ($this->stallTimeout > 0 && time() - $this->lastProgressAt > $this->stallTimeout) {
            $this->timeoutReason = $this->locale->t('runner.timeout_stall', ['seconds' => $this->stallTimeout]);
            $this->kill();

            return RunnerState::Timeout;
        }

        // The answer was already emitted, the stream is silent after it, and the process is alive: claude -p does not
        // exit while a background task runs (Monitor holds it until its own
        // timeout, up to 10 min), and then makes an extra service turn. The user
        // only needs the answer, so the process is finished off together with background tasks.
        // The result is not necessarily the first: on --resume claude first processes
        // accumulated task-notifications (its own empty result), and only then the
        // prompt; its events reset resultAt, see handleLine().
        $answeredAndLingering = $status['running'] === true
            && $this->resultAt !== null
            && time() - $this->resultAt >= $this->resultGrace;

        if ($status['running'] === true && !$answeredAndLingering) {
            return RunnerState::Running;
        }

        // The process has finished (or been finished off after the answer): read the files to their
        // current end, flush the buffer tail and close.
        if ($answeredAndLingering) {
            $this->terminate();
            $exit = 0;
            $this->drain();
        } else {
            $exit = (int) $status['exitcode'];
            // Bytes written between the previous drain() and the exit.
            $this->drain();
            // No result yet: a descendant (background task) may append it after the parent exited.
            // Keep reading on the next passes, without blocking the main loop.
            $this->exitedAt ??= microtime(true);
            if ($this->stdout === '' && microtime(true) - $this->exitedAt < self::EOF_WAIT_SEC) {
                return RunnerState::Running;
            }
        }
        if ($this->lineBuf !== '') {
            $this->handleLine($this->lineBuf);
            $this->lineBuf = '';
        }
        $this->closeProc();

        $result = ClaudeResult::fromJson($this->stdout, $this->stderr, $exit, $this->locale);
        if ($exit === 0 && $this->stdout === '') {
            $this->diagnosticsPath = $this->writeDiagnostics();
            $result = $this->lastAssistantText !== ''
                ? new ClaudeResult(false, $this->lastAssistantText, $this->sessionId, $this->assistantInputTokens, $this->assistantOutputTokens, true, true)
                : new ClaudeResult(true, $result->text, $result->sessionId ?? $this->sessionId, 0, 0, true);
        }
        $this->result = $result;

        return RunnerState::Done;
    }

    /**
     * Result of a finished call. Valid only after poll() === RunnerState::Done.
     */
    public function result(): ClaudeResult
    {
        if ($this->result === null) {
            throw new \RuntimeException('result() before the process finished.');
        }

        return $this->result;
    }

    private function drain(): void
    {
        $got = false;

        $chunk = $this->readNew(1);
        if ($chunk !== '') {
            $got = true;
            $this->ingestStdout($chunk);
        }
        $err = $this->readNew(2);
        if ($err !== '') {
            $got = true;
            $this->stderr .= $err;
        }

        // Any new output = heartbeat: the stream-json event stream arrives
        // as Claude works, so stallTimeout is no longer "blind".
        if ($got) {
            $this->lastProgressAt = time();
        }
    }

    /** New bytes of a run file since the last read. */
    private function readNew(int $fd): string
    {
        if (!isset($this->files[$fd])) {
            return '';
        }
        $h = @fopen($this->files[$fd], 'r');
        if ($h === false) {
            return '';
        }
        $chunk = '';
        if (fseek($h, $this->offsets[$fd]) === 0) {
            $chunk = stream_get_contents($h) ?: '';
        }
        fclose($h);
        $this->offsets[$fd] += strlen($chunk);

        return $chunk;
    }

    /**
     * Creates the run files (0600, regardless of umask) and returns them open for writing.
     *
     * @return array{resource, resource}
     */
    private function openRunFiles(): array
    {
        if ($this->stateDir !== '') {
            $dir = $this->stateDir.'/runs';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the run directory.');
            }
        } else {
            $dir = sys_get_temp_dir().'/claude-run-'.bin2hex(random_bytes(8));
            if (!@mkdir($dir, 0700)) {
                throw new \RuntimeException('Cannot create the run directory.');
            }
            $this->tmpDir = $dir;
        }

        $id = date('Ymd-His').'-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->runId = $id;
        $this->files = [1 => $dir.'/'.$id.'.out', 2 => $dir.'/'.$id.'.err'];
        $handles = [];
        foreach ($this->files as $fd => $path) {
            $h = fopen($path, 'w');
            if ($h === false) {
                $this->removeRunFiles();
                throw new \RuntimeException('Cannot create a run file.');
            }
            chmod($path, 0600);
            $handles[$fd] = $h;
        }

        return [$handles[1], $handles[2]];
    }

    private function hasAgentsArg(): bool
    {
        foreach ($this->extraArgs as $arg) {
            if ($arg === '--agents' || str_starts_with($arg, '--agents=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes the agents JSON next to the run files (same run id), 0600; returns its path.
     *
     * @param array<string, array<string, mixed>> $agents
     */
    private function writeAgentsFile(array $agents): string
    {
        $dir = $this->stateDir !== '' ? $this->stateDir.'/agents' : (string) $this->tmpDir;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the agents directory.');
        }
        $path = $dir.'/'.$this->runId.'.json';
        try {
            $json = json_encode($agents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Cannot encode the agents file: '.$e->getMessage(), 0, $e);
        }
        $old = umask(0177);
        $written = @file_put_contents($path, $json);
        umask($old);
        if ($written === false) {
            throw new \RuntimeException('Cannot write the agents file.');
        }
        $this->agentsFile = $path;

        return $path;
    }

    private function removeRunFiles(): void
    {
        if ($this->agentsFile !== null) {
            @unlink($this->agentsFile);
            $this->agentsFile = null;
        }
        foreach ($this->files as $path) {
            @unlink($path);
        }
        $this->files = [];
        if ($this->tmpDir !== null) {
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    /** Removes leftover run files of previous bot runs; called once at bot start. */
    public static function purgeRunFiles(string $stateDir): void
    {
        if ($stateDir === '') {
            return;
        }
        foreach ([...(glob($stateDir.'/runs/*') ?: []), ...(glob($stateDir.'/agents/*') ?: [])] as $path) {
            // A file named after a live process belongs to a running instance: leave it alone.
            if (preg_match('/^\d{8}-\d{6}-(\d+)-/', basename($path), $m) === 1 && self::isAlive((int) $m[1])) {
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            // EPERM (1) means the process exists but belongs to another user.
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }

        return is_dir('/proc/'.$pid);
    }

    /** Saves the stream tail and stderr of a run without a result; returns the path or null. */
    private function writeDiagnostics(): ?string
    {
        if ($this->stateDir === '') {
            return null;
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0775, true) && !is_dir($this->stateDir)) {
            return null;
        }
        $path = $this->stateDir.'/claude-no-result-'.date('Ymd-His').'-'.$this->pid.'.log';
        // Only event types are kept (tool output may contain secrets); stderr is capped.
        $body = '== stream-json events (last '.self::DIAG_LINES." lines, types only) ==\n".implode("\n", $this->recentLines)
            ."\n== stderr (first ".self::DIAG_STDERR_BYTES." bytes) ==\n".substr($this->stderr, 0, self::DIAG_STDERR_BYTES)."\n";

        $old = umask(0177);
        $written = @file_put_contents($path, $body);
        umask($old);
        if ($written === false) {
            return null;
        }
        @chmod($path, 0600);

        // Keep only the newest diagnostics files.
        $files = glob($this->stateDir.'/claude-no-result-*.log') ?: [];
        rsort($files);
        foreach (array_slice($files, self::DIAG_KEEP_FILES) as $stale) {
            @unlink($stale);
        }

        return $path;
    }

    /** Appends the chunk to the buffer and processes all completed lines (JSONL). */
    private function ingestStdout(string $chunk): void
    {
        $this->lineBuf .= $chunk;
        while (($nl = strpos($this->lineBuf, "\n")) !== false) {
            $line = substr($this->lineBuf, 0, $nl);
            $this->lineBuf = substr($this->lineBuf, $nl + 1);
            $this->handleLine($line);
        }
    }

    /** Parses one stream-json line: result -> stdout, assistant -> progress. */
    private function handleLine(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($line, true);

        $this->recentLines[] = is_array($event)
            ? (is_string($event['type'] ?? null) ? $event['type'] : '?').(is_string($event['subtype'] ?? null) ? '/'.$event['subtype'] : '')
            : '(non-JSON line, '.strlen($line).' bytes)';
        if (count($this->recentLines) > self::DIAG_LINES) {
            array_shift($this->recentLines);
        }

        if (!is_array($event)) {
            return; // non-JSON line: ignore
        }

        // session_id arrives in the very first event (system/init) and is repeated in
        // result. Catch it immediately so the bot can save the session before
        // the run may be killed (/cancel, timeout).
        $sid = self::sessionIdFromEvent($event);
        if ($sid !== null) {
            $this->sessionId = $sid;
        }

        // The result event is put into stdout as a line for ClaudeResult::fromJson.
        // An empty result (a turn from a task-notification without an answer) does not overwrite
        // an already received answer. The service turn after silence does not reach us:
        // the process is finished off in poll() by then.
        if (($event['type'] ?? null) === 'result') {
            $text = $event['result'] ?? null;
            $nonEmpty = is_string($text) && trim($text) !== '';
            if ($nonEmpty || !$this->hasAnswer) {
                $this->stdout = $line;
                $this->hasAnswer = $this->hasAnswer || $nonEmpty;
            }
            $this->resultAt = time();

            return;
        }

        // Any other event after result means work continues (the next turn).
        $this->resultAt = null;

        if (($event['type'] ?? null) === 'assistant') {
            $this->rememberAssistant($event);
        }

        foreach (self::progressLinesFromEvent($event) as $progressLine) {
            $this->progressLines[] = $progressLine;
        }
    }

    /**
     * Remembers the text of an assistant event as a fallback answer (see poll()).
     *
     * @param array<string, mixed> $event
     */
    private function rememberAssistant(array $event): void
    {
        $message = $event['message'] ?? null;
        if (!is_array($message) || !is_array($message['content'] ?? null)) {
            return;
        }
        $text = '';
        foreach ($message['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            return;
        }
        $this->lastAssistantText = trim($text);
        $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
        $this->assistantInputTokens = (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $this->assistantOutputTokens = (int) ($usage['output_tokens'] ?? 0);
    }

    /**
     * Extracts live-progress lines from a single stream-json event (in order of
     * appearance): Claude's text speech (`text` blocks are free, already in the stream)
     * and tool calls (`tool_use`). `thinking` blocks are deliberately NOT taken
     * (paid output tokens). A pure function, for parser unit tests.
     *
     * @param array<string, mixed> $event
     *
     * @return list<string>
     */
    public static function progressLinesFromEvent(array $event): array
    {
        if (($event['type'] ?? null) !== 'assistant') {
            return [];
        }

        $message = $event['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (!is_array($content)) {
            return [];
        }

        $lines = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'text') {
                $raw = trim((string) ($block['text'] ?? ''));
                if ($raw !== '') {
                    $lines[] = '💬 '.self::clip(self::stripMarkdown($raw), 180);
                }
            } elseif ($type === 'tool_use') {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $lines[] = self::formatToolUse((string) ($block['name'] ?? ''), $input);
            }
        }

        return $lines;
    }

    /**
     * Human-readable line for a tool call (for the progress message).
     *
     * @param array<string, mixed> $input
     */
    public static function formatToolUse(string $name, array $input): string
    {
        return match ($name) {
            'Read' => '📖 '.self::baseName((string) ($input['file_path'] ?? '')),
            'Edit' => '✏️ '.self::baseName((string) ($input['file_path'] ?? '')),
            'Write' => '📝 '.self::baseName((string) ($input['file_path'] ?? '')),
            'Bash' => '🔧 '.self::firstRealBashLine((string) ($input['command'] ?? '')),
            'Agent' => '🤖 '.self::clip((string) ($input['description'] ?? '')),
            default => '🔨 '.($name !== '' ? $name : 'Tool'),
        };
    }

    private static function baseName(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return '?';
        }
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    private static function clip(string $s, int $max = 60): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            return '?';
        }

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }

    /**
     * First non-comment line of a bash command (skipping # and blank ones).
     * If the command consists only of comments, take the whole text as a fallback.
     */
    private static function firstRealBashLine(string $command): string
    {
        $lines = preg_split('/\r?\n/', $command) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                return self::clip($line);
            }
        }

        return self::clip($command);
    }

    /**
     * Strips markdown markup before showing in plain-text progress.
     */
    private static function stripMarkdown(string $s): string
    {
        // Bold/italic/bold-italic
        $s = (string) preg_replace('/\*{1,3}(.*?)\*{1,3}/us', '$1', $s);
        // Inline code
        $s = (string) preg_replace('/`+([^`]+)`+/us', '$1', $s);
        // Strikethrough
        $s = (string) preg_replace('/~~(.*?)~~/us', '$1', $s);
        // Headings
        $s = (string) preg_replace('/^#{1,6}\s+/mu', '', $s);

        return $s;
    }

    public function kill(): void
    {
        if (is_resource($this->proc)) {
            $this->terminate();
            $this->closeProc();
        }
    }

    /** SIGTERM, then SIGKILL if still alive. */
    private function terminate(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, 15); // SIGTERM
            usleep(300000);
            $status = proc_get_status($this->proc);
            if ($status['running'] === true) {
                proc_terminate($this->proc, 9); // SIGKILL
            }
        }
    }

    private function closeProc(): void
    {
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
        $this->proc = null;
        $this->removeRunFiles();
    }
}
