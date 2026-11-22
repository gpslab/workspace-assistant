<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Status of one format field of a Kimi run against the verified values of
 * {@see KimiVersionGuard::VERIFIED_FORMATS}.
 */
enum KimiFormatStatus: string
{
    /** The value is one of the verified ones: the format is the one the readers were written against. */
    case Known = 'known';

    /** The value is present but not verified: a Kimi update may have changed the format. */
    case Unknown = 'unknown';

    /** The value is absent: the window does not reach the head of the file, where it is written. */
    case Missing = 'missing';
}
