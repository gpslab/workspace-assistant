<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The facts of a Kimi run that only its `wire.jsonl` holds, summed by {@see KimiWireReader::read()}
 * over a window of the file: the turn's tokens, the model and the protocol version the session was
 * bound to, and the error of the last failed turn of the window. Stdout (`stream-json`) carries
 * none of them.
 */
final class KimiWire
{
    /**
     * @param int            $inputOther         the sum of `usage.inputOther` over the window
     * @param int            $output             the sum of `usage.output` over the window
     * @param int            $inputCacheRead     the sum of `usage.inputCacheRead` over the window
     * @param int            $inputCacheCreation the sum of `usage.inputCacheCreation` over the window
     * @param ?string        $model              the model alias of `profile.bind`; null when the window does not
     *                                           reach the head of the file, where that record is written
     * @param ?string        $protocolVersion    `metadata.protocol_version`; null when the window does not reach
     *                                           the head of the file, where that record is written
     * @param ?KimiTurnError $error              the error of the last `turn.ended` of the window that has one; null
     *                                           when every turn of the window completed
     */
    public function __construct(
        public readonly int $inputOther,
        public readonly int $output,
        public readonly int $inputCacheRead,
        public readonly int $inputCacheCreation,
        public readonly ?string $model,
        public readonly ?string $protocolVersion,
        public readonly ?KimiTurnError $error,
    ) {
    }

    /** Whether a turn of the window ended with an error. */
    public function isError(): bool
    {
        return $this->error !== null;
    }
}
