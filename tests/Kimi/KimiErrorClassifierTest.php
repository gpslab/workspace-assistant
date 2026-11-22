<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\ClaudeErrorText;
use GpsLab\WorkspaceAssistant\Kimi\KimiErrorClass;
use GpsLab\WorkspaceAssistant\Kimi\KimiErrorClassifier;
use GpsLab\WorkspaceAssistant\Kimi\KimiStream;
use GpsLab\WorkspaceAssistant\Kimi\KimiStreamParser;
use GpsLab\WorkspaceAssistant\Kimi\KimiTurnError;
use GpsLab\WorkspaceAssistant\Kimi\KimiWireReader;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiErrorClassifier::class)]
#[CoversClass(KimiErrorClass::class)]
final class KimiErrorClassifierTest extends TestCase
{
    public function testAuthFixtureIsAuth(): void
    {
        $class = $this->classify('30-mock-auth', 1);

        self::assertSame(KimiErrorClass::Auth, $class);
        // A refused account is shown in the chat; the queue is not paused and the session is kept.
        self::assertNull($class->limitClass());
    }

    public function testOccupiedFixtureIsOccupied(): void
    {
        $class = $this->classify('06-occupied-second', 4);

        self::assertSame(KimiErrorClass::Occupied, $class);
        self::assertNull($class->limitClass());
    }

    /**
     * The exit code is part of the definition of `Occupied`: the same `session.occupied` event with
     * another exit code is a different failure.
     */
    public function testAnOccupiedStreamWithoutExitCodeFourIsNotOccupied(): void
    {
        $class = $this->classify('06-occupied-second', 1);

        self::assertNotSame(KimiErrorClass::Occupied, $class);
    }

    /**
     * The `session.occupied` event is part of the definition of `Occupied`: exit 4 without it is a
     * different failure than a session held by another instance.
     */
    public function testExitCodeFourWithoutTheSessionOccupiedStreamErrorIsNotOccupied(): void
    {
        $class = KimiErrorClassifier::classify(4, '', self::emptyStream(), null, $this->text());

        self::assertNotSame(KimiErrorClass::Occupied, $class);
    }

    /** Exit 4 with a stream error of another code is not `Occupied` either. */
    public function testExitCodeFourWithANonOccupiedStreamErrorIsNotOccupied(): void
    {
        $stream = new KimiStream(null, [], '', null, ['code' => 'other.error', 'message' => 'boom'], 0);

        $class = KimiErrorClassifier::classify(4, '', $stream, null, $this->text());

        self::assertNotSame(KimiErrorClass::Occupied, $class);
    }

    public function testRateFixtureIsRate(): void
    {
        $class = $this->classify('30-mock-rate', 1);

        self::assertSame(KimiErrorClass::Rate, $class);
        self::assertSame('rate', $class->limitClass());
    }

    public function testQuotaFixtureIsQuotaEvenThoughItsCodeIsTheSharedApiError(): void
    {
        // The fixture's code is `provider.api_error`, the same one an HTTP 500 carries: only the
        // name tells the two apart, so a classifier that reads the code alone reads it as a server
        // error.
        $wireError = self::wireError('30-mock-quota');
        self::assertNotNull($wireError);
        self::assertSame('provider.api_error', $wireError->code);
        self::assertSame('APIProviderQuotaExhaustedError', $wireError->name);

        $class = $this->classify('30-mock-quota', 1);

        self::assertSame(KimiErrorClass::Quota, $class);
        // Waiting does not refill a balance: a quota is shown, it does not pause the queue.
        self::assertSame('quota', $class->limitClass());
    }

    public function testServerFixtureIsNeitherQuotaNorAuth(): void
    {
        // A 500 shares `provider.api_error` with the quota 429; it must not be read as a quota, and
        // it is not an auth failure either. Its retried failure ends as an unclassified exit 1.
        $class = $this->classify('30-mock-server', 1);

        self::assertNotSame(KimiErrorClass::Quota, $class);
        self::assertNotSame(KimiErrorClass::Auth, $class);
        self::assertSame(KimiErrorClass::Failed, $class);
    }

