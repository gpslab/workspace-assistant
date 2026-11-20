<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Opencode\OpencodeAgent;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeConfigRenderer;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeLimit;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeNdjsonParser;
use GpsLab\WorkspaceAssistant\Opencode\ToolPermissions;

/**
 * Asynchronous launch of `opencode run` in JSON mode.
 *
 * The engine-neutral contract of {@see AgentRunner}; the OpenCode counterpart of {@see ClaudeRunner}.
 * The process starts non-blocking, the bot polls {@see poll()} and keeps serving Telegram.
 *
 * Every run gets its own config directory (`state/opencode-<runId>/`) with the rendered per-run config,
 * and `OPENCODE_DISABLE_PROJECT_CONFIG=1` / `OPENCODE_CONFIG_DIR` / `OPENCODE_CONFIG` in its
 * environment, so the managed repository's `opencode.json` / `.opencode/` cannot override the bot's
 * config. `XDG_CONFIG_HOME` is never touched: it would also redirect the configs of `gh` / `glab` and
 * their authorisation.
 *
 * The environment also carries the bot's git hooks `core.hooksPath` ({@see GitHooks}), exactly as
 * {@see ClaudeRunner::processEnv()} does, so the agent process is wired the same way on both engines.
 *
 * A gated run (a `pm` / `review` mode run, which covers the epic runs) additionally lists the gate shim
 * in the `plugins` of that config: OpenCode has no command hooks, so the PM gate and the review commit
 * hook of such a run reach it through the plugin, which starts the same PHP hooks. The plugin reads the
 * gate environment contract `JobRunner` passes in `$extraEnv` ({@see PmGateHook::STATE_ENV},
 * {@see PmGateHook::KIND_ENV}, {@see PmGateHook::RUN_ENV}, {@see PmGateHook::PHP_ENV}, plus
 * {@see PmGateHook::SKIP_ENV}, {@see ReviewCommitHook::SHAS_ENV} and
 * {@see ReviewCommitHook::ENABLED_ENV} on a review). A run with no gate kind gets no plugin.
 *
 * The model is pinned by the instance ({@see Config::$opencodeModel}); the `$model` of a
 * {@see JobRunner::launchJob()} call is a Claude Code alias and is deliberately ignored. A resumed
 * run needs no instructions-update block: OpenCode applies the changed instructions itself.
 *
 * Arguments are passed as an array to proc_open: no shell, no injection.
 */
final class OpencodeRunner implements AgentRunner
{
    /** How long (sec) poll() keeps reading the run files for first output after the process has exited. */
    private const int EOF_WAIT_SEC = 5;

    private const int CLIP_LENGTH = 60;

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

    /** Per-run config directory passed in OPENCODE_CONFIG_DIR; removed together with the run files. */
    private ?string $configDir = null;

    private string $runId = '';

    private ?float $exitedAt = null;

    private int $startedAt = 0;
    private int $lastProgressAt = 0;
    private int $pid = 0;
    private ?RunAnswer $result = null;
    private string $timeoutReason = '';

    /** sessionID from the event stream; known already while the run is in progress. */
    private ?string $sessionId = null;

    /** Whether a sub-agent has been launched: the stall timer is suspended from then on. */
    private bool $stallSuspended = false;

    /** Incomplete NDJSON line carried over between pipe reads. */
    private string $lineBuf = '';

    /** @var list<string> every complete NDJSON line of the run, for {@see OpencodeNdjsonParser::parse()} */
    private array $lines = [];

    /** @var list<string> human-readable lines for tool calls (live progress) */
    private array $progressLines = [];

    /** @var list<string> */
    private array $promptWarnings = [];

    private ?string $promptHash = null;

    /** The turn of the finished run, for a check the Bot runs after it ({@see AgentRunner::turnRecord()}). */
    private ?TurnRecord $turnRecord = null;

