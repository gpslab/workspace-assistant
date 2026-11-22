<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\FileSystem\Removal;
use GpsLab\WorkspaceAssistant\Kimi\DeferredRunHome;
use GpsLab\WorkspaceAssistant\Kimi\KimiErrorClassifier;
use GpsLab\WorkspaceAssistant\Kimi\KimiFormatStatus;
use GpsLab\WorkspaceAssistant\Kimi\KimiRunHomeRenderer;
use GpsLab\WorkspaceAssistant\Kimi\KimiStreamParser;
use GpsLab\WorkspaceAssistant\Kimi\KimiVersionGuard;
use GpsLab\WorkspaceAssistant\Kimi\KimiWire;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireReader;
use GpsLab\WorkspaceAssistant\Memory\MemoryContext;

/**
 * Asynchronous launch of Kimi Code in `-p` mode.
 *
 * The engine-neutral contract of {@see AgentRunner}; the Kimi counterpart of {@see ClaudeRunner}
 * and {@see OpencodeRunner}. The process starts non-blocking and the bot polls {@see poll()}.
 *
 * Every run gets its own home (`state/kimi-<runId>/`) passed as `KIMI_CODE_HOME`, mode 0700. The
 * renderer writes `config.toml`, `agent.md` and `agents/`; `credentials/`, `sessions/` and `oauth/`
 * are symlinks into the source home (`KIMI_HOME`, default `~/.kimi-code`) and `region` / `device_id`
 * are copied from it. The credentials are never copied: the server rotates the refresh token, and a
 * copy would be invalidated by the next refresh. `KIMI_CODE_NO_AUTO_UPDATE=1` keeps the engine from
 * replacing itself in a run. The agent file carries the assembled system prompt, and with
 * `MEMORY_3LAYER_BIN` set the memory of the instance as well ({@see MemoryContext}): this engine reads
 * no user scope, so the memory comes from the bot. A resumed session keeps the agent file it was
 * created with, so on a resume the fresh memory is handed over in the message instead.
 *
 * The run home is not removed when the process ends: {@see purgeRunHome()} is exposed for the Bot to
 * call after its post-run checks, so the gate shim's records are still there for them.
 *
 * stdout carries no usage, model or error; after the run the runner reads the window of the
 * session's `wire.jsonl` (see {@see KimiWireReader}) and classifies a failure with
 * {@see KimiErrorClassifier}. The stall heartbeat counts stdout bytes or growth of that wire file:
 * stdout is quiet while a background sub-agent works.
 *
 * Arguments are passed as an array to proc_open: no shell, no injection.
 */
final class KimiRunner implements AgentRunner, DeferredRunHome
{
    /** How long (sec) poll() keeps reading the run files for first output after the process has exited. */
    private const int EOF_WAIT_SEC = 5;

    /** How many bytes of the head of `wire.jsonl` are read for the model and the protocol version. */
    private const int WIRE_HEAD_BYTES = 65536;

    /**
     * How far (ms) a session's `metadata.created_at` may lie from the run's launch and still count as
     * the run's own new session. The session is created within a fraction of a second of the exec, so
     * this window is wide enough for the second-granularity launch clock without admitting a session
     * another Kimi CLI started before or notably after this run.
     */
    private const int SESSION_START_SLACK_MS = 5000;

    /** Source-home entries linked into the run home. Never copied: a copied refresh token is invalid. */
    private const array LINKED_HOME_ENTRIES = ['credentials', 'sessions', 'oauth'];

    /** Source-home files copied into the run home. */
    private const array COPIED_HOME_FILES = ['region', 'device_id'];

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

    /** Per-run temporary base (when stateDir is empty); the run home lives in it until the purge. */
    private ?string $tmpDir = null;

    /** Home of this run passed as KIMI_CODE_HOME; removed by {@see purgeRunHome()}. */
    private ?string $runHome = null;

    private string $runId = '';

    private ?float $exitedAt = null;

