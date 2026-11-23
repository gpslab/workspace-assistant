<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Kimi;

/**
 * The result of {@see KimiGatedPreconditions::check()}: ok, or the conditions a gated mode may not run
 * under on Kimi.
 *
 * The failing conditions are kept as {@see KimiGateReason} values, because each of them is refused in
 * the chat with its own text: `reasons` is the English form for `bot.log` and `failed` is what the
 * refusal and the tests read.
 */
final class KimiGatedPreconditionResult
{
    /**
     * The failing conditions as `bot.log` carries them, in the order they are checked.
     *
     * @var list<string>
     */
    public readonly array $reasons;

    /**
     * @param list<KimiGateReason>  $failed  the conditions that do not hold, in the order they are checked
     * @param array<string, string> $details the value a failing condition is about (the protocol version,
     *                                       the self-test failure text), keyed by {@see KimiGateReason::value}
     */
    private function __construct(
        public readonly array $failed,
        public readonly array $details,
    ) {
        $this->reasons = array_map(
            fn (KimiGateReason $reason): string => $reason->logText($this->detail($reason)),
            $failed,
        );
    }

    public static function ok(): self
    {
        return new self([], []);
    }

    /**
     * @param list<KimiGateReason>  $failed
     * @param array<string, string> $details
     */
    public static function failed(array $failed, array $details = []): self
    {
        return new self($failed, $details);
    }

    public function isOk(): bool
    {
        return $this->failed === [];
    }

    /** The value a failing condition is about ('' when it has none): the version, the self-test text. */
    public function detail(KimiGateReason $reason): string
    {
        return $this->details[$reason->value] ?? '';
    }
}