    /**
     * @param string[]              $extraArgs     extra argv passed as flags before the prompt (`OPENCODE_EXTRA_ARGS`)
     * @param int                   $timeout       overall ceiling for a run (sec); 0 disables it
     * @param int                   $stallTimeout  if the process emits no byte for longer than this (sec)
     *                                             it is considered hung and killed; 0 disables it
     * @param array<string, string> $extraEnv      extra process environment variables on top of the bot's
     * @param string                $stateDir      where the run files and the per-run config directory go;
     *                                             empty puts them into a temporary directory
     * @param bool                  $withAgents    render the built-in sub-agents (not for /compact)
     * @param string                $homeDir       home of the user the bot runs as, for `~/.claude/agents`
     * @param string                $opencodeModel `provider/model` passed as `--model`; '' passes none
     */
    public function __construct(
        private readonly string $opencodeBin,
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
        private readonly bool $withAgents = true,
        private readonly string $homeDir = '',
        private readonly string $forge = Config::FORGE_GITHUB,
        private readonly string $opencodeModel = '',
    ) {
    }

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

    public function start(string $prompt, ?string $sessionId): void
    {
        $this->resetState();
        $systemPrompt = $this->systemPrompt();
        $this->promptHash = hash('sha256', $systemPrompt);
        $builtinAgents = $this->builtinAgents();
        $instanceAgents = $this->instanceAgents();

        [$outFile, $errFile] = $this->openRunFiles();

        try {
            $this->configDir = $this->prepareConfigDir();
            $this->writeConfig($this->configDir, $systemPrompt, $builtinAgents, $instanceAgents);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => $outFile,
                2 => $errFile,
            ];
            $proc = proc_open($this->argv($prompt, $sessionId), $descriptors, $pipes, $this->repoPath, $this->processEnv());
        } catch (\Throwable $e) {
            fclose($outFile);
            fclose($errFile);
            $this->removeRunFiles();

            throw $e;
        }