    private string $stderr = '';
    private int $startedAt = 0;
    private int $lastProgressAt = 0;
    private int $pid = 0;
    private ?RunAnswer $result = null;
    private string $timeoutReason = '';

    /** session id of the run: known on a resume, then from `session.resume_hint` or the directory. */
    private ?string $sessionId = null;

    /** The session id the run resumed, if any. */
    private ?string $resumeSessionId = null;

    /** Incomplete stdout line carried over between reads. */
    private string $lineBuf = '';

    /** @var list<string> every complete stdout line of the run, for {@see KimiStreamParser::parse()} */
    private array $lines = [];

    /** @var list<string> human-readable lines for tool calls (live progress) */
    private array $progressLines = [];

    /** @var list<string> */
    private array $promptWarnings = [];

    private ?string $promptHash = null;

    /** `modelAlias` of the session's `profile.bind`, read from `wire.jsonl`. */
    private ?string $actualModel = null;

    /** Path of the session's main `wire.jsonl` once the session is known; null until then. */
    private ?string $wirePath = null;

    /** Byte offset of the wire window of this run (its size at launch on a resume). */
    private int $wireOffset = 0;

    /** Size of the wire file at the last heartbeat check. */
    private int $lastWireSize = 0;

    /** @var array<string, true>|null session directories that existed before this run was launched */
    private ?array $launchSessionDirs = null;

    /**
     * @param list<string>          $extraArgs       extra argv for the run (`KIMI_EXTRA_ARGS`)
     * @param int                   $timeout         overall ceiling for a run (sec); 0 disables it
     * @param int                   $stallTimeout    if neither stdout nor the session's wire.jsonl changes
     *                                               for longer than this (sec), the run is hung and killed
     * @param array<string, string> $extraEnv        extra process environment variables (gate environment)
     * @param string                $stateDir        where the run files and the run home go; empty puts them
     *                                               into a temporary directory
     * @param string                $homeDir         home of the user the bot runs as, for `~/.claude/agents`
     * @param bool                  $updateOnResume  prepend the instructions-update block when the prompt
     *                                               hash differs from $savedPromptHash
     * @param string|null           $savedPromptHash hash of the system prompt the session was last run with
     * @param string                $kimiModel       model alias passed as `-m`; '' passes none
     * @param string                $kimiHome        source home of the logged-in Kimi CLI (`KIMI_HOME`);
     *                                               '' means `<homeDir>/.kimi-code`
     * @param string                $hookShimPath    gate shim of the run; '' resolves the bot's
     *                                               `bin/kimi-hook.php`, and a missing file renders no hooks
     * @param MemoryContext|null    $memory          memory of the instance (`MEMORY_3LAYER_BIN`), injected
     *                                               into the run home's agent file on a new session and
     *                                               into the message on a resume: this engine reads no
     *                                               user scope, so the memory comes from the bot. Null = off
     */
    public function __construct(
        private readonly string $kimiBin,
        private readonly string $repoPath,
        private readonly int $timeout,
        private readonly array $extraArgs,
        private readonly int $stallTimeout = 0,
        private readonly string $appendSystemPrompt = '',
        private readonly string $mode = SystemPrompt::MODE_ARCH,
        private readonly array $extraEnv = [],
        private readonly string $promptsDir = '',
        private readonly Locale $locale = new Locale(),
        private readonly string $stateDir = '',
        private readonly string $homeDir = '',
        private readonly bool $updateOnResume = false,
        private readonly ?string $savedPromptHash = null,
        private readonly string $forge = Config::FORGE_GITHUB,
        private readonly string $kimiModel = '',
        private readonly string $kimiHome = '',
        private readonly string $hookShimPath = '',
        private readonly ?MemoryContext $memory = null,
    ) {
    }

    /** Hash of the system prompt passed to the run by the last start(); null before it. */
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