    public function testBadModelFixtureIsModelNotConfigured(): void
    {
        $class = $this->classify('04-bad-model', 1);

        self::assertSame(KimiErrorClass::ModelNotConfigured, $class);
        self::assertNull($class->limitClass());
    }

    public function testBadSessionFixtureIsSessionNotFound(): void
    {
        $class = $this->classify('05-bad-session', 1);

        self::assertSame(KimiErrorClass::SessionNotFound, $class);
        self::assertNull($class->limitClass());
    }

    public function testAHookFixtureThatSucceededIsNotAFailure(): void
    {
        // A `PreToolUse` hook that exits 1 lets the call run: the scenario exits 0 and prints only
        // the probe marker on stderr, which is not a known failure.
        self::assertSame(KimiErrorClass::Unknown, $this->classify('08-hook-exit1', 0));
    }

    public function testExitOneWithAnEmptyStreamAndNoErrorIsFailed(): void
    {
        $class = KimiErrorClassifier::classify(1, '', self::emptyStream(), null, $this->text());

        self::assertSame(KimiErrorClass::Failed, $class);
        self::assertNull($class->limitClass());
    }

    public function testAnUnrecognisedErrorIsUnknown(): void
    {
        $class = KimiErrorClassifier::classify(3, 'error: something went wrong', self::emptyStream(), null, $this->text());

        self::assertSame(KimiErrorClass::Unknown, $class);
        self::assertNull($class->limitClass());
    }

    public function testARateLimitStderrWithoutAWireErrorIsRate(): void
    {
        $class = KimiErrorClassifier::classify(1, 'error: HTTP 429 Too Many Requests', self::emptyStream(), null, $this->text());

        self::assertSame(KimiErrorClass::Rate, $class);
        self::assertSame('rate', $class->limitClass());
    }

    public function testAQuotaStderrWithoutAWireErrorIsQuota(): void
    {
        foreach (['error: insufficient balance', 'error: your quota has been exceeded'] as $stderr) {
            $class = KimiErrorClassifier::classify(1, $stderr, self::emptyStream(), null, $this->text());

            self::assertSame(KimiErrorClass::Quota, $class, $stderr);
            self::assertSame('quota', $class->limitClass(), $stderr);
        }
    }

    /**
     * The fixture messages themselves carry "quota" and "rate limit", so a classifier that reads the
     * text before the code and the name would answer on those words. Replacing the message with a
     * neutral one must not change the verdict.
     */
    public function testTheQuotaFixtureIsQuotaByNameWhateverItsMessageSays(): void
    {
        $class = $this->classifyWithNeutralMessage('30-mock-quota');

        self::assertSame(KimiErrorClass::Quota, $class);
    }

    public function testTheRateFixtureIsRateByCodeWhateverItsMessageSays(): void
    {
        $class = $this->classifyWithNeutralMessage('30-mock-rate');

        self::assertSame(KimiErrorClass::Rate, $class);
    }

    public function testTheAuthFixtureIsAuthWhateverItsMessageSays(): void
    {
        $wireError = self::wireError('30-mock-auth');
        self::assertNotNull($wireError);

        // A message that names both a rate limit and a quota must not talk the classifier out of
        // the code: the code is checked first.
        $hostile = self::withMessage($wireError, 'HTTP 429 rate limit: insufficient quota, please top up your balance');

        $class = KimiErrorClassifier::classify(1, self::stderr('30-mock-auth'), self::stream('30-mock-auth'), $hostile, $this->text());

        self::assertSame(KimiErrorClass::Auth, $class);
    }

