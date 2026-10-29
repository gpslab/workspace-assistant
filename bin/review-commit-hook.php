<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/*
 * PreToolUse hook (matcher Bash) that keeps a formal PR review on the commit that was reviewed, see
 * ReviewCommitHook. Called by claude, not by the bot: php bin/review-commit-hook.php <STATE_DIR>, with
 * the PreToolUse event JSON on stdin and the file of recorded SHAs named by ReviewCommitHook::SHAS_ENV
 * (unset: no SHA was recorded, only the form of the command is checked).
 *
 * Fail-CLOSED like bin/pm-gate-hook.php: any failure exits 2 with a fixed reason on stderr. A deny
 * decision goes out as JSON on stdout (exit 0) and is logged to <STATE_DIR>/review-commit-hook.log.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

$stateDir = rtrim((string) ($argv[1] ?? ''), '/');
$log = static function (string $line) use ($stateDir): void {
    if ($stateDir !== '') {
        @file_put_contents($stateDir.'/review-commit-hook.log', '['.date('Y-m-d H:i:sP').'] '.$line."\n", FILE_APPEND);
    }
};

try {
    if ((include __DIR__.'/../bootstrap.php') === false) {
        throw new \RuntimeException('bootstrap cannot be loaded');
    }
    if ($stateDir === '') {
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
    $shasFile = (string) getenv(ReviewCommitHook::SHAS_ENV);
    $shas = $shasFile !== '' ? ReviewCommitHook::readShas($shasFile) : [];

    $reason = ReviewCommitHook::check($command, $shas);
    if ($reason !== null) {
        $log('deny: '.$reason);
        echo PmGateHook::denyJson($reason);
    }
} catch (\Throwable $e) {
    $log('error: '.$e->getMessage());
    fwrite(STDERR, ReviewCommitHook::FAILURE_REASON."\n");
    exit(2);
}

exit(0);