    public function start(string $prompt, ?string $sessionId): void
    {
        // A reused runner must not leave the previous run home behind: it is the caller's to purge,
        // but a new run on the same object supersedes it.
        $this->purgeRunHome();
        $this->resetState();
        $this->resumeSessionId = $sessionId;
        $this->sessionId = $sessionId;

        $prompt = ClaudeRunner::neutralizeUpdateMarkers($prompt);
        $built = SystemPrompt::build(
            $this->mode,
            $this->promptsDir !== '' ? $this->repoPath.'/'.$this->promptsDir : null,
            $this->promptsDir !== '' ? $this->promptsDir : null,
            $this->forge,
        );
        $this->promptWarnings = $built['warnings'];
        $languageLine = SystemPrompt::languageLine($this->locale->languageName(), $this->promptWarnings);
        // The same assembly as ClaudeRunner::start(): the hash covers the mode prompt, the instance
        // addition and the language line, so a stored Claude hash and a Kimi hash mean the same thing.
        // The memory of the instance is NOT part of it: it is data that changes between runs, not a
        // prompt the operator changed, so hashing it would make every resume look like an operator edit
        // and trigger the update block. The memory is delivered fresh on every run regardless: the agent
        // file on a new session, the message on a resume.
        $systemPrompt = $built['text'];
        if ($this->appendSystemPrompt !== '') {
            $systemPrompt .= "\n\n".$this->appendSystemPrompt;
        }
        $fullPrompt = $systemPrompt."\n\n".$languageLine;
        $this->promptHash = hash('sha256', $fullPrompt);
        // This engine reads no user scope, so the memory of the instance is injected as content
        // ({@see MemoryContext}); null = off. A new session reads it from the agent file of the run
        // home, right after the mode prompt; a resumed session keeps the agent file it was created
        // with, so the fresh memory is handed over in the message instead.
        $memory = $this->memory?->block($this->promptWarnings) ?? '';
        if ($sessionId !== null && $memory !== '') {
            $prompt = $memory."\n\n".$prompt;
        }

        if ($sessionId !== null && $this->updateOnResume && $this->savedPromptHash !== $this->promptHash) {
            // A resumed session keeps the prompt it was created with: hand the new one over in the message.
            $prompt = PromptFiles::render('session_update', ['prompt' => $fullPrompt], $this->promptWarnings)."\n\n".$prompt;
        }

        // The session directories that exist now are other runs; the run's own one appears later.
        $this->launchSessionDirs = array_fill_keys($this->sessionDirs(), true);

        $this->runId = date('Ymd-His').'-'.getmypid().'-'.bin2hex(random_bytes(4));
        [$outFile, $errFile] = $this->openRunFiles();
        try {
            $runHome = $this->prepareRunHome();
            $this->runHome = $runHome;
            $this->writeRunHome($runHome, $built['text'], $languageLine, $memory);

            $this->wirePath = $this->findWirePath($sessionId);
            $this->wireOffset = $this->wirePath !== null ? max(0, (int) @filesize($this->wirePath)) : 0;
            $this->lastWireSize = $this->wireOffset;

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => $outFile,
                2 => $errFile,
            ];
            $proc = proc_open($this->argv($prompt, $sessionId, $runHome), $descriptors, $pipes, $this->repoPath, $this->processEnv());
        } catch (\Throwable $e) {
            fclose($outFile);
            fclose($errFile);
            $this->removeRunFiles();
            $this->purgeRunHome();

            throw $e;
        }

