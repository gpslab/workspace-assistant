<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\ClaudeErrorText;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeLimit;
use GpsLab\WorkspaceAssistant\Opencode\OpencodeNdjsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpencodeLimit::class)]
final class OpencodeLimitTest extends TestCase
{
    private function classifier(): ClaudeErrorText
    {
        return new ClaudeErrorText(new Locale());
    }

    public function testProviderQuotaFixtureIsQuota(): void
    {
        $error = $this->parsedError('error-provider-quota.ndjson');

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testProviderAuthFixtureIsNotALimit(): void
    {
        $error = $this->parsedError('error-provider-auth.ndjson');

        self::assertNull(OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testErrorMessageWithQuotaWordIsQuota(): void
    {
        $error = ['type' => 'provider.unknown', 'message' => 'Your quota has been exceeded.'];

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testErrorMessageWithBalanceWordIsQuota(): void
    {
        $error = ['type' => 'provider.unknown', 'message' => 'Account balance is exhausted.'];

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testErrorMessageWithInsufficientWordIsQuota(): void
    {
        $error = ['type' => 'provider.unknown', 'message' => 'Insufficient credits.'];

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testUnrecognised429InErrorMessageIsRateWithoutReset(): void
    {
        $error = ['type' => 'provider.unknown', 'message' => 'HTTP 429 Too Many Requests'];

        self::assertSame('rate', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testProvider429StatusIsRateWhenTheTextIsNeutral(): void
    {
        $error = ['type' => 'provider.unknown', 'status' => 429, 'message' => 'Too Many Requests'];

        self::assertSame('rate', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testProvider402StatusIsQuotaWhenTheTextIsNeutral(): void
    {
        $error = ['type' => 'provider.unknown', 'status' => 402, 'message' => 'Payment required.'];

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testQuotaTextWinsOverA429Status(): void
    {
        $error = ['type' => 'provider.unknown', 'status' => 429, 'message' => 'Insufficient credits.'];

        self::assertSame('quota', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testRateLimitTextIsRate(): void
    {
        $error = ['type' => 'provider.unknown', 'message' => 'You are being rate limited.'];

        self::assertSame('rate', OpencodeLimit::classify($error, $this->classifier()));
    }

    public function testNoErrorIsNotALimit(): void
    {
        self::assertNull(OpencodeLimit::classify(null, $this->classifier()));
    }

    /**
     * The `error` object the parser extracts from a fixture (`error` event).
     *
     * @return array<string, mixed>|null
     */
    private function parsedError(string $file): ?array
    {
        $content = (string) file_get_contents(__DIR__.'/../fixtures/opencode/run/'.$file);
        $turn = OpencodeNdjsonParser::parse(array_values(array_filter(explode("\n", $content), static fn (string $l): bool => $l !== '')));

        return $turn->error;
    }
}
