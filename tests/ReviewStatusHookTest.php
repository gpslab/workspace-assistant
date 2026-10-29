<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ReviewStatusHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewStatusHook::class)]
final class ReviewStatusHookTest extends TestCase
{
    private static function user(string $text): string
    {
        return (string) json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $text]]);
    }

    private static function bash(string $id, string $command): string
    {
        return (string) json_encode(['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => ['command' => $command]],
        ]]]);
    }

    private static function toolResult(string $id, bool $isError = false): string
    {
        return (string) json_encode(['type' => 'user', 'message' => ['content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok', 'is_error' => $isError],
        ]]]);
    }

    /**
     * @param list<string> $lines
     */
    private static function decide(array $lines, string $reply = '🟢 Merge — desktop#1005', bool $active = false): string
    {
        return ReviewStatusHook::decide(['last_assistant_message' => $reply, 'stop_hook_active' => $active], $lines);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function verdictProvider(): iterable
    {
        yield 'plain green' => ['🟢 Merge — desktop#1004', true];
        // Deliberately Cyrillic: the verdict is decided by the emoji, not by the language of the reply
        yield 'bold red' => ['**🔴 Нельзя** — 2 замечания', true];
        yield 'prefixed' => ["PR #1004: 🟢 can merge\ndetails", true];
        yield 'second line' => ["Done.\n\n🔴 Blocked — 1 finding", true];
        yield 'emoji deep in text' => ["Service status\nall fine\none more line\n🟢 green", false];
        yield 'emoji far in line' => ['I checked all the services and here is what came out: 🟢', false];
        yield 'no emoji' => ['Plain reply without a verdict', false];
    }

    #[DataProvider('verdictProvider')]
    public function testIsVerdict(string $text, bool $expected): void
    {
        self::assertSame($expected, ReviewStatusHook::isVerdict($text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function verdictSideProvider(): iterable
    {
        yield 'green only' => ['🟢 Merge — desktop#1005', ReviewStatusHook::APPROVE];
        yield 'red only' => ['🔴 Blocked — 1 finding', ReviewStatusHook::REQUEST_CHANGES];
        yield 'green before red in line' => ['🟢 Merge, 🔴 removed', ReviewStatusHook::APPROVE];
        yield 'red before green in line' => ['🔴 Blocked, although 🟢 CI', ReviewStatusHook::REQUEST_CHANGES];
        yield 'red line before green line' => ["🔴 Blocked — 1 finding\n🟢 tests are green", ReviewStatusHook::REQUEST_CHANGES];
        yield 'green line before red line' => ["🟢 Merge\n🔴 was: 2 findings, closed", ReviewStatusHook::APPROVE];
        yield 'none' => ['Plain reply', null];
    }

    #[DataProvider('verdictSideProvider')]
    public function testVerdictFirstEmojiWins(string $text, ?string $expected): void
    {
        self::assertSame($expected, ReviewStatusHook::verdict($text));
    }

    public function testOppositeStatusBlocks(): void
    {
        $lines = [self::user('Review PR #1005'), self::bash('t1', 'gh pr review 1005 --approve'), self::toolResult('t1')];
        $reply = "🔴 Blocked — 1 finding\n🟢 CI is green";

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines, $reply));
        $reason = ReviewStatusHook::reason(['last_assistant_message' => $reply], $lines);
        self::assertStringContainsString('Request changes', $reason);
        self::assertStringContainsString('opposite', $reason);
    }

    public function testLastStatusInTurnWins(): void
    {
        $lines = [
            self::user('Review PR #1005'),
            self::bash('t1', 'gh pr review 1005 --approve'),
            self::toolResult('t1'),
            self::bash('t2', 'gh pr review 1005 --request-changes --body-file r.md'),
            self::toolResult('t2'),
        ];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines, '🔴 Blocked — 1 finding'));
        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines, '🟢 Merge'));
    }

    public function testReasonWithoutStatusNamesRequiredState(): void
    {
        $reason = ReviewStatusHook::reason(['last_assistant_message' => '🟢 Merge'], [self::user('Review PR #1005')]);

        self::assertStringContainsString('Approve', $reason);
        self::assertStringContainsString('no successful review publication', $reason);
        // A repeated 🟢 on the same head was judged a "retelling" by the reviewer
        // and no status was set — reason must not leave such a loophole.
        self::assertStringContainsString('in an earlier one, it does not matter', $reason);
        self::assertStringContainsString('do not ask for permission', $reason);
    }

    public function testNonVerdictWithoutPrActivityPasses(): void
    {
        $lines = [self::user('what is new?'), self::bash('t1', 'git log -1')];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines, 'Plain reply'));
    }

    public function testPlainTextVerdictWithoutStatusBlocks(): void
    {
        // The review was done, the verdict is written in prose ("Did not
        // approve: …") without 🟢/🔴 and without a status — the hook used to let such a turn through.
        $lines = [self::user('Review pr #1055'), self::bash('t1', 'gh pr view 1055 --json files'), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines, 'Did not approve PR #1055: it does not implement the task.'));
        self::assertSame(ReviewStatusHook::MISSING, self::decide($lines, 'Did not approve.', active: true));
    }

    public function testNonVerdictWithStatusSetPasses(): void
    {
        $lines = [
            self::user('Review PR #1055'),
            self::bash('t1', 'gh pr view 1055'),
            self::toolResult('t1'),
            self::bash('t2', 'gh pr review 1055 --request-changes --body-file /tmp/r.md'),
            self::toolResult('t2'),
        ];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines, 'Requested changes.'));
    }

    public function testReasonWithoutVerdictDemandsOneOfTwoStates(): void
    {
        $reason = ReviewStatusHook::reason(['last_assistant_message' => 'Did not approve.'], [self::user('Review PR #1055')]);

        self::assertStringContainsString('no 🟢/🔴 verdict', $reason);
        self::assertStringContainsString('only part of its task is 🔴', $reason);
        self::assertStringContainsString('--request-changes', $reason);
        self::assertStringContainsString('first line starting with 🟢 or 🔴', $reason);
    }

    public function testVerdictWithoutPrActivityPasses(): void
    {
        // A retelling of a past verdict: there was no work with the PR in the turn — we do not
        // require a status, otherwise the hook would force approving an unchecked head.
        $lines = [self::user('remind me, what about #1004?')];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines));
    }

    public function testVerdictAfterCommentOnlyBlocks(): void
    {
        $lines = [
            self::user('Review PR #1005'),
            self::bash('t1', 'gh pr view 1005 -R anime-db/anime-db-desktop --json files'),
            self::toolResult('t1'),
            self::bash('t2', 'gh pr comment 1005 -R anime-db/anime-db-desktop --body-file /tmp/r.md'),
            self::toolResult('t2'),
        ];

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines, '🔴 Blocked — 1 finding'));
    }

    public function testSecondPassWithoutStatusIsMissing(): void
    {
        $lines = [self::user('Review PR #1005'), self::bash('t1', 'gh pr diff 1005'), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::MISSING, self::decide($lines, active: true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusCommandProvider(): iterable
    {
        yield 'approve' => ['gh pr review 1005 -R anime-db/anime-db-desktop --approve'];
        yield 'approve after multiline body' => ["gh pr review 1005 --body \"line\nline\" --approve"];
        yield 'api' => ['gh api -X POST repos/anime-db/x/pulls/1005/reviews -f event=APPROVE'];
    }

    #[DataProvider('statusCommandProvider')]
    public function testSuccessfulStatusPasses(string $command): void
    {
        $lines = [self::user('Review PR #1005'), self::bash('t1', $command), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines));
    }

    public function testFailedStatusCallDoesNotCount(): void
    {
        $lines = [
            self::user('Review PR #1005'),
            self::bash('t1', 'gh pr review 1005 --approve'),
            self::toolResult('t1', isError: true),
        ];

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines));
    }

    public function testHelpOrCommentMentionDoesNotCount(): void
    {
        $lines = [
            self::user('Review PR #1005'),
            self::bash('t1', 'gh pr review --help'),
            self::toolResult('t1'),
            self::bash('t2', 'gh pr comment 1005 --body "will run gh pr review later"'),
            self::toolResult('t2'),
        ];

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines));
    }

    public function testStatusFromPreviousTurnDoesNotCount(): void
    {
        $lines = [
            self::user('Review PR #1004'),
            self::bash('t1', 'gh pr review 1004 --approve'),
            self::toolResult('t1'),
            self::user('Review PR #1005'),
            self::bash('t2', 'gh pr view 1005'),
            self::toolResult('t2'),
        ];

        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines));
    }

    public function testHookFeedbackAndTaskNotificationDoNotSplitTurn(): void
    {
        $lines = [
            self::user('Review PR #1005'),
            self::bash('t1', 'gh pr review 1005 --approve'),
            self::toolResult('t1'),
            (string) json_encode(['type' => 'user', 'isMeta' => true, 'message' => ['content' => 'Stop hook feedback: …']]),
            self::user('<task-notification>background task finished</task-notification>'),
        ];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines));
    }

    public function testSettingsJsonPointsAtHookScriptWithStateDir(): void
    {
        $settings = json_decode(ReviewStatusHook::settingsJson('/srv/state'), true);
        self::assertIsArray($settings);
        $command = $settings['hooks']['Stop'][0]['hooks'][0]['command'];

        self::assertStringContainsString(dirname(__DIR__).'/bin/review-stop-hook.php', $command);
        self::assertStringEndsWith("'/srv/state'", $command);
    }

    public function testMarkerIsConsumedOnce(): void
    {
        $dir = sys_get_temp_dir().'/rsh-'.bin2hex(random_bytes(4));
        $marker = ReviewStatusHook::markerPath($dir, 'sess-1');
        mkdir(dirname($marker), 0775, true);
        touch($marker);

        self::assertTrue(ReviewStatusHook::consumeMarker($dir, 'sess-1'));
        self::assertFalse(ReviewStatusHook::consumeMarker($dir, 'sess-1'));

        rmdir(dirname($marker));
        rmdir($dir);
    }

    /**
     * @param list<string>      $lines
     * @param list<string>|null $ids
     */
    private static function decideIds(array $lines, ?array $ids, bool $active = false, string $reply = '🟢 Merge — a#1'): string
    {
        return ReviewStatusHook::decide(['last_assistant_message' => $reply, 'stop_hook_active' => $active], $lines, $ids);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reviewBodyProvider(): iterable
    {
        yield 'double quoted' => ["gh pr review 1 -R anime-db/a --approve --body \"C1 — fixed: x\nC2 — refuted\"", true];
        yield 'single quoted' => ["gh pr review 1 -R anime-db/a --approve --body 'C1 fixed, C2 refuted'", true];
        yield 'heredoc' => ["gh pr review 1 --approve --body \"\$(cat <<'EOF'\nC1 — fixed\nC2 — open\nEOF\n)\"", true];
        yield 'bare heredoc' => ["gh pr review 1 --approve --body-file - <<'EOF'\nC1 — fixed\nC2 — open\nEOF", true];
        yield 'bare heredoc unquoted tag' => ["gh pr review 1 --approve -F - <<EOF\nC1 — fixed\nC2 — open\nEOF\n", true];
        yield 'bare heredoc one id only' => ["gh pr review 1 --approve --body-file - <<'EOF'\nC1 — fixed\nEOF", false];
        yield 'bare heredoc unterminated' => ["gh pr review 1 --approve --body-file - <<'EOF'\nC1 — fixed\nC2 — open", false];
        yield 'one id only' => ['gh pr review 1 --approve --body "C1 — fixed"', false];
        yield 'one id only in heredoc' => ["gh pr review 1 --approve --body \"\$(cat <<'EOF'\nC1 — fixed\nEOF\n)\"", false];
        yield 'no body' => ['gh pr review 1 --approve', false];
        yield 'body-file' => ['gh pr review 1 --approve --body-file /tmp/c1-c2.md', false];
        yield 'short F' => ['gh pr review 1 --approve -F /tmp/body.md', false];
        yield 'prefix is not a match' => ['gh pr review 1 --approve --body "C10 fixed, C2 fixed"', false];
    }

    #[DataProvider('reviewBodyProvider')]
    public function testBodyMustCoverEveryId(string $command, bool $covered): void
    {
        $lines = [self::user('review a#1'), self::bash('t1', $command), self::toolResult('t1')];

        self::assertSame($covered ? ReviewStatusHook::PASS : ReviewStatusHook::BLOCK, self::decideIds($lines, ['C1', 'C2']));
        if (!$covered) {
            self::assertSame(ReviewStatusHook::MISSING, self::decideIds($lines, ['C1', 'C2'], true));
        }
    }

    public function testReasonListsMissingIds(): void
    {
        $lines = [self::user('x'), self::bash('t1', 'gh pr review 1 --approve --body "C1 — fixed"'), self::toolResult('t1')];
        $input = ['last_assistant_message' => '🟢 ok', 'stop_hook_active' => false];

        $reason = ReviewStatusHook::reason($input, $lines, ['C1', 'C2', 'C3']);

        self::assertStringContainsString('C2, C3', $reason);
        self::assertStringNotContainsString('C1,', $reason);
        self::assertStringContainsString('-F body=@file', $reason);
    }

    public function testNoIdsMeansNoCoverageCheck(): void
    {
        $lines = [self::user('x'), self::bash('t1', 'gh pr review 1 --approve'), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::PASS, self::decideIds($lines, null));
        self::assertSame(ReviewStatusHook::PASS, self::decideIds($lines, []));
    }

    public function testFailedReviewBodyDoesNotCoverIds(): void
    {
        $lines = [self::user('x'), self::bash('t1', 'gh pr review 1 --approve --body "C1 C2"'), self::toolResult('t1', true)];

        self::assertSame(ReviewStatusHook::BLOCK, self::decideIds($lines, ['C1', 'C2']));
    }

    public function testStatusStillRequiredWhenIdsGiven(): void
    {
        $lines = [self::user('x'), self::bash('t1', 'gh pr view 1'), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::BLOCK, self::decideIds($lines, ['C1']));
        self::assertStringContainsString('no successful review publication', ReviewStatusHook::reason(['last_assistant_message' => '🟢 ok'], $lines, ['C1']));
    }

    public function testStopScriptSkipsIdCheckInEpicRun(): void
    {
        $dir = sys_get_temp_dir().'/rsh-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/ids.json', '["C1","C2"]');
        $transcript = $dir.'/t.jsonl';
        file_put_contents($transcript, implode("\n", [
            self::user('x'),
            self::bash('t1', 'gh pr review 1 --approve --body "C1 only"'),
            self::toolResult('t1'),
        ])."\n");
        $input = (string) json_encode(['session_id' => 's1', 'transcript_path' => $transcript, 'last_assistant_message' => '🟢 ok']);
        $run = static function (array $env) use ($dir, $input): string {
            $proc = proc_open(
                [PHP_BINARY, dirname(__DIR__).'/bin/review-stop-hook.php', $dir],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                array_merge(getenv(), ['WORKSPACE_ASSISTANT_EPIC_SIGNAL' => ''], $env),
            );
            self::assertIsResource($proc);
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            proc_close($proc);

            return $out;
        };

        $manual = json_decode($run(['WORKSPACE_ASSISTANT_REVIEW_IDS' => $dir.'/ids.json']), true);
        self::assertIsArray($manual);
        self::assertSame('block', $manual['decision']);
        self::assertStringContainsString('C2', $manual['reason']);
        self::assertSame('', $run(['WORKSPACE_ASSISTANT_REVIEW_IDS' => $dir.'/ids.json', 'WORKSPACE_ASSISTANT_EPIC_SIGNAL' => $dir.'/signal.json']));

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function testIdsMarkerIsConsumedOnce(): void
    {
        $dir = sys_get_temp_dir().'/rsh-'.bin2hex(random_bytes(4));
        mkdir($dir);

        self::assertNull(ReviewStatusHook::consumeIdsMarker($dir, 'sess-1'));
        ReviewStatusHook::writeIdsMarker($dir, 'sess-1', ['C2', 'C3']);
        self::assertSame(['C2', 'C3'], ReviewStatusHook::consumeIdsMarker($dir, 'sess-1'));
        self::assertNull(ReviewStatusHook::consumeIdsMarker($dir, 'sess-1'));

        exec('rm -rf '.escapeshellarg($dir));
    }

    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiBodyProvider(): iterable
    {
        $head = 'gh api repos/o/r/pulls/7/reviews';
        yield 'multi-line -f' => ["{$head} \\\n  -f commit_id=".self::SHA." \\\n  -f event=APPROVE \\\n  -f body=\"C1 — fixed\nC2 — open\""];
        yield '-f' => ["{$head} -f commit_id=".self::SHA.' -f event=APPROVE -f body="C1 — fixed C2 — open"'];
        yield '-F' => ["{$head} -F commit_id=".self::SHA.' -f event=APPROVE -F body="C1 — fixed C2 — open"'];
        yield '--field' => ["{$head} -f commit_id=".self::SHA.' -f event=APPROVE --field body=\'C1 — fixed C2 — open\''];
        yield '--raw-field' => ["{$head} -f commit_id=".self::SHA.' -f event=APPROVE --raw-field "body=C1 — fixed C2 — open"'];
        yield 'bare value' => ["{$head} -f commit_id=".self::SHA.' -f event=APPROVE -f body=C1,C2'];
        yield 'heredoc' => ["{$head} -f commit_id=".self::SHA." -f event=APPROVE -F body=@- <<'EOF'\nC1 — fixed\nC2 — open\nEOF"];
        yield 'continued heredoc' => ["{$head} \\\n  -f commit_id=".self::SHA." \\\n  -f event=\"APPROVE\" \\\n  -F body=@- <<'EOF'\nC1 — fixed\nC2 — open\nEOF"];
    }

    #[DataProvider('apiBodyProvider')]
    public function testApiStatusAndBodyIdsAreRecognised(string $command): void
    {
        $lines = [self::user('x'), self::bash('t1', $command), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines));
        self::assertSame([], ReviewStatusHook::missingIds($lines, ['C1', 'C2']));
        self::assertSame(['C3'], ReviewStatusHook::missingIds($lines, ['C1', 'C3']));
    }

    public function testApiStatusWithoutIdsInBodyIsMissingThem(): void
    {
        $command = "gh api repos/o/r/pulls/7/reviews \\\n  -f commit_id=".self::SHA." \\\n  -f event=REQUEST_CHANGES \\\n  -f body=\"only prose\"";
        $lines = [self::user('x'), self::bash('t1', $command), self::toolResult('t1')];

        self::assertSame(ReviewStatusHook::PASS, self::decide($lines, '🔴 Blocked'));
        self::assertSame(ReviewStatusHook::BLOCK, self::decide($lines, '🟢 Merge'));
        self::assertSame(['C1'], ReviewStatusHook::missingIds($lines, ['C1']));
    }

    public function testApiBodyIsNotTakenFromOtherFields(): void
    {
        $command = 'gh api repos/o/r/pulls/7/reviews -f commit_id='.self::SHA.' -f event=APPROVE -f body=ok -f comments="C1"';
        $lines = [self::user('x'), self::bash('t1', $command), self::toolResult('t1')];

        self::assertSame(['C1'], ReviewStatusHook::missingIds($lines, ['C1']));
    }

    public function testReasonNamesTheOnlyLegitimateNoStatusCases(): void
    {
        $reason = ReviewStatusHook::reason(['last_assistant_message' => '🟢 Merge'], [self::user('Review PR #1005')]);

        self::assertStringContainsString('commit_id=<SHA>', $reason);
        self::assertStringContainsString('only legitimate reasons to end without a status', $reason);
        self::assertStringContainsString('manual Approve over a moved head', $reason);
        self::assertStringContainsString('GitHub rejects the POST', $reason);
        self::assertStringContainsString('commit_id=<SHA>', ReviewStatusHook::FALLBACK_REASON);
        self::assertStringContainsString('only legitimate reasons to leave no status', ReviewStatusHook::FALLBACK_REASON);
        self::assertSame(ReviewStatusHook::MISSING, self::decide([self::user('x'), self::bash('t1', 'gh pr view 1'), self::toolResult('t1')], '🟢 ok', true));
    }
}
