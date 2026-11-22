<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Kimi\KimiHook;

/*
 * The gate shim of a Kimi run, registered by the run-home renderer for `SessionStart` and
 * `PreToolUse` without a matcher (see KimiHooksRenderer). Kimi's hooks fail open — only exit 2 blocks
 * a call — so this shim is fail-CLOSED: every failure it can observe exits 2 with a reason on stderr,
 * which the model sees. Only an explicit pass and "not a gated command" exit 0.
 *
 * It reads the hook JSON from stdin and the gate environment from the process environment, and runs
 * the same single-source PHP logic as the other engines: the secret guard on shell commands, the PM
 * gate for a shell command that creates an issue or PR, and the review commit hook. A `SessionStart`
 * writes the session id to the run home and does nothing else.
 *
 * The run home is `KIMI_CODE_HOME` (`state/kimi-<runId>`); the shim writes `session-id` and appends
 * one hook record per call there (see KimiHook). The history of a gated command is the session's main
 * `wire.jsonl`, located by the payload's `session_id` or, when the payload has none, by the file
 * `SessionStart` wrote; a session id is used only when it matches KimiHook::SESSION_ID_PATTERN.
 *
 * `--self-test` loads the bootstrap and checks that the run home is writable; exit 0 or non-zero with
 * the reason on stderr.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Fail-closed handlers, registered before any require: a recoverable error becomes an exception, an
// uncaught exception and a fatal error exit 2 (a fatal would otherwise exit 255, which Kimi does not
// treat as a block).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, KimiHook::FAILURE_REASON."\n");
    exit(2);
});
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, KimiHook::FAILURE_REASON."\n");
        exit(2);
    }
});

/**
 * The path of the session's main `wire.jsonl`, located by the payload's `session_id` or, when the
 * payload has none, by the file `SessionStart` wrote. A session id is used only when it matches
 * {@see KimiHook::SESSION_ID_PATTERN}, so a value from the payload or the file can never reach the
 * glob as a pattern or a path.
 *
 * @param array<string, mixed> $input the decoded hook payload
 *
 * @throws \RuntimeException when the history cannot be located
 */
function locateHistory(string $home, array $input): string
{
    $sessionId = $input['session_id'] ?? null;
    if (!is_string($sessionId) || $sessionId === '') {
        $file = $home.'/'.KimiHook::SESSION_ID_FILE;
        $sessionId = is_file($file) ? trim((string) file_get_contents($file)) : '';
    }
    if (preg_match(KimiHook::SESSION_ID_PATTERN, $sessionId) !== 1) {
        throw new \RuntimeException('the session id is not available');
    }

    $wires = glob($home.'/sessions/*/'.$sessionId.'/agents/main/wire.jsonl') ?: [];
    if ($wires === []) {
        throw new \RuntimeException('the main wire of the session is not available');
    }

    return $wires[0];
}

