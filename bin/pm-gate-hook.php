<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/*
 * PreToolUse hook (matcher Bash) that gates issue creation, see PmGateHook. Called by claude,
 * not by the bot: php bin/pm-gate-hook.php <STATE_DIR> <run kind> <run id>, with the PreToolUse
 * event JSON on stdin and the skip reason file named by PmGateHook::SKIP_ENV.
 *
 * Fail-CLOSED, the opposite of the review Stop hook: any failure (including the bootstrap
 * not loading) exits 2 with a fixed reason on stderr, which blocks the command. A fatal that
 * cannot be caught exits 255, which claude does not treat as a block: the command in --settings
 * wraps this script with `|| exit 2`. A deny decision goes out as JSON on stdout (exit 0) and is
 * logged to <STATE_DIR>/pm-gate.log.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

$kind = '';
$stateDir = rtrim((string) ($argv[1] ?? ''), '/');
$log = static function (string $line) use ($stateDir): void {
    if ($stateDir !== '') {
        @file_put_contents($stateDir.'/pm-gate.log', '['.date('Y-m-d H:i:sP').'] '.$line."\n", FILE_APPEND);
    }
};

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }
    try {
        Timezone::applyFromEnvFile(__DIR__.'/../.env');
    } catch (\Throwable) {
        // Keep UTC: only the log timestamps depend on it.
    }

    $kind = (string) ($argv[2] ?? '');
    $runId = (string) ($argv[3] ?? '');
    if ($stateDir === '' || !in_array($kind, PmRunbook::SKIP_KEYS, true) || preg_match('/^[A-Za-z0-9-]+$/', $runId) !== 1) {
        throw new \RuntimeException('bad arguments');
    }
    $input = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($input)) {
        throw new \RuntimeException('stdin is not JSON');
    }
    $command = $input['tool_input']['command'] ?? null;
    if (($input['tool_name'] ?? 'Bash') !== 'Bash' || !is_string($command)) {
        exit(0);
    }
    if (!PmGateHook::createsIssue($command)) {
        exit(0);
    }

    $transcriptPath = (string) ($input['transcript_path'] ?? '');
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        throw new \RuntimeException('transcript is not available');
    }
    $lines = file($transcriptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new \RuntimeException('transcript cannot be read');
    }
    $config = PmGateHook::readSnapshot($stateDir, $runId);
    $skipFile = (string) getenv(PmGateHook::SKIP_ENV);
    $skipReason = $skipFile !== '' && is_file($skipFile) ? (string) file_get_contents($skipFile) : '';

    $decision = PmGateHook::decide($lines, $config, $kind, $skipReason);
    if ($decision['decision'] === PmGateHook::DENY) {
        $log("{$kind} {$runId} deny: {$decision['reason']}");
        echo PmGateHook::denyJson($decision['reason']);
    } elseif ($decision['skipped']) {
        $log("{$kind} {$runId} allow (skipped with a reason)");
        if (@file_put_contents(PmGateHook::appliedPath($stateDir, $runId), trim($skipReason)) === false) {
            throw new \RuntimeException('cannot record the applied skip');
        }
    }
} catch (\Throwable $e) {
    $log("{$kind} error: ".$e->getMessage());
    fwrite(STDERR, "issue creation blocked: the gate failed, see state/pm-gate.log\n");
    exit(2);
}

exit(0);
