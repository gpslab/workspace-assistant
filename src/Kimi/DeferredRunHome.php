<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * A run home that outlives the run and is removed by the caller afterwards.
 *
 * The Kimi gate shim writes the audit and the hook records into the run home; the engine run itself
 * must not delete it. The Bot removes it with {@see purgeRunHome()} after its post-run checks.
 */
interface DeferredRunHome
{
    /** Removes the run home of the finished run. Safe to call more than once. */
    public function purgeRunHome(): void;
}