    /**
     * The message of a wire error is consulted only when its code and name give no class — the last
     * wire signal before stderr, which is not consulted at all while a wire error exists.
     */
    public function testAWireMessageThatNamesALimitIsUsedWhenTheCodeAndNameGiveNoClass(): void
    {
        $quota = new KimiTurnError('provider.api_error', 'You exceeded your current quota', 'APIStatusError', false, 500);
        $rate = new KimiTurnError('provider.api_error', 'Rate limit reached for requests', 'APIStatusError', true, 429);

        self::assertSame(
            KimiErrorClass::Quota,
            KimiErrorClassifier::classify(1, 'error: generic failure', self::emptyStream(), $quota, $this->text()),
        );
        self::assertSame(
            KimiErrorClass::Rate,
            KimiErrorClassifier::classify(1, 'error: generic failure', self::emptyStream(), $rate, $this->text()),
        );
    }

    /**
     * stderr is consulted only when the run left no wire error: a classified wire error wins over
     * it, and an unclassified one hides it entirely. The generic "Prompt turn ended with reason:
     * failed" line of the mock fixtures would otherwise decide the class of every provider error.
     */
    public function testStderrIsNotConsultedWhileAWireErrorExists(): void
    {
        $rate = new KimiTurnError('provider.rate_limit', 'Rate limit reached', 'APIProviderRateLimitError', true, 429);
        self::assertSame(
            KimiErrorClass::Rate,
            KimiErrorClassifier::classify(1, 'error: insufficient balance', self::emptyStream(), $rate, $this->text()),
        );

        $server = new KimiTurnError('provider.api_error', 'Internal server error', 'APIStatusError', true, 500);
        self::assertSame(
            KimiErrorClass::Failed,
            KimiErrorClassifier::classify(1, 'error: insufficient balance', self::emptyStream(), $server, $this->text()),
        );
    }

    public function testOnlyRateAndQuotaPauseTheQueue(): void
    {
        self::assertSame('rate', KimiErrorClass::Rate->limitClass());
        self::assertSame('quota', KimiErrorClass::Quota->limitClass());

        $pausing = [KimiErrorClass::Rate, KimiErrorClass::Quota];
        foreach (KimiErrorClass::cases() as $class) {
            if (!in_array($class, $pausing, true)) {
                self::assertNull($class->limitClass(), $class->name);
            }
        }
    }

    /**
     * Classifies a run fixture: its stream-json stdout as the stream, its `wire.jsonl` error (null
     * when the scenario has no wire directory) and its stderr.
     */
    private function classify(string $scenario, int $exitCode): KimiErrorClass
    {
        return KimiErrorClassifier::classify(
            $exitCode,
            self::stderr($scenario),
            self::stream($scenario),
            self::wireError($scenario),
            $this->text(),
        );
    }

    /** The same, with the fixture's wire error message replaced by a neutral one. */
    private function classifyWithNeutralMessage(string $scenario, int $exitCode = 1): KimiErrorClass
    {
        $wireError = self::wireError($scenario);
        self::assertNotNull($wireError);

        return KimiErrorClassifier::classify(
            $exitCode,
            self::stderr($scenario),
            self::stream($scenario),
            self::withMessage($wireError, 'the provider refused the turn'),
            $this->text(),
        );
    }

    private static function withMessage(KimiTurnError $error, string $message): KimiTurnError
    {
        return new KimiTurnError($error->code, $message, $error->name, $error->retryable, $error->statusCode);
    }

    private static function emptyStream(): KimiStream
    {
        return new KimiStream(null, [], '', null, null, 0);
    }

    private function text(): ClaudeErrorText
    {
        return new ClaudeErrorText(new Locale());
    }

    private static function stream(string $scenario): KimiStream
    {
        return KimiStreamParser::parse(self::lines(__DIR__.'/../fixtures/kimi/run/'.$scenario.'.stdout.jsonl'));
    }

    private static function stderr(string $scenario): string
    {
        $file = __DIR__.'/../fixtures/kimi/run/'.$scenario.'.stderr.txt';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private static function wireError(string $scenario): ?KimiTurnError
    {
        $file = __DIR__.'/../fixtures/kimi/wire/'.$scenario.'/main.jsonl';

        return is_file($file) ? KimiWireReader::read(self::lines($file))->error : null;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $file): array
    {
        return array_values(array_filter(
            explode("\n", (string) file_get_contents($file)),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }
}
