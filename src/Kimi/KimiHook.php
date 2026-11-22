<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The file names and shapes the Kimi gate shim (`bin/kimi-hook.php`) writes into the run home and the
 * wiring task reads back. A bin script cannot export constants, so they live here: the shim uses them
 * to name its files, and the wiring task imports them to find the session id and the hook records.
 *
 * The run home is `state/kimi-<runId>` (passed to the run as `KIMI_CODE_HOME`); the shim writes the
 * session id of a `SessionStart` and appends one hook record per call there.
 */
final class KimiHook
{
    /** The file the shim writes the session id of a `SessionStart` into, relative to the run home. */
    public const string SESSION_ID_FILE = 'session-id';

    /** The file the shim appends one JSON record per call to, relative to the run home. */
    public const string HOOK_RECORDS_FILE = 'hook-records.jsonl';

    /**
     * A Kimi session id is `session_<uuid>`: the prefix and 36 characters of a lowercase UUID. The
     * shim uses a session id only when it matches this pattern, so a value from the payload or the
     * session-id file can never reach the history glob as a pattern or a path.
     */
    public const string SESSION_ID_PATTERN = '/^session_[0-9a-f-]{36}$/';

    /** The `outcome` of a hook record for a call the gate allowed. */
    public const string OUTCOME_PASS = 'pass';

    /** The `outcome` of a hook record for a call the gate denied. */
    public const string OUTCOME_DENY = 'deny';

    /** What the model sees when the shim itself fails (a bootstrap, input or gate-state failure). */
    public const string FAILURE_REASON = 'The command was blocked: the gate failed.';
}
