<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\SecretFinding;
use GpsLab\WorkspaceAssistant\SecretScanner;
use GpsLab\WorkspaceAssistant\SecretSources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretScanner::class)]
#[CoversClass(SecretSources::class)]
#[CoversClass(SecretFinding::class)]
final class SecretScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/secret-scanner-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
        SecretScanner::setShared(null);
    }

    /** Fake values are built by concatenation: no literal token lands in the repository. */
    private static function fake(string $prefix, int $length, string $alphabet = 'aB3dE5gH7jK9'): string
    {
        return $prefix.substr(str_repeat($alphabet, (int) ceil($length / strlen($alphabet))), 0, $length);
    }

    private const string VALUE = 'Zq7Xm2Vb9Lk4Pw8';

    /**
     * @param array<string, string> $environment
     * @param list<string>          $files
     */
    private function scanner(string $env = '', array $environment = [], array $files = []): SecretScanner
    {
        $envFile = $this->dir.'/.env';
        file_put_contents($envFile, $env);

        return new SecretScanner(new SecretSources($envFile, $files, $environment));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        $v = 'p@ss/w+rd=Zq7Xm2 "x"';

        return [
            'raw' => [$v],
            'urlencode upper hex' => [rawurlencode($v)],
            'urlencode lower hex' => [(string) preg_replace_callback('/%[0-9A-F]{2}/', static fn (array $m): string => strtolower($m[0]), rawurlencode($v))],
            'urlencode plus' => [urlencode($v)],
            'json' => [substr((string) json_encode($v), 1, -1)],
            'json unescaped slashes' => [substr((string) json_encode($v, JSON_UNESCAPED_SLASHES), 1, -1)],
            'base64' => [base64_encode($v)],
            'base64url' => [rtrim(strtr(base64_encode($v), '+/', '-_'), '=')],
            'html' => [htmlspecialchars($v, ENT_QUOTES | ENT_HTML5)],
        ];
    }

    #[DataProvider('forms')]
    public function testKnownValueIsMaskedInEveryForm(string $form): void
    {
        $scanner = $this->scanner('MY_SECRET=p@ss/w+rd=Zq7Xm2 "x"');

        self::assertSame('a [REDACTED] b', $scanner->mask('a '.$form.' b'));
    }

    public function testUrlencodeHexIsCaseInsensitive(): void
    {
        $scanner = $this->scanner('API_TOKEN='.self::VALUE.'/+=');

        foreach ([rawurlencode(self::VALUE.'/+='), str_replace(['%2F', '%2B', '%3D'], ['%2f', '%2b', '%3d'], rawurlencode(self::VALUE.'/+='))] as $form) {
            self::assertSame('x=[REDACTED]', $scanner->mask('x='.$form));
        }
    }

    public function testMultiLineValueIsMaskedPerLine(): void
    {
        $scanner = $this->scanner('MY_SECRET="first-line-secret-1'."\n".'second-line-secret-2"');

        self::assertSame('only [REDACTED] shown', $scanner->mask('only second-line-secret-2 shown'));
        self::assertSame('[REDACTED]', $scanner->mask("first-line-secret-1\nsecond-line-secret-2"));
    }

    public function testSourcesAndTheirFilters(): void
    {
        $file = $this->dir.'/secrets.env';
        file_put_contents($file, "# comment\nexport arbitrary_name=file-value-123456\nSHORT=tiny\n");
        $scanner = $this->scanner(
            "BOT_TOKEN=bot-token-value-1\nCLAUDE_EXTRA_ARGS=--dangerously-skip-permissions\nCLAUDE_BIN=/usr/local/bin/claude\nGITHUB_REPO=gpslab/workspace-assistant\nDEPLOY_KEY=env-file-deploy-key\nSIGNING_PAT=env-file-pat-value\nPLAIN_NAME=not-a-secret-value\n",
            ['OTHER_PASSWORD' => 'process-env-password', 'HOME' => '/home/someone-else-here'],
            [$file],
        );

        $text = 'bot-token-value-1 env-file-deploy-key env-file-pat-value process-env-password file-value-123456';
        self::assertSame('[REDACTED] [REDACTED] [REDACTED] [REDACTED] [REDACTED]', $scanner->mask($text));

        foreach (['--dangerously-skip-permissions', '/usr/local/bin/claude', 'gpslab/workspace-assistant', 'not-a-secret-value', '/home/someone-else-here', 'tiny'] as $plain) {
            self::assertSame($plain, $scanner->mask($plain));
        }
    }

    public function testTildeIsExpandedInSecretFiles(): void
    {
        file_put_contents($this->dir.'/home.env', 'ANY=tilde-expanded-value');
        $scanner = $this->scanner('', ['HOME' => $this->dir], ['~/home.env']);

        self::assertSame('[REDACTED]', $scanner->mask('tilde-expanded-value'));
    }

    public function testThresholds(): void
    {
        $scanner = $this->scanner("A_TOKEN=12345678901\nB_TOKEN=aaaaaaaaaaaaaaaa\nC_TOKEN=123456789012\n");

        self::assertSame('12345678901', $scanner->mask('12345678901'), 'shorter than 12');
        self::assertSame('aaaaaaaaaaaaaaaa', $scanner->mask('aaaaaaaaaaaaaaaa'), 'one repeated character');
        self::assertSame('[REDACTED]', $scanner->mask('123456789012'), 'exactly 12 characters');
    }

    public function testMissingSecretFileIsALogLineNotAFailure(): void
    {
        $scanner = $this->scanner('MY_SECRET=still-masked-value', [], [$this->dir.'/missing.env']);

        self::assertSame('[REDACTED]', $this->quietly(static fn (): string => $scanner->mask('still-masked-value')));
    }

    public function testSetIsRebuiltWhenASourceFileChanges(): void
    {
        $file = $this->dir.'/secrets.env';
        file_put_contents($file, 'ONE=first-secret-value');
        $scanner = $this->scanner('', [], [$file]);
        self::assertSame('[REDACTED] second-secret-value', $scanner->mask('first-secret-value second-secret-value'));

        file_put_contents($file, "ONE=first-secret-value\nTWO=second-secret-value");
        touch($file, time() + 5);

        self::assertSame('[REDACTED] [REDACTED]', $scanner->mask('first-secret-value second-secret-value'));
    }

    public function testKnownValueEndingAfterAnOverlappingPatternLeavesNoTail(): void
    {
        $scanner = $this->scanner('MY_TOKEN=Zq7Xm2Vb9Lk4.Pw8Rt5Yu');

        // The openai pattern ends inside the known value, which ends after it.
        $text = 'x sk-'.str_repeat('A', 20).'Zq7Xm2Vb9Lk4.Pw8Rt5YuEND y';

        self::assertSame('x [REDACTED]END y', $scanner->mask($text));
    }

    public function testFindingHasTypeAndLocationOnly(): void
    {
        $scanner = $this->scanner('GH_PASSWORD='.self::VALUE);
        $result = $scanner->scan("line one\nkey: ".self::VALUE);

        self::assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        self::assertSame('known:GH_PASSWORD', $finding->type);
        self::assertSame(14, $finding->offset);
        self::assertSame(2, $finding->line);
        self::assertStringNotContainsString(self::VALUE, serialize($finding));
        self::assertStringNotContainsString(self::VALUE, $result['text']);
    }

    public function testLogHasTheTypeButNeverTheValue(): void
    {
        $scanner = $this->scanner('GH_PASSWORD='.self::VALUE);
        $token = self::fake('ghp_', 36);

        $log = $this->captureLog(static fn (): string => $scanner->mask(self::VALUE.' '.$token));

        self::assertStringContainsString('known:GH_PASSWORD', $log);
        self::assertStringContainsString('github_token', $log);
        self::assertStringNotContainsString(self::VALUE, $log);
        self::assertStringNotContainsString(substr($token, 4, 12), $log);
    }

    public function testMissingFileLogNeverHasValues(): void
    {
        $scanner = $this->scanner('MY_SECRET='.self::VALUE, [], [$this->dir.'/missing.env']);

        $log = $this->captureLog(static fn (): string => $scanner->mask('x'));

        self::assertStringContainsString('missing.env', $log);
        self::assertStringNotContainsString(self::VALUE, $log);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'github ghp' => [self::fake('ghp_', 36)],
            'github ghs' => [self::fake('ghs_', 40)],
            'github pat' => [self::fake('github_pat_', 60, 'aB3dE5gH7jK9_')],
            'gitlab' => [self::fake('glpat-', 20, 'aB3dE5gH7jK9-_')],
            'anthropic' => [self::fake('sk-ant-', 40, 'aB3dE5gH7jK9-_')],
            'openai' => [self::fake('sk-', 48)],
            'slack' => [self::fake('xoxb-', 30, '1234567890-')],
            'aws' => [self::fake('AKIA', 16, 'ABCDEFGHIJKLMNOP')],
            'google' => [self::fake('AIza', 35, 'aB3dE5gH7jK9_-')],
            'telegram' => ['123456789:'.self::fake('', 35, 'aB3dE5gH7jK9_-')],
            'jwt' => [self::fake('eyJ', 20).'.'.self::fake('eyJ', 30).'.'.self::fake('', 43)],
            'private key' => [sprintf('-----BEGIN %1$s-----', 'RSA PRIVATE KEY')."\nMIIEabc\ndef\n".sprintf('-----END %1$s-----', 'RSA PRIVATE KEY')],
            'private key unterminated' => [sprintf('-----BEGIN %1$s-----', 'PRIVATE KEY')."\nMIIEabc"],
        ];
    }

    #[DataProvider('shapes')]
    public function testShapePatternIsMasked(string $secret): void
    {
        $scanner = new SecretScanner();

        // An unterminated private key is masked to the end of the text, so the suffix is checked only for the others.
        $expected = str_contains($secret, 'END ') || !str_contains($secret, 'PRIVATE KEY') ? 'a [REDACTED] b' : 'a [REDACTED]';
        self::assertSame($expected, $scanner->mask('a '.$secret.' b'), 'masks the whole token, no tail');
    }

    public function testOpenAiPatternHasALookbehind(): void
    {
        $text = 'task-'.str_repeat('abcdef', 5);

        self::assertSame($text, (new SecretScanner())->mask($text));
    }

    public function testUrlPasswordIsMaskedAndTheRestOfTheUrlStays(): void
    {
        $scanner = new SecretScanner();

        self::assertSame(sprintf('git clone https://user:%s@example.com/r.git', '[REDACTED]'), $scanner->mask(sprintf('git clone https://user:%s@example.com/r.git', 'hunter2-pass')));
        self::assertSame('https://example.com/a:b', $scanner->mask('https://example.com/a:b'));
    }

    public function testGenericPasswordAssignmentIsNotMasked(): void
    {
        $text = 'password=hunter2hunter2 PASSWD: "some-long-password-value" api_key=abcdef1234567890';

        self::assertSame($text, $this->scanner()->mask($text));
    }

    public function testSecretAtATruncationBoundaryLeavesNoTail(): void
    {
        $scanner = $this->scanner('MY_SECRET='.self::VALUE);
        $text = str_repeat('x', 4090).self::VALUE;

        $masked = mb_substr($scanner->mask($text), 0, 4096);

        self::assertStringNotContainsString(substr(self::VALUE, 0, 4), $masked);
    }

    public function testSecretInsideMarkdownIsMasked(): void
    {
        $scanner = $this->scanner('MY_SECRET=snake_case_secret_value');

        self::assertSame('**key**: `[REDACTED]` and *[REDACTED]*', $scanner->mask('**key**: `snake_case_secret_value` and *snake_case_secret_value*'));
    }

    public function testHugeSourceFilesDoNotBreakThePatternSize(): void
    {
        $lines = [];
        for ($i = 0; $i < 600; ++$i) {
            $lines[] = 'K'.$i.'='.self::fake('value-'.$i.'-', 40);
        }
        $file = $this->dir.'/many.env';
        file_put_contents($file, implode("\n", $lines));
        $scanner = $this->scanner('', [], [$file]);

        self::assertSame('[REDACTED] [REDACTED]', $scanner->mask(self::fake('value-0-', 40).' '.self::fake('value-599-', 40)));
    }

    public function testSharedScannerDefaultsToPatternsOnly(): void
    {
        $token = self::fake('ghp_', 36);

        self::assertSame('[REDACTED]', SecretScanner::shared()->mask($token));
    }

    /**
     * Log::line writes to the STDERR constant, so a write filter is put on the stream around the call.
     *
     * @param \Closure(): string $run
     */
    private function captureLog(\Closure $run): string
    {
        return $this->capture($run)[1];
    }

    /**
     * @param \Closure(): string $run
     */
    private function quietly(\Closure $run): string
    {
        return $this->capture($run)[0];
    }

    /**
     * @param \Closure(): string $run
     *
     * @return array{string, string} result of the closure, what it logged
     */
    private function capture(\Closure $run): array
    {
        if (!in_array('secret_scanner_stderr', stream_get_filters(), true)) {
            stream_filter_register('secret_scanner_stderr', StderrCaptureFilter::class);
        }
        StderrCaptureFilter::$buffer = '';
        $filter = stream_filter_append(STDERR, 'secret_scanner_stderr', STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);

        try {
            $result = $run();
        } finally {
            stream_filter_remove($filter);
        }

        return [$result, StderrCaptureFilter::$buffer];
    }
}

/** Collects what is written to a stream instead of passing it on. */
final class StderrCaptureFilter extends \php_user_filter
{
    public static string $buffer = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
