<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * Decides whether a Kimi run carries the formats the Kimi readers were verified against.
 *
 * Pure: it takes the two values the caller has already read — the `version` of the stdout
 * `system.version` event ({@see KimiStreamParser}) and the `metadata.protocol_version` of
 * `wire.jsonl` ({@see KimiWireReader}) — and performs no I/O. Both formats are private and
 * undocumented, so a Kimi self-update can change either and silently break history, usage and
 * audit; this guard is how the caller notices.
 *
 * The two values are checked separately, and a `null` value is reported as `missing`, not as
 * unknown: `metadata` is written once at the start of a `wire.jsonl`, so a window of a resumed run
 * has no protocol version and the caller takes it from the head of the file.
 */
final class KimiVersionGuard
{
    /**
     * The verified formats of the files in `tests/fixtures/kimi/`: one list per field. `version` is
     * the `system.version` of the stream-json stdout, `protocol_version` the value that
     * `metadata` of `wire.jsonl` is written with.
     *
     * @var array{version: list<string>, protocol_version: list<string>}
     */
    public const array VERIFIED_FORMATS = [
        'version' => ['2.7.2'],
        'protocol_version' => ['1.5'],
    ];

    /**
     * How each value stands against {@see VERIFIED_FORMATS}. A `null` value is `missing`; a value
     * that is not one of the verified ones is `unknown`.
     */
    public static function check(?string $version, ?string $protocolVersion): KimiVersionCheck
    {
        return new KimiVersionCheck(
            self::status($version, self::VERIFIED_FORMATS['version']),
            self::status($protocolVersion, self::VERIFIED_FORMATS['protocol_version']),
        );
    }

    /**
     * @param list<string> $known
     */
    private static function status(?string $value, array $known): KimiFormatStatus
    {
        if ($value === null) {
            return KimiFormatStatus::Missing;
        }

        return in_array($value, $known, true) ? KimiFormatStatus::Known : KimiFormatStatus::Unknown;
    }
}
