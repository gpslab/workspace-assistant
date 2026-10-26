<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Exception\TelegramApiException;
use GpsLab\WorkspaceAssistant\Exception\TelegramTransportException;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Delivery::class)]
final class DeliveryTest extends TestCase
{
    private const int CHAT = 100;

    private string $outbox;

    protected function setUp(): void
    {
        $this->outbox = sys_get_temp_dir().'/delivery-test-'.bin2hex(random_bytes(6));
        mkdir($this->outbox, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outbox.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->outbox);
    }

    public function testDeliversToTheRequestedTopic(): void
    {
        $tg = new FakeTelegram();

        $result = $this->makeDelivery($tg)->deliverMessage(self::CHAT, 7, 'hello');

        self::assertSame(7, $result['thread_id']);
        self::assertSame(['hello'], $tg->textsTo(self::CHAT, 7));
    }

    public function testApiRefusalFallsBackToTheChatRoot(): void
    {
        $tg = new FakeTelegram();
        $tg->failThread = 7;

        $threadId = $this->makeDelivery($tg)->deliver(self::CHAT, 7, 'hello');

        self::assertSame(0, $threadId);
        self::assertSame([], $tg->textsTo(self::CHAT, 7));
        self::assertSame(['hello'], $tg->textsTo(self::CHAT, 0));
    }

    public function testApiRefusalInTheRootIsRethrown(): void
    {
        $tg = new FakeTelegram();
        $tg->failThread = 0;

        $this->expectException(TelegramApiException::class);
        $this->makeDelivery($tg)->deliver(self::CHAT, 0, 'hello');
    }

    public function testTransportFailureBeforeSendRetriesTheSameTopic(): void
    {
        $tg = $this->failingTelegram([new TelegramTransportException('connect failed', 7, false)]);

        $threadId = $this->makeDelivery($tg)->deliver(self::CHAT, 7, 'hello');

        self::assertSame(7, $threadId);
        self::assertSame([7, 7], $tg->sendAttempts);
        self::assertSame(['hello'], $tg->textsTo(self::CHAT, 7));
        self::assertSame([], $tg->textsTo(self::CHAT, 0));
    }

    public function testTransportFailureAfterSendIsNotRetriedNorForwarded(): void
    {
        $tg = $this->failingTelegram([new TelegramTransportException('timeout', 28, true)]);

        try {
            $this->makeDelivery($tg)->deliver(self::CHAT, 7, 'hello');
            self::fail('The transport failure must be rethrown.');
        } catch (TelegramTransportException $e) {
            self::assertTrue($e->requestSent);
        }

        self::assertSame([7], $tg->sendAttempts, 'There must be no second sendText.');
        self::assertSame([], $tg->sent);
    }

    public function testSafeDeliverSwallowsFailures(): void
    {
        $tg = $this->failingTelegram([new TelegramTransportException('timeout', 28, true)]);

        $this->makeDelivery($tg)->safeDeliver(self::CHAT, 7, 'hello');

        self::assertSame([7], $tg->sendAttempts);
    }

    public function testClearOutboxDeletesFilesOnly(): void
    {
        file_put_contents($this->outbox.'/a.txt', 'a');
        mkdir($this->outbox.'/dir');

        $this->makeDelivery(new FakeTelegram())->clearOutbox();

        self::assertSame([$this->outbox.'/dir'], glob($this->outbox.'/*'));
        rmdir($this->outbox.'/dir');
    }

    public function testFlushOutboxSendsFilesAndClearsTheDirectory(): void
    {
        file_put_contents($this->outbox.'/b.txt', 'b');
        file_put_contents($this->outbox.'/a.png', 'a');
        $tg = new FakeTelegram();

        $this->makeDelivery($tg)->flushOutbox(self::CHAT, 7);

        self::assertSame(['a.png', 'b.txt'], array_column($tg->files, 'name'));
        self::assertSame([], glob($this->outbox.'/*'));
        self::assertSame([], $tg->sent);
    }

    public function testFlushOutboxCapsFilesAndReportsTheRest(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            file_put_contents(sprintf('%s/f%02d.txt', $this->outbox, $i), 'x');
        }
        $tg = new FakeTelegram();

        $this->makeDelivery($tg)->flushOutbox(self::CHAT, 7);

        self::assertCount(10, $tg->files);
        self::assertSame([], glob($this->outbox.'/*'));
        self::assertCount(1, $tg->sent);
        self::assertStringContainsString('2', $tg->sent[0]['text']);
    }

    public function testFlushOutboxClearsFilesWhenSendingFails(): void
    {
        file_put_contents($this->outbox.'/a.txt', 'a');
        $tg = new FakeTelegram();
        $tg->fileFails = true;
        $delivery = $this->makeDelivery($tg);

        $delivery->flushOutbox(self::CHAT, 7);

        self::assertSame([], glob($this->outbox.'/*'));
        self::assertCount(1, $tg->sent, 'A note about the failed file is delivered.');
        self::assertStringContainsString('a.txt', $tg->sent[0]['text']);
    }

    public function testFlushOutboxDoesNothingForAnEmptyDirectory(): void
    {
        $tg = new FakeTelegram();

        $this->makeDelivery($tg)->flushOutbox(self::CHAT, 7);

        self::assertSame([], $tg->sent);
        self::assertSame([], $tg->files);
    }

    public function testOverflowCaptionIsNullOnlyInArchMode(): void
    {
        $delivery = $this->makeDelivery(new FakeTelegram());

        self::assertNull($delivery->overflowCaption(SystemPrompt::MODE_ARCH));
        self::assertNotNull($delivery->overflowCaption('dev'));
    }

    public function testTruncateSummaryKeepsShortTextAndCutsByWholeLines(): void
    {
        $delivery = $this->makeDelivery(new FakeTelegram());

        self::assertSame("one\ntwo", $delivery->truncateSummary("one\ntwo"));

        $line = str_repeat('x', 999);
        $cut = $delivery->truncateSummary(implode("\n", array_fill(0, 5, $line)));

        $lines = explode("\n", $cut);
        self::assertSame([$line, $line], array_slice($lines, 0, 2));
        self::assertCount(3, $lines);
        self::assertStringContainsString('3', $lines[2]);
    }

    public function testTruncateSummaryBudgetCountsEscapedLength(): void
    {
        $delivery = $this->makeDelivery(new FakeTelegram());
        // "<" escapes to "&lt;" (4 chars): 700 of them cost 2800 > 2500.
        $cut = $delivery->truncateSummary(str_repeat('<', 700)."\nrest");

        self::assertStringNotContainsString('rest', $cut);
        self::assertStringNotContainsString('<', $cut);
    }

    private function makeDelivery(FakeTelegram $tg): Delivery
    {
        return new Delivery($tg, $this->makeConfig(), new Locale('en'));
    }

    /**
     * @param list<\Throwable> $failures thrown by the first sendText calls
     */
    private function failingTelegram(array $failures): FakeTelegram
    {
        $tg = new FakeTelegram();
        $tg->sendFailures = $failures;

        return $tg;
    }

    private function makeConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            githubRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: sys_get_temp_dir(),
            outboxDir: $this->outbox,
            inboxDir: sys_get_temp_dir(),
            pollTimeout: 30,
            gitTimeout: 120,
            botLocale: 'en',
        );
    }
}
