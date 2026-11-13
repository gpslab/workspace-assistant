<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ClaudeRunner;
use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Delivery;
use GpsLab\WorkspaceAssistant\Locale;
use GpsLab\WorkspaceAssistant\SecretScanner;
use GpsLab\WorkspaceAssistant\SecretSources;
use GpsLab\WorkspaceAssistant\TelegramClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Masking at every outgoing path: it happens before truncation and Markdown conversion. */
#[CoversClass(TelegramClient::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(ClaudeRunner::class)]
final class SecretMaskingTest extends TestCase
{
    // Built by concatenation: no literal token in the repository.
    private const string SECRET = 'snake_Zq7Xm2_Vb9Lk4Pw8';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/secret-masking-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/outbox', 0775, true);
        file_put_contents($this->dir.'/.env', 'MY_SECRET='.self::SECRET);
        SecretScanner::setShared(new SecretScanner(new SecretSources($this->dir.'/.env', [], [])));
    }

    protected function tearDown(): void
    {
        SecretScanner::setShared(null);
        foreach (glob($this->dir.'/{outbox/*,.env,*}', GLOB_BRACE) ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir.'/outbox');
        @rmdir($this->dir);
    }

    public function testProgressLineIsMaskedBeforeClip(): void
    {
        // The secret starts before the 180-character clip and ends after it.
        $text = str_repeat('x', 170).' '.self::SECRET;

        $lines = ClaudeRunner::progressLinesFromEvent(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]]);

        self::assertCount(1, $lines);
        self::assertStringNotContainsString('snake', $lines[0]);
        self::assertStringContainsString('[REDACTED', $lines[0]);
    }

    public function testToolCallProgressIsMasked(): void
    {
        $command = 'curl -H "Authorization: '.self::SECRET.'" https://example.com';

        $line = ClaudeRunner::formatToolUse('Bash', ['command' => $command]);

        self::assertStringNotContainsString('snake', $line);
        self::assertStringNotContainsString('Zq7X', $line);
    }

    public function testSendTextMasksBeforeMarkdownConversion(): void
    {
        $tg = new RecordingTelegram();

        $tg->sendText(1, 'key: `'.self::SECRET.'` and _'.self::SECRET.'_');

        self::assertSame('key: <code>[REDACTED]</code> and <i>[REDACTED]</i>', $tg->calls[0]['params']['text']);
    }

    public function testSendTextMasksBeforeTheLengthDecisionAndInTheFileBody(): void
    {
        $tg = new RecordingTelegram();

        $tg->sendText(1, "| a | b |\n|---|---|\n| ".self::SECRET." | 2 |\n");

        self::assertSame('sendDocument', $tg->calls[0]['method']);
        self::assertSame("| a | b |\n|---|---|\n| [REDACTED] | 2 |\n", $tg->sentFileBodies[0]);
    }

    public function testSendPlainMasksBeforeTruncation(): void
    {
        $tg = new RecordingTelegram();

        $tg->sendPlain(1, str_repeat('x', 4090).self::SECRET);

        $sent = $tg->calls[0]['params']['text'];
        self::assertSame(4096, mb_strlen($sent));
        self::assertStringNotContainsString('snake', $sent);
        self::assertStringNotContainsString('Zq7X', $sent);
    }

    public function testCaptionIsMaskedBeforeTruncation(): void
    {
        $tg = new RecordingTelegram();
        $file = $this->dir.'/outbox/a.bin';
        file_put_contents($file, 'x');

        $tg->sendFile(1, $file, str_repeat('x', 995).self::SECRET, 'application/octet-stream');

        $caption = $tg->calls[0]['params']['caption'];
        self::assertSame(1000, mb_strlen($caption));
        self::assertStringNotContainsString('snake', $caption);
    }

    public function testCallMasksTextOfEditMessageTextBeforeTheTransport(): void
    {
        $tg = new TransportRecordingTelegram();

        $tg->editMessageText(1, 5, 'journal: '.self::SECRET);

        self::assertSame('editMessageText', $tg->requests[0]['method']);
        self::assertSame('journal: [REDACTED]', $tg->requests[0]['params']['text']);
    }

    public function testCallMasksCaptionBeforeTheTransport(): void
    {
        $tg = new TransportRecordingTelegram();

        $tg->call('sendDocument', ['chat_id' => 1, 'caption' => 'note '.self::SECRET]);

        self::assertSame('note [REDACTED]', $tg->requests[0]['params']['caption']);
    }

    public function testTextOutboxFileIsSentAsAMaskedCopyAndTheOriginalIsUntouched(): void
    {
        $tg = new FakeTelegram();
        $original = $this->dir.'/outbox/report.md';
        file_put_contents($original, 'token: '.self::SECRET);
        $this->delivery($tg)->flushOutbox(1, 0);

        self::assertSame('token: [REDACTED]', $tg->fileContents[0]['content']);
        self::assertSame('report.md', $tg->files[0]['name']);
        self::assertNotSame($original, $tg->fileContents[0]['path']);
        self::assertFileDoesNotExist($tg->fileContents[0]['path'], 'the temporary copy is cleaned up');
    }

    public function testOriginalIsNotChangedWhileTheCopyIsSent(): void
    {
        $tg = new FakeTelegram();
        $original = $this->dir.'/outbox/data.json';
        file_put_contents($original, '{"k":"'.self::SECRET.'"}');
        $seen = null;
        $tg->onSendFile = static function () use ($original, &$seen): void {
            $seen = file_get_contents($original);
        };

        $this->delivery($tg)->flushOutbox(1, 0);

        self::assertSame('{"k":"'.self::SECRET.'"}', $seen);
        self::assertSame('{"k":"[REDACTED]"}', $tg->fileContents[0]['content']);
    }

    public function testTextFilesAreMaskedByContentWhateverTheExtension(): void
    {
        $tg = new FakeTelegram();
        $files = [
            'id_rsa.pem' => 'key='.self::SECRET,
            'config.yml' => 'token: '.self::SECRET,
            'notes.txt' => self::SECRET,
            'noext' => self::SECRET."\xff",
        ];
        foreach ($files as $name => $content) {
            file_put_contents($this->dir.'/outbox/'.$name, $content);
        }
        file_put_contents($this->dir.'/outbox/blob.bin', "\x00\x01\x02\xff".self::SECRET);
        $this->delivery($tg)->flushOutbox(1, 0);

        $byName = [];
        foreach ($tg->files as $i => $file) {
            $byName[$file['name']] = $tg->fileContents[$i]['content'];
        }
        self::assertSame('key=[REDACTED]', $byName['id_rsa.pem']);
        self::assertSame('token: [REDACTED]', $byName['config.yml']);
        self::assertSame('[REDACTED]', $byName['notes.txt']);
        self::assertSame("[REDACTED]\xff", $byName['noext'], 'a stray non-UTF-8 byte does not hide the file');
        self::assertSame("\x00\x01\x02\xff".self::SECRET, $byName['blob.bin'], 'binary files are not scanned');
    }

    public function testCleanTextFileIsSentAsIs(): void
    {
        $tg = new FakeTelegram();
        $path = $this->dir.'/outbox/clean.md';
        file_put_contents($path, 'nothing here');
        $this->delivery($tg)->flushOutbox(1, 0);

        self::assertSame($path, $tg->fileContents[0]['path']);
    }

    private function delivery(FakeTelegram $tg): Delivery
    {
        return new Delivery($tg, new Config(
            botToken: 'test-token',
            allowedUserIds: [1],
            repoPath: sys_get_temp_dir(),
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->dir,
            outboxDir: $this->dir.'/outbox',
            inboxDir: $this->dir,
            pollTimeout: 30,
            gitTimeout: 120,
            botLocale: 'en',
        ), new Locale());
    }
}

/** TelegramClient with the network call replaced by a recording. */
final class RecordingTelegram extends TelegramClient
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<string> */
    public array $sentFileBodies = [];

    public function __construct()
    {
        parent::__construct('test-token', new Locale());
    }

    public function call(string $method, array $params = [], ?int $timeout = null): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        foreach ($params as $value) {
            if ($value instanceof \CURLFile) {
                $this->sentFileBodies[] = (string) file_get_contents($value->getFilename());
            }
        }

        return ['message_id' => 1];
    }
}

/** TelegramClient with only the HTTP transport replaced: the real call() runs. */
final class TransportRecordingTelegram extends TelegramClient
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $requests = [];

    public function __construct()
    {
        parent::__construct('test-token', new Locale());
    }

    protected function request(string $method, array $params, ?int $timeout): array
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return [];
    }
}