        // The child has its own copies of the descriptors.
        fclose($outFile);
        fclose($errFile);
        if (!is_resource($proc)) {
            $this->removeRunFiles();
            $this->purgeRunHome();

            throw new \RuntimeException('Failed to start kimi.');
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
     * Session id of the current run as soon as it is known; null until then. A new session is only
     * exposed once its directory is confirmed to be this run's (see {@see currentWirePath()}), not
     * merely by being the newest unknown directory.
     */
    public function sessionId(): ?string
    {
        $this->currentWirePath();

        return $this->sessionId;
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

    /** Kimi writes no separate diagnostics file. */
    public function diagnosticsPath(): ?string
    {
        return null;
    }

    /** The model alias the session was bound to, read from its `profile.bind` after the run. */
    public function actualModel(): ?string
    {
        return $this->actualModel;
    }

    /** Kimi has no post-run review record: the check is not available on this engine. */
    public function turnRecord(): ?TurnRecord
    {
        return null;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function poll(): RunnerState
    {
        $proc = $this->proc;
        if (!is_resource($proc)) {
            throw new \RuntimeException('poll() without an active process.');
        }

        $this->drain();
        $this->heartbeatFromWire();
        $status = proc_get_status($proc);

        if ($this->timeout > 0 && time() - $this->startedAt > $this->timeout) {
            $this->timeoutReason = $this->locale->t('runner.timeout_total', ['seconds' => $this->timeout]);
            $this->kill();

            return RunnerState::Timeout;
        }

        // The process is alive but neither stdout nor the session's wire.jsonl has changed: it is hung.
        // stdout alone is not enough: a background sub-agent writes only to its wire file while running.
        if ($this->stallTimeout > 0 && time() - $this->lastProgressAt > $this->stallTimeout) {
            $this->timeoutReason = $this->locale->t('runner.timeout_stall', ['seconds' => $this->stallTimeout]);
            $this->kill();

            return RunnerState::Timeout;
        }

        if ($status['running'] === true) {
            return RunnerState::Running;
        }

        $exit = (int) $status['exitcode'];
        // Bytes written between the previous drain() and the exit.
        $this->drain();
        // A descendant may still append output after the parent exited: keep reading on the next
        // passes, without blocking the main loop. A failed run needs no wait: its result comes from
        // stderr and the wire file, which are already on disk.
        $this->exitedAt ??= microtime(true);
        if ($exit === 0 && $this->lines === [] && $this->lineBuf === '' && microtime(true) - $this->exitedAt < self::EOF_WAIT_SEC) {
            return RunnerState::Running;
        }
        if ($this->lineBuf !== '') {
            $this->handleLine($this->lineBuf);
            $this->lineBuf = '';
        }

        $this->result = $this->buildResult($exit);
        $this->closeProc();

        return RunnerState::Done;
    }

    /**
     * Result of a finished call. Valid only after poll() === RunnerState::Done.
     */
    public function result(): RunAnswer
    {
        if ($this->result === null) {
            throw new \RuntimeException('result() before the process finished.');
        }

        return $this->result;
    }

    /**
     * Removes the run home of the finished run. Not called by {@see closeProc()}: the Bot calls it
     * after its post-run checks, so the gate shim's records are still on disk for them.
     */
    public function purgeRunHome(): void
    {
        if ($this->runHome !== null) {
            Removal::removeTree($this->runHome);
            $this->runHome = null;
        }
        if ($this->tmpDir !== null) {
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    /**
     * Removes `kimi-*` run homes of previous bot runs; called once at bot start. A directory named
     * after a live process belongs to a running instance and is kept.
     */
    public static function purgeRunHomes(string $stateDir): void
    {
        if ($stateDir === '') {
            return;
        }
        foreach (glob($stateDir.'/kimi-*') ?: [] as $path) {
            if (preg_match('/^kimi-\d{8}-\d{6}-(\d+)-/', basename($path), $m) === 1 && self::isAlive((int) $m[1])) {
                continue;
            }
            Removal::removeTree($path);
        }
    }

    /**
     * The complete environment of the process: the bot's, the instance's extra variables (the gate
     * environment of {@see JobRunner}), the run home and the self-update switch, then the
     * `core.hooksPath` of the bot's git hooks ({@see GitHooks}).
     *
     * @return array<string, string>
     */
    public function processEnv(): array
    {
        return GitHooks::apply(array_merge(getenv(), $this->extraEnv, [
            'KIMI_CODE_HOME' => (string) $this->runHome,
            'KIMI_CODE_NO_AUTO_UPDATE' => '1',
        ]));
    }

    /**
     * Argv of the run: `-p <prompt> --output-format stream-json`, then either the resumed session
     * (`-S`) or the agent file of a new one (`--agent-file`), the pinned model, and the instance's
     * extra arguments.
     *
     * `--agent-file` is never combined with `-S`: Kimi rejects the pair, and a resumed session keeps
     * the prompt it was created with (a changed prompt arrives in the message, see
     * {@see ClaudeRunner::neutralizeUpdateMarkers()} and the instructions-update block).
     *
     * @return list<string>
     */
    private function argv(string $prompt, ?string $sessionId, string $runHome): array
    {
        $argv = [$this->kimiBin, '-p', $prompt, '--output-format', 'stream-json'];
        if ($sessionId !== null) {
            $argv[] = '-S';
            $argv[] = $sessionId;
        } else {
            $argv[] = '--agent-file';
            $argv[] = $runHome.'/'.KimiRunHomeRenderer::AGENT_FILE;
        }
        if ($this->kimiModel !== '' && !$this->hasModelArg()) {
            $argv[] = '-m';
            $argv[] = $this->kimiModel;
        }
        foreach ($this->extraArgs as $arg) {
            $argv[] = $arg;
        }

        return $argv;
    }

    /** Whether the instance's extra arguments already pin a model. */
    private function hasModelArg(): bool
    {
        foreach ($this->extraArgs as $arg) {
            if ($arg === '-m' || $arg === '--model' || str_starts_with($arg, '--model=')) {
                return true;
            }
        }

        return false;
    }

    /** The home of the logged-in Kimi CLI the run links its credentials, sessions and oauth to. */
    private function sourceHome(): string
    {
        if ($this->kimiHome !== '') {
            return rtrim($this->kimiHome, '/');
        }
        $home = $this->homeDir !== '' ? $this->homeDir : (string) getenv('HOME');

        return rtrim($home, '/').'/.kimi-code';
    }

    /** The gate shim of the run when none is configured: the bot's own `bin/kimi-hook.php`. */
    private function defaultShimPath(): string
    {
        return dirname(__DIR__).'/bin/kimi-hook.php';
    }

    /** The gate shim of the run, or '' when it is not installed yet (then the config gets no hooks). */
    private function resolvedShimPath(): string
    {
        $path = $this->hookShimPath !== '' ? $this->hookShimPath : $this->defaultShimPath();

        return is_file($path) ? $path : '';
    }

    /**
     * The run home: `state/kimi-<runId>/`, mode 0700 regardless of umask.
     */
    private function prepareRunHome(): string
    {
        $base = $this->stateDir !== '' ? $this->stateDir : (string) $this->tmpDir;
        $dir = $base.'/kimi-'.$this->runId;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the Kimi run home.');
        }
        @chmod($dir, 0700);

        return $dir;
    }

    /**
     * Writes the renderer's files and links the shared home entries into the run home.
     *
     * The user's own home is never written: only the run home is created, its `credentials/`,
     * `sessions/` and `oauth/` point into the source home, and `region` / `device_id` are copied
     * from it.
     *
     * @param string $memory the memory block of the instance ('': nothing to inject)
     */
    private function writeRunHome(string $dir, string $systemPrompt, string $languageLine, string $memory = ''): void
    {
        $source = $this->sourceHome();
        $sourceConfig = is_file($source.'/config.toml') ? (string) @file_get_contents($source.'/config.toml') : '';
        $renderer = new KimiRunHomeRenderer(
            $sourceConfig,
            $dir,
            $this->resolvedShimPath(),
            $systemPrompt,
            $memory,
            $this->appendSystemPrompt,
            $languageLine,
            $this->repoPath,
            $this->homeDir,
        );

        foreach ($renderer->render($this->promptWarnings) as $relative => $content) {
            $path = $dir.'/'.$relative;
            $parent = dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create the Kimi run home directory.');
            }
            @chmod($parent, 0700);
            $old = umask(0177);
            $written = @file_put_contents($path, $content);
            umask($old);
            if ($written === false) {
                throw new \RuntimeException('Cannot write the Kimi run home file.');
            }
            @chmod($path, 0600);
        }

        foreach (self::LINKED_HOME_ENTRIES as $name) {
            if (!@symlink($source.'/'.$name, $dir.'/'.$name)) {
                throw new \RuntimeException('Cannot link '.$name.' into the Kimi run home.');
            }
        }
        foreach (self::COPIED_HOME_FILES as $name) {
            if (!is_file($source.'/'.$name)) {
                continue;
            }
            if (!@copy($source.'/'.$name, $dir.'/'.$name)) {
                throw new \RuntimeException('Cannot copy '.$name.' into the Kimi run home.');
            }
            @chmod($dir.'/'.$name, 0600);
        }
    }

    /**
     * The session directories of the source home (`sessions/<bucket>/<session id>`).
     *
     * @return list<string>
     */
    private function sessionDirs(): array
    {
        $dirs = glob($this->sourceHome().'/sessions/*/session_*') ?: [];

        return array_values(array_filter($dirs, static fn (string $path): bool => is_dir($path)));
    }

    /** `wire.jsonl` of a known session id, or null when the session directory is not on disk. */
    private function findWirePath(?string $sessionId): ?string
    {
        if ($sessionId === null) {
            return null;
        }
        foreach ($this->sessionDirs() as $dir) {
            if (basename($dir) === $sessionId) {
                return $dir.'/agents/main/wire.jsonl';
            }
        }

        return null;
    }

    /**
     * Path of the session's main `wire.jsonl`, resolving the session first when it was new.
     *
     * A new session directory exists from the start of the run, although stdout prints its id only
     * at the end. It is not necessarily the only new directory: another Kimi CLI on the same home
     * can start its own session during our run. Only a directory whose wire records a start time
     * matching this run's launch is taken for ours (see {@see wireStartsThisRun()}); the
     * authoritative id is `session.resume_hint`, used when it arrives (see {@see handleLine()}).
     */
    private function currentWirePath(): ?string
    {
        if ($this->wirePath !== null) {
            return $this->wirePath;
        }
        if ($this->resumeSessionId !== null) {
            $this->wirePath = $this->findWirePath($this->resumeSessionId);
            if ($this->wirePath !== null) {
                $this->lastWireSize = max($this->lastWireSize, (int) @filesize($this->wirePath));
            }

            return $this->wirePath;
        }

        $known = $this->launchSessionDirs ?? [];
        foreach ($this->sessionDirs() as $dir) {
            if (isset($known[$dir])) {
                continue;
            }
            $wire = $dir.'/agents/main/wire.jsonl';
            if (!$this->wireStartsThisRun($wire)) {
                continue;
            }
            $this->sessionId = basename($dir);
            $this->wirePath = $wire;
            $this->wireOffset = 0;
            $this->lastWireSize = (int) @filesize($wire);

            return $this->wirePath;
        }

        return null;
    }

    /**
     * Whether a session directory is this run's new session: its `metadata.created_at` (the start
     * time the wire records, in ms) lies within a small window of the run's launch. A foreign
     * session directory started by another Kimi process on the same home records a different start
     * time and is rejected, so its tokens and error are never read for this run.
     */
    private function wireStartsThisRun(string $wirePath): bool
    {
        foreach ($this->readHeadLines($wirePath) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ($record['type'] ?? null) === 'metadata') {
                $createdAt = $record['created_at'] ?? null;
                if (!is_int($createdAt) && !is_float($createdAt)) {
                    return false;
                }
                $launchMs = $this->startedAt * 1000;

                return (int) $createdAt >= $launchMs - self::SESSION_START_SLACK_MS
                    && (int) $createdAt <= $launchMs + self::SESSION_START_SLACK_MS;
            }
        }

        return false;
    }

    /**
     * Keeps the run alive while its session's wire file grows: stdout is quiet during a background
     * sub-agent, which writes its progress to the wire file only.
     */
    private function heartbeatFromWire(): void
    {
        $path = $this->currentWirePath();
        if ($path === null) {
            return;
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size !== false && $size > $this->lastWireSize) {
            $this->lastWireSize = $size;
            $this->lastProgressAt = time();
        }
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

        // Any stdout byte is a heartbeat for stallTimeout (the wire file is checked separately).
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
            $dir = sys_get_temp_dir().'/kimi-run-'.bin2hex(random_bytes(8));
            if (!@mkdir($dir, 0700)) {
                throw new \RuntimeException('Cannot create the run directory.');
            }
            $this->tmpDir = $dir;
        }

        $this->files = [1 => $dir.'/'.$this->runId.'.out', 2 => $dir.'/'.$this->runId.'.err'];
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

    /**
     * Keeps one stdout line: the live progress lines and the session id as soon as they appear.
     * The final answer, usage and error are read from the whole stream after the run.
     */
    private function handleLine(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        $this->lines[] = $line;

        // The parser works on a window, so one line is a valid window: this is the same event
        // formatting the final parse uses.
        $parsed = KimiStreamParser::parse([$line]);
        if ($parsed->sessionId !== null) {
            $this->sessionId = $parsed->sessionId;
        }
        foreach ($parsed->progressLines as $progressLine) {
            $this->progressLines[] = $progressLine;
        }
    }

    /**
     * The run's answer from the collected stdout and the window of `wire.jsonl`.
     *
     * The engine's text is shown as it is: the caller masks it before it reaches a chat message.
     * A failure is classified by {@see KimiErrorClassifier}; its class goes to
     * {@see RunAnswer::$errorClass} and its limit class to {@see RunAnswer::$limitClass}.
     */
    private function buildResult(int $exit): RunAnswer
    {
        $stream = KimiStreamParser::parse($this->lines);
        $wire = $this->readWire();
        if ($wire !== null && $wire->model !== null) {
            $this->actualModel = $wire->model;
        }
        $this->logUnverifiedFormat($stream->version, $wire?->protocolVersion);

        $class = KimiErrorClassifier::classify($exit, $this->stderr, $stream, $wire?->error, new ClaudeErrorText($this->locale));
        $sessionId = $stream->sessionId ?? $this->sessionId;

        $isError = $exit !== 0 || $stream->error !== null || ($wire?->isError() ?? false);
        $text = $stream->finalText;
        if ($text === '' && $isError) {
            $text = $this->errorText($stream->error, $wire, $exit);
        }
        $exitedWithoutResult = false;
        if (!$isError && trim($text) === '') {
            $exitedWithoutResult = true;
            $isError = true;
        }
        if (trim($text) === '') {
            $text = trim($this->stderr) !== ''
                ? trim($this->stderr)
                : $this->locale->t('claude.no_output', ['code' => $exit]);
        }

        return new RunAnswer(
            isError: $isError,
            text: $text,
            sessionId: $sessionId,
            totals: $wire !== null
                ? new UsageTotals($wire->inputOther, $wire->inputCacheCreation, $wire->inputCacheRead, $wire->output)
                : null,
            exitedWithoutResult: $exitedWithoutResult,
            limitClass: $isError ? $class->limitClass() : null,
            errorClass: $isError ? $class->value : null,
        );
    }

    /**
     * The failure text: the stdout error event first, then the wire error, then stderr (the
     * config-level `Model … is not configured` and `Session … not found` lines are shown verbatim).
     *
     * @param array{code: string, message: string}|null $streamError
     */
    private function errorText(?array $streamError, ?KimiWire $wire, int $exit): string
    {
        if ($streamError !== null) {
            return trim($streamError['message']) !== '' ? $streamError['message'] : $streamError['code'];
        }
        if ($wire !== null && $wire->error !== null) {
            $message = $wire->error->message;
            if (is_string($message) && trim($message) !== '') {
                return $message;
            }
            $code = $wire->error->code;

            return is_string($code) ? $code : '';
        }
        $stderr = trim($this->stderr);

        return $stderr !== '' ? $stderr : $this->locale->t('claude.no_output', ['code' => $exit]);
    }

    /**
     * Logs an unverified CLI version or wire protocol version: both formats are private, so a Kimi
     * self-update can silently break the readers, and this line is how it is noticed.
     */
    private function logUnverifiedFormat(?string $version, ?string $protocolVersion): void
    {
        $check = KimiVersionGuard::check($version, $protocolVersion);
        if ($check->isUntrusted()) {
            Log::line('kimi: unverified wire protocol version '.(string) $protocolVersion.'; tokens, model and error may be read wrongly');
        }
        if ($check->version === KimiFormatStatus::Unknown) {
            Log::line('kimi: unverified CLI version '.(string) $version);
        }
    }

    /**
     * The facts of the session's `wire.jsonl`: the window written by this run, plus the head of the
     * file when the window does not reach it (the model and the protocol version are written once).
     */
    private function readWire(): ?KimiWire
    {
        $path = $this->currentWirePath();
        if ($path === null || !is_file($path)) {
            return null;
        }
        $wire = KimiWireReader::read($this->readLines($path, $this->wireOffset));
        if ($wire->model !== null && $wire->protocolVersion !== null) {
            return $wire;
        }
        $head = KimiWireReader::read($this->readHeadLines($path));

        return new KimiWire(
            $wire->inputOther,
            $wire->output,
            $wire->inputCacheRead,
            $wire->inputCacheCreation,
            $wire->model ?? $head->model,
            $wire->protocolVersion ?? $head->protocolVersion,
            $wire->error,
        );
    }

    /**
     * The complete lines of `$path` from `$offset` to its current end.
     *
     * @return list<string>
     */
    private function readLines(string $path, int $offset): array
    {
        $h = @fopen($path, 'r');
        if ($h === false) {
            return [];
        }
        $body = '';
        if (fseek($h, $offset) === 0) {
            $body = stream_get_contents($h) ?: '';
        }
        fclose($h);

        return $body === '' ? [] : explode("\n", rtrim($body, "\n"));
    }

    /**
     * The complete lines of the head of the file (at most {@see WIRE_HEAD_BYTES} bytes): the
     * `metadata` and `profile.bind` records are written there once.
     *
     * @return list<string>
     */
    private function readHeadLines(string $path): array
    {
        $h = @fopen($path, 'r');
        if ($h === false) {
            return [];
        }
        $body = (string) fread($h, self::WIRE_HEAD_BYTES);
        fclose($h);
        // The last line of the head is likely truncated: only whole lines are parsed.
        $end = strrpos($body, "\n");
        if ($end === false) {
            return [];
        }

        return explode("\n", substr($body, 0, $end));
    }

    private function removeRunFiles(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }
        $this->files = [];
        // The run home may live in the temporary base: it is removed by purgeRunHome(), not here.
        if ($this->tmpDir !== null && $this->runHome === null) {
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    private function resetState(): void
    {
        $this->proc = null;
        $this->files = [];
        $this->offsets = [1 => 0, 2 => 0];
        $this->tmpDir = null;
        $this->runHome = null;
        $this->runId = '';
        $this->exitedAt = null;
        $this->stderr = '';
        $this->startedAt = 0;
        $this->lastProgressAt = 0;
        $this->pid = 0;
        $this->result = null;
        $this->timeoutReason = '';
        $this->sessionId = null;
        $this->resumeSessionId = null;
        $this->lineBuf = '';
        $this->lines = [];
        $this->progressLines = [];
        $this->promptWarnings = [];
        $this->promptHash = null;
        $this->actualModel = null;
        $this->wirePath = null;
        $this->wireOffset = 0;
        $this->lastWireSize = 0;
        $this->launchSessionDirs = null;
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

    /** Closes the process and removes the run files. The run home is kept for a deferred purge. */
    private function closeProc(): void
    {
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
        $this->proc = null;
        $this->removeRunFiles();
    }
}
