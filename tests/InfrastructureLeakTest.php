<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards tracked files against leaking details of a particular deployment.
 *
 * Forbidden plain substrings are listed in clear. Forbidden tokens are stored only as SHA-256 hashes
 * of their lowercase form, so that this list does not publish the very values it keeps out. Matches
 * are reported by file and line; hashed tokens are never printed.
 */
final class InfrastructureLeakTest extends TestCase
{
    private const FORBIDDEN_SUBSTRINGS = [
        'NOPASSWD',
        '/var/www/',
    ];

    private const FORBIDDEN_TOKEN_HASHES = [
        '306e9108e1622a03fdc4f332da0a51f39247175a826a5c4b1bef65fe4e272a9a',
        'da9a02021efd887cf8ff0a04c689a52c12b365b45f3f8fc40a230873b67ab949',
        '253c581cf9cdef5cb2731ce55dbc714d3650056b663f9b3f60c5511f2f4e8436',
        '04fe5809e23712ce2c71547d89f4a9058bf7fdb6b42ab3fbe8d765f658845133',
    ];

    /** Tracked paths that are not scanned: exact paths and directory prefixes. */
    private const EXCLUDED_PATHS = ['LICENSE', 'composer.lock'];
    private const EXCLUDED_DIRS = ['vendor/'];

    private const TOKEN_PATTERN = '/[A-Za-z0-9._-]+/';

    public function testFindsForbiddenSubstringWithFileAndLine(): void
    {
        $problems = self::findProblems('docs/a.md', "ok\nrun sudo with NOPASSWD here\n", ['NOPASSWD'], []);

        self::assertSame(['docs/a.md:2: forbidden substring "NOPASSWD"'], $problems);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hashedTokenProvider(): iterable
    {
        yield 'plain' => ['the example-token value'];
        yield 'upper case' => ['the EXAMPLE-TOKEN value'];
        yield 'mixed case' => ['the Example-Token value'];
        yield 'sentence end' => ['deployed on example-token.'];
        yield 'extension' => ['clone example-token.git'];
        yield 'suffix' => ['example-token-prod'];
        yield 'prefix' => ['user-example-token'];
        yield 'host name' => ['https://example-token.example.com/x'];
        yield 'path part' => ['/srv/example-token/app'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hashedTokenProvider')]
    public function testFindsHashedToken(string $line): void
    {
        $problems = self::findProblems('a.txt', "first\n".$line, [], [hash('sha256', 'example-token')]);

        self::assertSame(['a.txt:2: forbidden token hash'], $problems);
    }

    public function testFindsHashedTokenParts(): void
    {
        $problems = self::findProblems('a.txt', 'user-example.git', [], [hash('sha256', 'example')]);

        self::assertSame(['a.txt:1: forbidden token hash'], $problems);
    }

    public function testReportDoesNotContainHashedValue(): void
    {
        $problems = self::findProblems('a.txt', 'x Example-Token y', [], [hash('sha256', 'example-token')]);

        self::assertCount(1, $problems);
        self::assertStringNotContainsStringIgnoringCase('example-token', $problems[0]);
    }

    public function testIgnoresCleanContent(): void
    {
        self::assertSame([], self::findProblems('a.txt', "nothing\nspecial here", ['NOPASSWD'], [hash('sha256', 'example-token')]));
    }

    public function testSkipsBinaryContent(): void
    {
        self::assertSame([], self::findProblems('a.bin', "NOPASSWD\0example-token", ['NOPASSWD'], [hash('sha256', 'example-token')]));
    }

    public function testExcludedPathsAreSkipped(): void
    {
        self::assertTrue($this->isExcluded('LICENSE'));
        self::assertTrue($this->isExcluded('composer.lock'));
        self::assertTrue($this->isExcluded('vendor/pkg/file.php'));
        self::assertFalse($this->isExcluded('src/Bot.php'));
        self::assertFalse($this->isExcluded('.github/workflows/ci.yml'));
    }

    public function testTrackedFilesDoNotLeakInfrastructure(): void
    {
        $root = dirname(__DIR__);
        $self = realpath(__FILE__);
        $problems = [];

        foreach ($this->trackedFiles($root) as $path) {
            if ($this->isExcluded($path)) {
                continue;
            }
            $full = $root.'/'.$path;
            if (realpath($full) === $self || !is_file($full)) {
                continue;
            }
            $content = @file_get_contents($full);
            if ($content === false || str_contains($content, "\0")) {
                continue;
            }

            array_push($problems, ...self::findProblems($path, $content, self::FORBIDDEN_SUBSTRINGS, self::FORBIDDEN_TOKEN_HASHES));
        }

        self::assertSame([], $problems, "Infrastructure details found in tracked files:\n".implode("\n", $problems));
    }

    /**
     * Scans file content and returns a report line per problem; hashed tokens are never printed.
     *
     * A token is checked whole and also by every run of its parts split on "." and "-", so a forbidden
     * value is found next to punctuation, in a suffix, a prefix or a host name.
     *
     * @param list<string> $substrings
     * @param list<string> $hashes
     *
     * @return list<string>
     */
    public static function findProblems(string $path, string $content, array $substrings, array $hashes): array
    {
        if (str_contains($content, "\0")) {
            return [];
        }

        $problems = [];
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            $number = $index + 1;
            foreach ($substrings as $substring) {
                if (str_contains($line, $substring)) {
                    $problems[] = sprintf('%s:%d: forbidden substring "%s"', $path, $number, $substring);
                }
            }

            $found = false;
            if (preg_match_all(self::TOKEN_PATTERN, $line, $matches) !== false) {
                foreach ($matches[0] as $token) {
                    foreach (self::candidates($token) as $candidate) {
                        if (in_array(hash('sha256', strtolower($candidate)), $hashes, true)) {
                            $found = true;
                        }
                    }
                }
            }
            if ($found) {
                $problems[] = sprintf('%s:%d: forbidden token hash', $path, $number);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $token): array
    {
        $parts = preg_split('/[.-]+/', $token, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $candidates = [$token];
        foreach ($parts as $i => [$first, $start]) {
            for ($j = $i; $j < count($parts); ++$j) {
                [$last, $offset] = $parts[$j];
                $candidates[] = substr($token, $start, $offset + strlen($last) - $start);
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function trackedFiles(string $root): array
    {
        $process = proc_open(
            ['git', 'ls-files', '-z'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'Cannot start git');

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, 'git ls-files failed: '.trim((string) $error));
        self::assertNotEmpty($output, 'git ls-files returned no files');

        return array_values(array_filter(explode("\0", (string) $output), static fn (string $p): bool => $p !== ''));
    }

    private function isExcluded(string $path): bool
    {
        if (in_array($path, self::EXCLUDED_PATHS, true)) {
            return true;
        }
        foreach (self::EXCLUDED_DIRS as $dir) {
            if (str_starts_with($path, $dir)) {
                return true;
            }
        }

        return false;
    }
}