        // The child has its own copies of the descriptors.
        fclose($outFile);
        fclose($errFile);
        if (!is_resource($proc)) {
            $this->removeRunFiles();

            throw new \RuntimeException('Failed to start opencode.');
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

    /** sessionID of the current run as soon as it is seen; null until then. */
    public function sessionId(): ?string
    {
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

    /** OpenCode writes no separate diagnostics file. */
    public function diagnosticsPath(): ?string
    {
        return null;
    }

    /** OpenCode reports no model in its event stream; the instance pin is the only model source. */
    public function actualModel(): ?string
    {
        return null;
    }

    /** The turn of the finished run: its shell calls with the outcome of each and its final text. */
    public function turnRecord(): ?TurnRecord
    {
        return $this->turnRecord;
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
        $status = proc_get_status($proc);

        if ($this->timeout > 0 && time() - $this->startedAt > $this->timeout) {
            $this->timeoutReason = $this->locale->t('runner.timeout_total', ['seconds' => $this->timeout]);
            $this->kill();

            return RunnerState::Timeout;
        }

        // Before the first sub-agent a silent process is hung. Once a sub-agent is launched the
        // timer is suspended for the rest of the run: OpenCode always runs sub-agents in the
        // background and stdout stays silent while they work, so silence no longer means "hung".
        if (!$this->stallSuspended && $this->stallTimeout > 0 && time() - $this->lastProgressAt > $this->stallTimeout) {
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
        // passes, without blocking the main loop.
        $this->exitedAt ??= microtime(true);
        if ($this->lines === [] && microtime(true) - $this->exitedAt < self::EOF_WAIT_SEC) {
            return RunnerState::Running;
        }
        if ($this->lineBuf !== '') {
            $this->handleLine($this->lineBuf);
            $this->lineBuf = '';
        }
        $this->closeProc();

        $this->result = $this->buildResult($exit);

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
     * The complete environment of the process: the bot's, the run variables, the per-run config
     * pointers, and the `core.hooksPath` of the bot's git hooks ({@see GitHooks}), exactly as
     * {@see ClaudeRunner::processEnv()} builds it. It must be complete: with extra variables proc_open
     * does not inherit the rest.
     *
     * `XDG_CONFIG_HOME` is deliberately left as it is: redirecting it would also move the configs of
     * `gh` / `glab` and lose their authorisation.
     *
     * @return array<string, string>
     */
    public function processEnv(): array
    {
        $dir = (string) $this->configDir;

        return GitHooks::apply(array_merge(getenv(), $this->extraEnv, [
            'OPENCODE_DISABLE_PROJECT_CONFIG' => '1',
            'OPENCODE_CONFIG_DIR' => $dir,
            'OPENCODE_CONFIG' => $dir.'/explicit.json',
        ]));
    }

    /**
     * Argv of the run: the fixed `opencode run` flags, the optional session and model, the
     * instance's extra arguments, then `--` with the prompt last.
     *
     * The prompt is chat input: it goes after the end-of-options marker so a message starting with
     * `-` / `--` is never parsed as an opencode flag.
     *
     * @return list<string>
     */
    private function argv(string $prompt, ?string $sessionId): array
    {
        $argv = [$this->opencodeBin, 'run', '--format', 'json', '--standalone', '--auto'];
        if ($sessionId !== null) {
            $argv[] = '--session';
            $argv[] = $sessionId;
        }
        if ($this->opencodeModel !== '') {
            $argv[] = '--model';
            $argv[] = $this->opencodeModel;
        }
        foreach ($this->extraArgs as $arg) {
            $argv[] = $arg;
        }
        // End of options: the prompt is chat input and must never be parsed as a flag.
        $argv[] = '--';
        $argv[] = $prompt;

        return $argv;
    }

    /**
     * The assembled system prompt, exactly as {@see ClaudeRunner::start()} builds it: mode contract and
     * personas, the instance's `CLAUDE_APPEND_SYSTEM_PROMPT` and the chat-language line last.
     */
    private function systemPrompt(): string
    {
        $built = SystemPrompt::build(
            $this->mode,
            $this->promptsDir !== '' ? $this->repoPath.'/'.$this->promptsDir : null,
            $this->promptsDir !== '' ? $this->promptsDir : null,
            $this->forge,
            // This runner IS the OpenCode engine: the engine tokens of the prompt take its wording.
            Config::ENGINE_OPENCODE,
        );
        $this->promptWarnings = $built['warnings'];
        $systemPrompt = $built['text'];
        if ($this->appendSystemPrompt !== '') {
            $systemPrompt .= "\n\n".$this->appendSystemPrompt;
        }
        // The language line goes last: it must override the persona and the append text.
        $systemPrompt .= "\n\n".SystemPrompt::languageLine($this->locale->languageName(), $this->promptWarnings);

        return $systemPrompt;
    }

    /**
     * The built-in sub-agents in the renderer's shape (empty for /compact runs or when disabled).
     *
     * @return list<OpencodeAgent>
     */
    private function builtinAgents(): array
    {
        if (!$this->withAgents) {
            return [];
        }

        $agents = [];
        foreach (BuiltinAgents::build($this->repoPath, $this->homeDir, Config::ENGINE_OPENCODE, $this->promptWarnings) as $name => $agent) {
            $agents[] = new OpencodeAgent($name, $agent['description'], $agent['prompt'], $agent['tools']);
        }

        return $agents;
    }

    /**
     * The agents the instance defines itself, in the renderer's shape (empty for /compact runs or when
     * disabled).
     *
     * OpenCode loads none of the instance's agent directories on its own — not even `.claude/agents`,
     * which it reads for skills only — so without this an instance's own agent would be missing from
     * the run while the built-in one of the same name was left out of it (see
     * {@see InstanceAgents::directories()}).
     *
     * @return list<OpencodeAgent>
     */
    private function instanceAgents(): array
    {
        if (!$this->withAgents) {
            return [];
        }

        $agents = [];
        foreach (InstanceAgents::load($this->repoPath, $this->homeDir, Config::ENGINE_OPENCODE) as $name => $agent) {
            if ($agent['tools'] !== null && ToolPermissions::mapsToNothing($agent['tools'])) {
                Log::line('Agent "'.$name.'" ('.$agent['path'].') declares tools ('.implode(', ', $agent['tools']).') that map to no OpenCode permission; it is restricted to nothing.');
            }
            $agents[] = new OpencodeAgent($name, $agent['description'], $agent['prompt'], $agent['tools']);
        }

        return $agents;
    }

    /**
     * Directory of the gate shim (`<bot>/opencode-plugin`) when this run is gated, null otherwise.
     *
     * OpenCode has no command hooks, so a gated run carries its PM gate and its review commit hook
     * through this plugin (see {@see PmGateHook::KIND_ENV}, set by {@see JobRunner} on every gated run);
     * the plugin starts the same PHP hooks, so the gate logic stays single-source.
     */
    private function gatePluginDir(): ?string
    {
        return ($this->extraEnv[PmGateHook::KIND_ENV] ?? '') === '' ? null : dirname(__DIR__).'/opencode-plugin';
    }

    /**
     * Writes the renderer's artefacts into the per-run config directory.
     *
     * @param list<OpencodeAgent> $builtinAgents
     * @param list<OpencodeAgent> $instanceAgents
     */
    private function writeConfig(string $dir, string $systemPrompt, array $builtinAgents, array $instanceAgents): void
    {
        $renderer = new OpencodeConfigRenderer(
            $systemPrompt,
            $dir.'/'.OpencodeConfigRenderer::SYSTEM_PROMPT_FILE,
            $builtinAgents,
            $instanceAgents,
            $this->repoPath,
            is_file($this->repoPath.'/CLAUDE.md'),
            is_file($this->repoPath.'/AGENTS.md'),
            $this->gatePluginDir(),
        );

        foreach ($renderer->render() as $relative => $content) {
            $path = $dir.'/'.$relative;
            $parent = dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new \RuntimeException('Cannot create the OpenCode config directory.');
            }
            @chmod($parent, 0700);
            $old = umask(0177);
            $written = @file_put_contents($path, $content);
            umask($old);
            if ($written === false) {
                throw new \RuntimeException('Cannot write the OpenCode config file.');
            }
            @chmod($path, 0600);
        }
    }

    private function prepareConfigDir(): string
    {
        $base = $this->stateDir !== '' ? $this->stateDir : (string) $this->tmpDir;
        $dir = $base.'/opencode-'.$this->runId;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the OpenCode config directory.');
        }
        @chmod($dir, 0700);

        return $dir;
    }

    private function resetState(): void
    {
        $this->proc = null;
        $this->files = [];
        $this->offsets = [1 => 0, 2 => 0];
        $this->tmpDir = null;
        $this->configDir = null;
        $this->runId = '';
        $this->exitedAt = null;
        $this->pid = 0;
        $this->result = null;
        $this->timeoutReason = '';
        $this->sessionId = null;
        $this->stallSuspended = false;
        $this->lineBuf = '';
        $this->lines = [];
        $this->progressLines = [];
        $this->promptWarnings = [];
        $this->promptHash = null;
        $this->turnRecord = null;
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
        }

        // Any byte of an event is a heartbeat for stallTimeout.
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
            $dir = sys_get_temp_dir().'/opencode-run-'.bin2hex(random_bytes(8));
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

    /** Appends the chunk to the buffer and processes all completed lines (NDJSON). */
    private function ingestStdout(string $chunk): void
    {
        $this->lineBuf .= $chunk;
        while (($nl = strpos($this->lineBuf, "\n")) !== false) {
            $line = substr($this->lineBuf, 0, $nl);
            $this->lineBuf = substr($this->lineBuf, $nl + 1);
            $this->handleLine($line);
        }
    }

    /** Keeps one NDJSON line: sessionID, the sub-agent marker and live progress. */
    private function handleLine(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        $this->lines[] = $line;

        /** @var array<string, mixed>|null $event */
        $event = json_decode($line, true);
        if (!is_array($event)) {
            return;
        }

        $sid = $event['sessionID'] ?? null;
        if (is_string($sid) && $sid !== '' && $this->sessionId === null) {
            $this->sessionId = $sid;
        }

        if (($event['type'] ?? null) !== 'tool_use') {
            return;
        }

        $part = is_array($event['part'] ?? null) ? $event['part'] : [];
        if (($part['tool'] ?? null) === 'subagent') {
            // From here on stdout may stay silent for the whole sub-agent run: stop treating
            // silence as a hang for the rest of this run.
            $this->stallSuspended = true;
        }
        $this->progressLines[] = $this->progressLine($part);
    }

    /**
     * Human-readable line for a tool call (for the progress message).
     *
     * @param array<string, mixed> $part
     */
    private function progressLine(array $part): string
    {
        $tool = is_string($part['tool'] ?? null) ? $part['tool'] : '';
        $state = is_array($part['state'] ?? null) ? $part['state'] : [];
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $scanner = SecretScanner::shared();
        $field = static fn (string $key): string => is_string($input[$key] ?? null)
            ? $scanner->mask($input[$key])
            : '';

        return match ($tool) {
            'shell' => '🔧 '.self::clip($field('command')),
            'subagent' => '🤖 '.self::clip($field('description')),
            'read' => '📖 '.self::baseName($field('filePath')),
            'edit', 'write' => '✏️ '.self::baseName($field('filePath')),
            default => '🔨 '.($tool !== '' ? $tool : 'Tool'),
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

    private static function clip(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            return '?';
        }

        return mb_strlen($s) > self::CLIP_LENGTH ? mb_substr($s, 0, self::CLIP_LENGTH).'…' : $s;
    }

    /**
     * The run result from the collected NDJSON and the exit code. The limit classification of the
     * parser is carried to {@see JobRunner} in {@see RunAnswer::$limitClass}.
     */
    private function buildResult(int $exit): RunAnswer
    {
        $turn = OpencodeNdjsonParser::parse($this->lines);
        $limitClass = OpencodeLimit::classify($turn->error, new ClaudeErrorText($this->locale));
        // The same turn in the neutral shape the Bot's post-run checks work on: OpenCode has no Stop
        // hooks, so what the record carries (the shell calls and the final text) is all they get.
        $this->turnRecord = TurnRecord::fromToolCalls($turn->toolCalls, $turn->finalText);

        $text = $turn->finalText;
        if ($text === '') {
            $text = self::errorMessage($turn->error);
        }

        $isError = $turn->isError() || $exit !== 0;
        $exitedWithoutResult = false;
        if ($text === '') {
            $exitedWithoutResult = !$turn->isError() && $exit === 0;
            $isError = true;
            $text = $this->locale->t('claude.no_output', ['code' => $exit]);
        }

        return new RunAnswer(
            isError: $isError,
            text: $text,
            sessionId: $turn->sessionId ?? $this->sessionId,
            exitedWithoutResult: $exitedWithoutResult,
            limitClass: $limitClass,
        );
    }

    /**
     * The reason of an OpenCode `error` event: its message, else its type.
     *
     * @param array<string, mixed>|null $error
     */
    private static function errorMessage(?array $error): string
    {
        if ($error === null) {
            return '';
        }
        $message = $error['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }
        $type = $error['type'] ?? null;

        return is_string($type) ? $type : '';
    }

    private function removeRunFiles(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }
        $this->files = [];
        if ($this->configDir !== null) {
            self::removeTree($this->configDir);
            $this->configDir = null;
        }
        if ($this->tmpDir !== null) {
            self::removeTree($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    /**
     * Removes leftover per-run config directories of previous bot runs; called once at bot start.
     * A directory named after a live process belongs to a running instance and is kept.
     */
    public static function purgeRunDirs(string $stateDir): void
    {
        if ($stateDir === '') {
            return;
        }
        foreach (glob($stateDir.'/opencode-*') ?: [] as $path) {
            if (!is_dir($path)) {
                continue;
            }
            if (preg_match('/^opencode-\d{8}-\d{6}-(\d+)-/', basename($path), $m) === 1 && self::isAlive((int) $m[1])) {
                continue;
            }
            self::removeTree($path);
        }
    }

    private static function removeTree(string $path): void
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

    private function closeProc(): void
    {
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
        $this->proc = null;
        $this->removeRunFiles();
    }
}