// --self-test: the wiring task runs it before a gated run to prove the shim can write its files.
if (in_array('--self-test', $argv ?? [], true)) {
    try {
        if ((include __DIR__.'/../bootstrap.php') === false) {
            throw new \RuntimeException('bootstrap cannot be loaded');
        }
        $home = (string) getenv('KIMI_CODE_HOME');
        if ($home === '' || !is_dir($home) || !is_writable($home)) {
            throw new \RuntimeException('the run home is not writable');
        }
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }
}

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }

    // The known values come from the bot's `.env`, exactly as in bin/secret-guard-hook.php.
    $envPath = getenv(GitHooks::ENV_FILE_ENV) ?: __DIR__.'/../.env';
    $env = is_file($envPath) ? Env::load($envPath) : new Env();
    SecretScanner::setShared(new SecretScanner(new SecretSources($envPath, array_values($env->list('SECRET_ENV_FILES')))));

    $home = rtrim((string) getenv('KIMI_CODE_HOME'), '/');
    if ($home === '') {
        throw new \RuntimeException('the run home is not set');
    }

    $input = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($input)) {
        throw new \RuntimeException('stdin is not JSON');
    }

    $appendRecord = static function (array $record) use ($home): void {
        $path = $home.'/'.KimiHook::HOOK_RECORDS_FILE;
        if (@file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND) === false) {
            throw new \RuntimeException('cannot append the hook record');
        }
    };

    // The events are told apart by the fields the spike recorded: `tool_name` / `tool_call_id` /
    // `tool_input` mean PreToolUse, `session_id` with `model` and `profile` means SessionStart.
    $isPreToolUse = array_key_exists('tool_name', $input)
        || array_key_exists('tool_call_id', $input)
        || array_key_exists('tool_input', $input);
    $isSessionStart = array_key_exists('session_id', $input)
        && array_key_exists('model', $input)
        && array_key_exists('profile', $input);

    if ($isSessionStart && !$isPreToolUse) {
        $sessionId = $input['session_id'] ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            if (@file_put_contents($home.'/'.KimiHook::SESSION_ID_FILE, $sessionId) === false) {
                throw new \RuntimeException('cannot write the session id');
            }
        }
        $appendRecord([
            'event' => 'SessionStart',
            'tool_call_id' => null,
            'tool_name' => null,
            'agent_name' => null,
            'outcome' => null,
        ]);
        exit(0);
    }

    if (!$isPreToolUse) {
        throw new \RuntimeException('the input matches neither SessionStart nor PreToolUse');
    }

    $toolName = is_string($input['tool_name'] ?? null) ? $input['tool_name'] : null;
    $toolCallId = is_string($input['tool_call_id'] ?? null) ? $input['tool_call_id'] : null;
    $agentName = is_string($input['agent_name'] ?? null) && $input['agent_name'] !== '' ? $input['agent_name'] : null;

    $record = static fn (?string $outcome): array => [
        'event' => 'PreToolUse',
        'tool_call_id' => $toolCallId,
        'tool_name' => $toolName,
        'agent_name' => $agentName,
        'outcome' => $outcome,
    ];
    $deny = static function (string $reason) use ($appendRecord, $record): never {
        $appendRecord($record(KimiHook::OUTCOME_DENY));
        fwrite(STDERR, $reason."\n");
        exit(2);
    };

    // Only a shell command is gated; every other tool passes.
    if ($toolName !== 'Bash') {
        $appendRecord($record(KimiHook::OUTCOME_PASS));
        exit(0);
    }

    $command = $input['tool_input']['command'] ?? null;
    if (!is_string($command) || $command === '') {
        throw new \RuntimeException('the Bash call carries no command');
    }

    // The secret guard runs first, on every shell call: a guard deny is final and no PM gate skip is
    // recorded for it.
    $guardReason = SecretGuard::decide($command, SecretScanner::shared());
    if ($guardReason !== null) {
        $deny($guardReason);
    }

    $kind = (string) getenv(PmGateHook::KIND_ENV);
    $stateDir = rtrim((string) getenv(PmGateHook::STATE_ENV), '/');
    $runId = (string) getenv(PmGateHook::RUN_ENV);

    // The PM gate runs on a gated run for a command that creates an issue or PR. Any failure of the
    // gate itself (bad arguments, a missing snapshot, an unlocatable history) is a deny, not a silent
    // pass: the record is appended and the model sees the failure reason.
    if ($kind !== '' && PmGateHook::createsIssue($command)) {
        try {
            if ($stateDir === '' || !in_array($kind, PmRunbook::SKIP_KEYS, true) || preg_match('/^[A-Za-z0-9-]+$/', $runId) !== 1) {
                throw new \RuntimeException('bad gate arguments');
            }
            $config = PmGateHook::readSnapshot($stateDir, $runId);
            $skipFile = (string) getenv(PmGateHook::SKIP_ENV);
            $skipReason = $skipFile !== '' && is_file($skipFile) ? (string) file_get_contents($skipFile) : '';
            $history = locateHistory($home, $input);
            $decision = PmGateHook::decideFromInput(
                ['engine' => PmGateHook::ENGINE_KIMI, 'history' => $history],
                $config,
                $kind,
                $skipReason,
            );
        } catch (\RuntimeException) {
            $deny(KimiHook::FAILURE_REASON);
        }
        if ($decision['decision'] === PmGateHook::DENY) {
            $deny($decision['reason']);
        }
        if ($decision['skipped']) {
            if (@file_put_contents(PmGateHook::appliedPath($stateDir, $runId), trim($skipReason)) === false) {
                throw new \RuntimeException('cannot record the applied skip');
            }
        }
    }

    // The review commit hook runs on a review run, for any shell command (its check is a no-op for a
    // command that is not a review publication).
    if (getenv(ReviewCommitHook::ENABLED_ENV) === '1') {
        $shasFile = (string) getenv(ReviewCommitHook::SHAS_ENV);
        $shas = $shasFile !== '' ? ReviewCommitHook::readShas($shasFile) : [];
        $reviewReason = ReviewCommitHook::check($command, $shas);
        if ($reviewReason !== null) {
            $deny($reviewReason);
        }
    }

    $appendRecord($record(KimiHook::OUTCOME_PASS));
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, KimiHook::FAILURE_REASON."\n");
    exit(2);
}
