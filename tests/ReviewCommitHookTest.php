<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ReviewCommitHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewCommitHook::class)]
final class ReviewCommitHookTest extends TestCase
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';
    private const string OTHER = 'fedcba9876543210fedcba9876543210fedcba98';
    private const string FORM = 'gh api repos/<owner>/<repo>/pulls/<N>/reviews -f commit_id=<SHA> -f event=<APPROVE|REQUEST_CHANGES> -f body=<review body>';

    /** @var array<string, string> */
    private const array RECORDED = ['gpslab/ws#7' => self::SHA];

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function deniedProvider(): iterable
    {
        yield 'gh pr review --approve' => ['gh pr review 7 -R gpslab/ws --approve', self::RECORDED];
        yield 'gh pr review --request-changes' => ['gh pr review 7 -R gpslab/ws --request-changes --body "x"', self::RECORDED];
        yield 'gh pr review chained' => ['cd x && gh pr review 7 --approve', []];
        yield 'no commit_id' => ['gh api repos/gpslab/ws/pulls/7/reviews -f event=APPROVE -f body=ok', self::RECORDED];
        yield 'no commit_id, multi-line' => ["gh api repos/gpslab/ws/pulls/7/reviews \\\n  -f event=REQUEST_CHANGES \\\n  -f body=ok", []];
        yield 'command substitution' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id=$(gh pr view 7 --json headRefOid -q .headRefOid) -f event=APPROVE -f body=ok', self::RECORDED];
        yield 'quoted command substitution' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id="$(git rev-parse HEAD)" -f event=APPROVE -f body=ok', []];
        yield 'shell variable' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id=$SHA -f event=APPROVE -f body=ok', []];
        yield 'quoted shell variable' => ['gh api repos/gpslab/ws/pulls/7/reviews -f "commit_id=${SHA}" -f event=APPROVE -f body=ok', self::RECORDED];
        yield 'short sha' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id=0123456 -f event=APPROVE -f body=ok', []];
        yield 'other literal sha' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id='.self::OTHER.' -f event=APPROVE -f body=ok', self::RECORDED];
        yield 'other literal sha, uppercase repo in path' => ['gh api repos/GPSLab/WS/pulls/7/reviews -f commit_id='.self::OTHER.' -f event=APPROVE -f body=ok', self::RECORDED];
        yield '--input' => ['gh api repos/gpslab/ws/pulls/7/reviews --input review.json', self::RECORDED];
        yield '--input from stdin' => ['gh api repos/gpslab/ws/pulls/7/reviews -X POST --input - <<< "{}"', []];
    }

    /**
     * @param array<string, string> $shas
     */
    #[DataProvider('deniedProvider')]
    public function testDenied(string $command, array $shas): void
    {
        $reason = ReviewCommitHook::check($command, $shas);

        self::assertNotNull($reason);
        self::assertStringContainsString(self::FORM, $reason);
        self::assertStringContainsString('commit_id', $reason);
    }

    public function testDenialNamesTheRecordedShaWhenKnown(): void
    {
        $reason = (string) ReviewCommitHook::check('gh api repos/gpslab/ws/pulls/7/reviews -f event=APPROVE', self::RECORDED);

        self::assertStringContainsString('commit_id='.self::SHA, $reason);
        self::assertStringNotContainsString('<SHA> is the literal', $reason);
        self::assertStringContainsString('<SHA> is the literal 40-hex', (string) ReviewCommitHook::check('gh pr review 7 --approve', []));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function allowedProvider(): iterable
    {
        yield 'recorded sha' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id='.self::SHA.' -f event=APPROVE -f body=ok', self::RECORDED];
        yield 'recorded sha, multi-line, quoted' => ["gh api repos/gpslab/ws/pulls/7/reviews \\\n  -f \"commit_id=".self::SHA."\" \\\n  -f event=REQUEST_CHANGES \\\n  -F body=@- <<'EOF'\nC1 — open\nEOF", self::RECORDED];
        yield 'recorded sha uppercase' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id='.strtoupper(self::SHA).' -f event=APPROVE', self::RECORDED];
        yield 'literal sha, none recorded' => ['gh api repos/gpslab/ws/pulls/7/reviews -f commit_id='.self::OTHER.' -f event=APPROVE -f body=ok', []];
        yield 'literal sha, recorded for another pr' => ['gh api repos/gpslab/ws/pulls/8/reviews -f commit_id='.self::OTHER.' -f event=APPROVE', self::RECORDED];
        yield 'reading reviews' => ['gh api repos/gpslab/ws/pulls/7/reviews', self::RECORDED];
        yield 'reading reviews, explicit GET' => ['gh api -X GET repos/gpslab/ws/pulls/7/reviews -f per_page=100', self::RECORDED];
        yield 'review comment' => ['gh pr review 7 --comment --body "x"', self::RECORDED];
        yield 'reading comments' => ['gh api repos/gpslab/ws/pulls/7/comments', self::RECORDED];
        yield 'pr diff is not publication' => ['gh pr diff 7', self::RECORDED];
        yield 'unrelated' => ['git diff main...'.self::SHA, self::RECORDED];
        yield 'approve in a quoted string' => ['git commit -m "gh pr review 7 --approve"', self::RECORDED];
    }

    /**
     * @param array<string, string> $shas
     */
    #[DataProvider('allowedProvider')]
    public function testAllowed(string $command, array $shas): void
    {
        self::assertNull(ReviewCommitHook::check($command, ReviewCommitHook::normalize($shas)));
    }

    public function testManualReviewOfTwoPrsChecksEachAgainstItsOwnSha(): void
    {
        $shas = ['gpslab/ws#7' => self::SHA, 'gpslab/other#9' => self::OTHER];
        $post = static fn (string $path, string $sha): string => "gh api repos/{$path}/reviews -f commit_id={$sha} -f event=APPROVE -f body=ok";

        self::assertNull(ReviewCommitHook::check($post('gpslab/ws/pulls/7', self::SHA), $shas));
        self::assertNull(ReviewCommitHook::check($post('gpslab/other/pulls/9', self::OTHER), $shas));
        self::assertNotNull(ReviewCommitHook::check($post('gpslab/ws/pulls/7', self::OTHER), $shas));
        self::assertNotNull(ReviewCommitHook::check($post('gpslab/other/pulls/9', self::SHA), $shas));
    }

    public function testPathWithoutRepoMatchesByNumberOnlyWhenUnambiguous(): void
    {
        $post = static fn (string $sha): string => "gh api pulls/7/reviews -f commit_id={$sha} -f event=APPROVE";

        self::assertNotNull(ReviewCommitHook::check($post(self::OTHER), ['gpslab/ws#7' => self::SHA]));
        self::assertNull(ReviewCommitHook::check($post(self::OTHER), ['gpslab/ws#7' => self::SHA, 'gpslab/b#7' => self::OTHER]));
    }

    public function testRecordedShasRoundTripAndAreValidated(): void
    {
        $dir = sys_get_temp_dir().'/rch-'.bin2hex(random_bytes(4));
        $path = ReviewCommitHook::writeShas($dir, 'run-1', ['GPSLab/WS#7' => strtoupper(self::SHA), 'bad#1' => 'nope']);

        self::assertSame(['gpslab/ws#7' => self::SHA], ReviewCommitHook::readShas($path));
        ReviewCommitHook::cleanup($dir, 'run-1');
        self::assertFileDoesNotExist($path);
        $this->expectException(\RuntimeException::class);
        ReviewCommitHook::readShas($path);
    }

    public function testSettingsAddTheHookNextToTheExistingPreToolUseHook(): void
    {
        $base = '{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command","command":"gate"}]}]}}';
        $settings = json_decode(ReviewCommitHook::addTo($base, '/srv/state'), true);

        self::assertCount(2, $settings['hooks']['PreToolUse']);
        self::assertSame('Bash', $settings['hooks']['PreToolUse'][1]['matcher']);
        self::assertStringContainsString('review-commit-hook.php', $settings['hooks']['PreToolUse'][1]['hooks'][0]['command']);
        self::assertStringContainsString('|| exit 2', $settings['hooks']['PreToolUse'][1]['hooks'][0]['command']);
    }

    public function testHookScriptDeniesAndFailsClosed(): void
    {
        $dir = sys_get_temp_dir().'/rch-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $run = static function (string $command, array $env) use ($dir): array {
            $proc = proc_open(
                [PHP_BINARY, dirname(__DIR__).'/bin/review-commit-hook.php', $dir],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                array_merge(getenv(), $env),
            );
            self::assertIsResource($proc);
            fwrite($pipes[0], (string) json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]));
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [$out, proc_close($proc)];
        };
        $post = static fn (string $sha): string => 'gh api repos/gpslab/ws/pulls/7/reviews -f commit_id='.$sha.' -f event=APPROVE -f body=ok';
        $file = ReviewCommitHook::writeShas($dir, 'r1', ['gpslab/ws#7' => self::SHA]);
        $env = [ReviewCommitHook::SHAS_ENV => $file];

        self::assertSame(['', 0], $run($post(self::SHA), $env));
        [$out, $code] = $run($post(self::OTHER), $env);
        self::assertSame(0, $code);
        self::assertSame('deny', json_decode($out, true)['hookSpecificOutput']['permissionDecision']);
        self::assertSame(['', 0], $run($post(self::OTHER), [ReviewCommitHook::SHAS_ENV => '']));
        self::assertSame(2, $run($post(self::SHA), [ReviewCommitHook::SHAS_ENV => $dir.'/missing.json'])[1]);

        exec('rm -rf '.escapeshellarg($dir));
    }
}
