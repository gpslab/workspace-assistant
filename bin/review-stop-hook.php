<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\Run;

/*
 * Stop hook of review mode (see ReviewStatusHook). Called by claude, not by the bot:
 * php bin/review-stop-hook.php <STATE_DIR>, with the Stop event JSON on stdin.
 *
 * Fail-open: any hook error lets the turn go (exit 0), but it is written to
 * <STATE_DIR>/review-hook.log — it is not lost silently. stdout carries only the decision
 * JSON: a warning/deprecation before it would break claude's parsing.
 * The one exception: a block decision survives a failure to load the reason texts
 * (a hardcoded reason is returned), so the hook never turns a block into a pass.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__.'/../bootstrap.php';

// The hook is run by claude and does not load Config: apply TIMEZONE from .env separately.
try {
    Timezone::applyFromEnvFile(__DIR__.'/../.env');
} catch (\Throwable) {
    // Fail-open: keep UTC.
}

$stateDir = rtrim((string) ($argv[1] ?? ''), '/');
$log = static function (string $line) use ($stateDir): void {
    if ($stateDir !== '') {
        @file_put_contents($stateDir.'/review-hook.log', '['.date('Y-m-d H:i:sP').'] '.$line."\n", FILE_APPEND);
    }
};

try {
    $input = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($input)) {
        throw new \RuntimeException('stdin is not JSON');
    }
    $sessionId = (string) ($input['session_id'] ?? '');
    $transcriptPath = (string) ($input['transcript_path'] ?? '');
    $lines = $transcriptPath !== '' && is_file($transcriptPath)
        ? (file($transcriptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        : [];

    // Cold reviewer finding ids (manual review). In an epic run the id check
    // is skipped: coverage is checked there by the signal.
    $ids = null;
    $idsFile = (string) getenv(ColdFindings::IDS_ENV);
    if ($idsFile !== '' && (string) getenv(Run::SIGNAL_ENV) === '' && is_file($idsFile)) {
        $decoded = json_decode((string) file_get_contents($idsFile), true);
        $ids = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    $decision = ReviewStatusHook::decide($input, $lines, $ids);
    $log("{$sessionId} {$decision}");

    if ($decision === ReviewStatusHook::BLOCK) {
        echo json_encode(['decision' => 'block', 'reason' => ReviewStatusHook::reasonOrFallback($input, $lines, $ids, static fn (\Throwable $e) => $log('reason error: '.$e->getMessage()))], JSON_UNESCAPED_UNICODE);
    } elseif ($decision === ReviewStatusHook::MISSING && $stateDir !== '' && $sessionId !== '') {
        if (ReviewStatusHook::statusOk($input, $lines)) {
            ReviewStatusHook::writeIdsMarker($stateDir, $sessionId, ReviewStatusHook::missingIds($lines, $ids ?? []));
        } else {
            $marker = ReviewStatusHook::markerPath($stateDir, $sessionId);
            @mkdir(dirname($marker), 0775, true);
            @touch($marker);
        }
    }
} catch (\Throwable $e) {
    $log('error: '.$e->getMessage());
}

exit(0);
