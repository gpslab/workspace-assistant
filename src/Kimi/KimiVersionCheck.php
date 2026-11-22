<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The result of {@see KimiVersionGuard::check()}: how each of the two format fields of a Kimi run
 * stands against the verified values.
 *
 * The two fields are reported independently: an unknown CLI version says nothing about the
 * `wire.jsonl` protocol, and vice versa.
 */
final class KimiVersionCheck
{
    public function __construct(
        public readonly KimiFormatStatus $version,
        public readonly KimiFormatStatus $protocol,
    ) {
    }

    /**
     * Whether the wire format is untrusted: the protocol version is present but is not one of the
     * verified ones, so a self-update may have changed `wire.jsonl` behind the reader.
     *
     * A `missing` protocol version is not untrusted: the window simply does not reach the head of
     * the file, where that record is written, and the caller takes it from there. An unknown CLI
     * version on its own does not untrust the format either — the protocol version decides.
     */
    public function isUntrusted(): bool
    {
        return $this->protocol === KimiFormatStatus::Unknown;
    }
}
