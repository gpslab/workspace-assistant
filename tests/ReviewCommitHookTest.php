<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\PmGateHook;
use GpsLab\WorkspaceAssistant\ReviewCommitHook;
use GpsLab\WorkspaceAssistant\ReviewStatusHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewCommitHook::class)]
final class ReviewCommitHookTest extends TestCase
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';
    private const string UPPER = '0123456789ABCDEF0123456789ABCDEF01234567';
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
        yield 'owner/repo placeholders, other sha' => ['gh api repos/{owner}/{repo}/pulls/7/reviews -f commit_id='.self::OTHER.' -f event=APPROVE -f body=ok', self::RECORDED];
        yield ':owner/:repo placeholders, other sha' => ['gh api repos/:owner/:repo/pulls/7/reviews -f commit_id='.self::OTHER.' -f event=APPROVE -f body=ok', self::RECORDED];
        yield '--input' => ['gh api repos/gpslab/ws/pulls/7/reviews --input review.json', self::RECORDED];
        yield '--input from stdin' => ['gh api repos/gpslab/ws/pulls/7/reviews -X POST --input - <<< "{}"', []];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gitlabDeniedProvider(): iterable
    {
        yield 'approve without --sha' => ['glab mr approve 12'];
        yield 'approve with a short sha' => ['glab mr approve 12 --sha 0123abc'];
        yield 'approve with a variable sha' => ['glab mr approve 12 --sha $SHA'];
        yield 'approve chained' => ['cd x && glab mr approve 12'];
        yield 'approve with an uppercase sha' => ['glab mr approve 12 --sha '.self::UPPER];
        yield 'approve -s with an uppercase sha' => ['glab mr approve 12 -s '.self::UPPER];
        yield 'approve literal then a variable sha' => ['glab mr approve 12 --sha '.self::SHA.' --sha $SHA'];
        yield 'approve -s literal then command substitution' => ['glab mr approve 12 -s '.self::SHA.' -s $(git rev-parse HEAD)'];
        yield 'note marker in -m"…" without a space' => ['glab mr note 12 -m"ok <!-- verdict:approve sha='.self::SHA.' -->"'];
        yield 'note marker in ANSI-C quoting' => ['glab mr note 12 -m $\'ok <!-- verdict:approve sha='.self::SHA.' -->\''];
        yield 'approve literal then attached variable' => ['glab mr approve 12 --sha '.self::SHA.' -s$SHA'];
        yield 'approve literal then attached quoted variable' => ['glab mr approve 12 --sha '.self::SHA.' -s"$SHA"'];
        yield 'approve literal then attached substitution' => ['glab mr approve 12 --sha '.self::SHA.' -s$(git rev-parse HEAD)'];
        yield 'approve attached variable' => ['glab mr approve 12 -s$SHA'];
        yield 'approve -s with a variable sha' => ['glab mr approve 12 -s $SHA'];
        yield 'note with a command substitution sha' => ['glab mr note 12 -m "ok <!-- verdict:approve sha=$(git rev-parse HEAD) -->"'];
        yield 'note with a short sha' => ['glab mr note 12 -m "ok <!-- verdict:changes sha=0123abc -->"'];
    }

    #[DataProvider('gitlabDeniedProvider')]
    public function testGitlabDenied(string $command): void
    {
        self::assertNotNull(ReviewCommitHook::check($command));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gitlabAllowedProvider(): iterable
    {
        yield 'approve with a literal sha' => ['glab mr approve 12 --sha '.self::SHA];
        yield 'approve with -s' => ['glab mr approve 12 -s '.self::SHA];
        yield 'approve with attached -s' => ['glab mr approve 12 -s'.self::SHA];
        yield 'approve with -s=' => ['glab mr approve 12 -s='.self::SHA];
        yield 'approve with --sha=' => ['glab mr approve 12 --sha='.self::SHA];
        yield 'note with a literal sha' => ['glab mr note 12 -m "ok <!-- verdict:approve sha='.self::SHA.' -->"'];
        yield 'note without a marker' => ['glab mr note 12 -m "hello"'];
        yield 'revoke' => ['glab mr revoke 12'];
    }

    #[DataProvider('gitlabAllowedProvider')]
    public function testGitlabAllowed(string $command): void
    {
        self::assertNull(ReviewCommitHook::check($command));
    }

    /**
     * @return iterable<string, array{string, bool, bool}> command, whether the commit hook denies it, whether
     *                                                     the Stop hook counts it when it is not denied
     */
    public static function markerProvider(): iterable
    {
        $sha = self::SHA;
        $m = "<!-- verdict:approve sha={$sha} -->";
        $one = static fn (string $marker): string => 'glab mr note 12 -m "C1 — fixed '.$marker.'"';
        yield 'approve' => [$one($m), false, true];
        yield 'changes' => [$one("<!-- verdict:changes sha={$sha} -->"), false, true];
        yield 'unknown state' => [$one("<!-- verdict:approved sha={$sha} -->"), true, false];
        yield 'uppercase state' => [$one("<!-- verdict:Approve sha={$sha} -->"), true, false];
        yield 'uppercase sha' => [$one('<!-- verdict:approve sha='.self::UPPER.' -->'), true, false];
        yield 'command substitution' => [$one('<!-- verdict:approve sha=$(git rev-parse HEAD) -->'), true, false];
        yield 'variable' => [$one('<!-- verdict:approve sha=$SHA -->'), true, false];
        yield 'short sha' => [$one('<!-- verdict:changes sha=0123abc -->'), true, false];
        yield 'two markers' => [$one("<!-- verdict:approve sha={$sha} --> <!-- verdict:changes sha={$sha} -->"), true, false];
        yield 'no sha' => [$one('<!-- verdict:approve -->'), true, false];
        yield 'trailing junk' => [$one("<!-- verdict:approve sha={$sha} extra -->"), true, false];
        yield 'two -m, marker first' => ["glab mr note 12 -m \"{$m}\" -m \"LGTM\"", true, true];
        yield 'two -m, marker last' => ["glab mr note 12 -m \"LGTM\" -m \"{$m}\"", true, true];
        yield 'second -m attached' => ["glab mr note 12 -m \"{$m}\" -m\"LGTM\"", true, true];
        yield 'second -m bare word' => ["glab mr note 12 -m \"{$m}\" -m LGTM", true, true];
        yield 'second --message variable' => ["glab mr note 12 -m \"{$m}\" --message \$B", true, true];
        yield '-m in heredoc text' => ["glab mr note 12 -m \"\$(cat <<'EOF'\nC1 — fixed, use -m 'x'\n{$m}\nEOF\n)\"", false, true];
        yield 'marker in a positional heredoc' => ["glab mr note 12 -m \"x\" \"\$(cat <<'EOF'\n{$m}\nEOF\n)\"", true, false];
    }

    /** Any note with a `verdict:` marker is either denied by the commit hook or counted by the Stop hook. */
    #[DataProvider('markerProvider')]
    public function testMarkerPairProperty(string $command, bool $denied, bool $counted): void
    {
        $isDenied = ReviewCommitHook::check($command) !== null;

        self::assertSame($denied, $isDenied);
        self::assertTrue($isDenied || self::stopCounts($command));
        if (!$isDenied) {
            self::assertSame($counted, self::stopCounts($command));
        }
    }

    public function testNoteWithoutMarkerNeverDeniedByFlagRule(): void
    {
        self::assertNull(ReviewCommitHook::check('glab mr note 12 -m "a" -m "b"'));
        self::assertNull(ReviewCommitHook::check("glab mr note 12 -m \"x\" \"\$(cat <<'EOF'\nhi\nEOF\n)\""));
    }

    public function testTwoBodyFlagsDenialText(): void
    {
        $m = '<!-- verdict:approve sha='.self::SHA.' -->';
        $text = (string) ReviewCommitHook::check("glab mr note 12 -m \"{$m}\" -m LGTM");

        self::assertStringContainsString('only the last `-m`', $text);
        self::assertStringContainsString('-m "$(cat <<\'EOF\' … EOF)"', $text);
    }

    /**
     * @return iterable<string, array{string, bool}> command, whether it is denied
     */
    public static function approveEndpointProvider(): iterable
    {
        $base = 'glab api -X POST projects/1/merge_requests/';
        yield 'literal N without sha' => [$base.'5/approve', true];
        yield '$N without sha' => [$base.'$N/approve', true];
        yield '${N} without sha' => [$base.'${N}/approve', true];
        yield '$N with sha' => [$base.'$N/approve -f sha='.self::SHA, false];
        yield '${N} with sha' => [$base.'${N}/approve -f sha='.self::SHA, false];
        yield '$N with a variable sha' => [$base.'$N/approve -f sha=$SHA', true];
    }

    #[DataProvider('approveEndpointProvider')]
    public function testApproveEndpointVariableNumber(string $command, bool $denied): void
    {
        self::assertSame($denied, ReviewCommitHook::check($command) !== null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotingProvider(): iterable
    {
        yield 'double quotes' => ['glab mr note 12 -m "ok %s"'];
        yield 'single quotes' => ["glab mr note 12 -m 'ok %s'"];
        yield '--message=' => ['glab mr note 12 --message="ok %s"'];
        yield 'heredoc' => ["glab mr note 12 -m \"\$(cat <<'EOF'\nok %s\nEOF\n)\""];
    }

    #[DataProvider('quotingProvider')]
    public function testMarkerPairPropertyAcrossQuoting(string $template): void
    {
        $marker = '<!-- verdict:approve sha='.self::SHA.' -->';

        $command = sprintf($template, $marker);

        self::assertNull(ReviewCommitHook::check($command));
        self::assertTrue(self::stopCounts($command));
    }

    /** Forms the Stop hook never reads a body from are denied, not published uncounted. */
    public function testMarkerOutsideReadBodyDenied(): void
    {
        $marker = '<!-- verdict:approve sha='.self::SHA.' -->';

        self::assertStringContainsString('outside a body', (string) ReviewCommitHook::check('glab mr note 12 -m"ok '.$marker.'"'));
        self::assertStringContainsString('outside a body', (string) ReviewCommitHook::check("glab mr note 12 -m \$'ok ".$marker."'"));
    }

    /** True when the Stop hook counts the note as a verdict. */
    private static function stopCounts(string $command): bool
    {
        $marker = $command;
        $lines = [
            (string) json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => 'review !12']]),
            (string) json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => $command]]]]]),
            (string) json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'ok', 'is_error' => false]]]]),
        ];
        $stop = ReviewStatusHook::decide(['last_assistant_message' => str_contains($marker, 'verdict:changes') ? '🔴 Blocked' : '🟢 Merge', 'stop_hook_active' => false], $lines);

        return $stop === ReviewStatusHook::PASS;
    }

    public function testMarkerDenialTexts(): void
    {
        $note = static fn (string $marker): string => (string) ReviewCommitHook::check('glab mr note 12 -m "x '.$marker.'"');

        self::assertStringContainsString('lowercase 40-hex', $note('<!-- verdict:approve sha='.self::UPPER.' -->'));
        self::assertStringContainsString('literal lowercase 40-hex', $note('<!-- verdict:approve sha=$(git rev-parse HEAD) -->'));
        self::assertStringContainsString('verdict:approve sha=<SHA>', $note('<!-- verdict:approved sha='.self::SHA.' -->'));
        self::assertStringContainsString('not recognised', $note('<!-- verdict:Approve sha='.self::SHA.' -->'));
        self::assertStringContainsString('2 `verdict:` markers', $note('<!-- verdict:approve sha='.self::SHA.' --> <!-- verdict:approve sha='.self::SHA.' -->'));
        self::assertStringContainsString('malformed', $note('<!-- verdict:approve -->'));
    }

    public function testApproveUppercaseDenialSaysLowercase(): void
    {
        self::assertStringContainsString('lowercase', (string) ReviewCommitHook::check('glab mr approve 12 -s '.self::UPPER));
    }

    /**
     * @return iterable<string, array{string}> the part of the command from `glab api` up to the endpoint
     */
    public static function approveIdProvider(): iterable
    {
        yield 'numeric' => ['projects/12/merge_requests/5/approve'];
        yield 'encoded path' => ['projects/group%2Fsub%2Fproj/merge_requests/5/approve'];
        yield ':id' => ['projects/:id/merge_requests/5/approve'];
        yield ':fullpath' => ['"projects/:fullpath/merge_requests/5/approve"'];
        yield 'leading slash' => ['/projects/12/merge_requests/5/approve'];
        yield 'v4 prefix' => ['api/v4/projects/12/merge_requests/5/approve'];
        yield 'leading slash v4' => ['/api/v4/projects/:id/merge_requests/5/approve'];
        yield 'full url' => ['https://gitlab.example.com/api/v4/projects/g%2Fp/merge_requests/5/approve'];
    }

    #[DataProvider('approveIdProvider')]
    public function testGlabApiApprovePinned(string $endpoint): void
    {
        $api = 'glab api '.$endpoint;
        $sha = self::SHA;
        $bare = trim($endpoint, '"');

        self::assertNotNull(ReviewCommitHook::check("{$api} -X POST"));
        self::assertNotNull(ReviewCommitHook::check("{$api} --method POST"));
        self::assertNotNull(ReviewCommitHook::check("{$api} -f other=1"));
        self::assertStringContainsString('without `sha`', (string) ReviewCommitHook::check("{$api} -X POST"));
        self::assertNull(ReviewCommitHook::check("{$api} -X POST -f sha={$sha}"));
        self::assertNull(ReviewCommitHook::check("{$api} -F sha={$sha}"));
        self::assertNull(ReviewCommitHook::check("{$api} --field sha={$sha}"));
        self::assertNull(ReviewCommitHook::check("{$api} --raw-field \"sha={$sha}\""));
        self::assertNull(ReviewCommitHook::check("glab api -X POST {$bare}?sha={$sha}"));
        self::assertNull(ReviewCommitHook::check("glab api -X POST \"{$bare}?sha={$sha}\""));
        self::assertNotNull(ReviewCommitHook::check("{$api} -X POST -f sha=\$(git rev-parse HEAD)"));
        self::assertNotNull(ReviewCommitHook::check("{$api} -X POST -f sha=\$SHA"));
        self::assertNotNull(ReviewCommitHook::check("{$api} -X POST -f sha=0123abc"));
        self::assertNotNull(ReviewCommitHook::check("glab api -X POST {$bare}?sha=\$SHA"));
        self::assertStringContainsString('lowercase', (string) ReviewCommitHook::check("{$api} -X POST -f sha=".self::UPPER));
        self::assertStringContainsString('lowercase', (string) ReviewCommitHook::check("glab api -X POST {$bare}?sha=".self::UPPER));
        self::assertNotNull(ReviewCommitHook::check("{$api} -X POST --input body.json"));
        self::assertNotNull(ReviewCommitHook::check("{$api} --input body.json -f sha={$sha}"));
        self::assertNull(ReviewCommitHook::check($api));
        self::assertNull(ReviewCommitHook::check("{$api} -X GET -f per_page=5"));
        self::assertNull(ReviewCommitHook::check('glab api '.str_replace('/approve', '/unapprove', $endpoint).' -X POST'));
    }

    public function testGlabApiApprovePinnedWhateverTheForge(): void
    {
        self::assertNotNull(ReviewCommitHook::check('cd x && glab api projects/1/merge_requests/5/approve -X POST'));
    }

    public function testGlabApiIdGrammarIsSharedWithTheIssueGate(): void
    {
        $ref = new \ReflectionClass(ReviewCommitHook::class);
        self::assertStringContainsString(PmGateHook::GLAB_PROJECT_PATH, (string) $ref->getConstant('GLAB_APPROVE_ENDPOINT_RE'));
        $gate = new \ReflectionClass(PmGateHook::class);
        self::assertStringContainsString(PmGateHook::GLAB_PROJECT_PATH, (string) $gate->getConstant('GLAB_ISSUES_ENDPOINT_RE'));
    }

    public function testGithubBehaviourUnchanged(): void
    {
        self::assertNull(ReviewCommitHook::check('gh api repos/o/r/pulls/7/reviews -f commit_id='.self::UPPER.' -f event=APPROVE'));
        self::assertNull(ReviewCommitHook::check('gh api repos/o/r/pulls/7/reviews -f commit_id='.self::SHA.' -f event=APPROVE'));
        self::assertNotNull(ReviewCommitHook::check('gh api repos/o/r/pulls/7/reviews -f event=APPROVE'));
        self::assertNull(ReviewCommitHook::check('gh api repos/o/r/pulls/7/reviews -f commit_id='.self::SHA.' -f event=APPROVE -f body="<!-- verdict:Approve sha=x -->"'));
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
