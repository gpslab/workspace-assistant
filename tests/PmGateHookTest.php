<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\PmGateHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmGateHook::class)]
final class PmGateHookTest extends TestCase
{
    private const array STAGES = [
        ['id' => 'critique', 'agent' => 'critic', 'required' => true],
        ['id' => 'verify', 'agent' => 'verifier', 'required' => true],
        ['id' => 'extra', 'agent' => 'extra', 'required' => false],
    ];

    private const string CREATE = 'gh issue create --title t --body b';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pmgate-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,pm-gate/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir.'/pm-gate');
        @rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function commandProvider(): iterable
    {
        yield 'issue create' => ['gh issue create --title t --body b', true];
        yield 'chained' => ['cd x && gh issue create -t a', true];
        yield 'api POST' => ['gh api repos/o/r/issues -X POST -f title=t', true];
        yield 'api method POST' => ['gh api --method POST /repos/o/r/issues -f title=t', true];
        yield 'api -f without method' => ['gh api repos/o/r/issues -f title=t -f body=b', true];
        yield 'api -F without method' => ['gh api repos/o/r/issues -F title=t', true];
        yield 'api --input' => ['gh api repos/o/r/issues --input body.json', true];
        yield 'api comments' => ['gh api repos/o/r/issues/12/comments -f body=x', false];
        yield 'api listing' => ['gh api repos/o/r/issues', false];
        yield 'api GET with fields' => ['gh api repos/o/r/issues -X GET -f state=open', false];
        yield 'api --method GET' => ['gh api repos/o/r/issues --method GET -F per_page=5', false];
        yield 'api patch one issue' => ['gh api repos/o/r/issues/5 -X PATCH -f state=closed', false];
        yield 'git commit text' => ['git commit -m "gh issue create is gated"', false];
        yield 'grep argument' => ['grep -rn "gh issue create" docs', false];
        yield 'api continuation' => ["gh api repos/o/r/issues \\\n  -f title=t -f body=b", true];
        yield 'issue continuation' => ["gh issue \\\n create --title t", true];
        yield 'if then' => ['if true; then gh issue create -t a; fi', true];
        yield 'brace group' => ['{ gh issue create -t a; }', true];
        yield 'var prefix' => ['GH_REPO=o/r gh issue create -t a', true];
        yield 'env prefix' => ['env GH_REPO=o/r gh issue create -t a', true];
        yield 'command prefix' => ['command gh issue create -t a', true];
        yield 'xargs prefix' => ['echo x | xargs gh issue create -t', true];
        yield 'api full url' => ['gh api https://api.github.com/repos/o/r/issues -f title=t', true];
        yield 'quoted separator' => ['git commit -m "x; gh issue create"', false];
        yield 'single quoted separator' => ["echo 'a && gh issue create'", false];
        yield 'heredoc with apostrophe' => ["cat > /tmp/b.md <<'EOF'\nThe gate doesn't see this.\nEOF\ngh issue create --title t --body-file /tmp/b.md", true];
        yield 'heredoc dash double quoted' => ["cat <<-\"END\" > b.md\n\tit's\n\tEND\ngh issue create -t a", true];
        yield 'heredoc body mentions create' => ["cat > b.md <<'EOF'\ngh issue create --title t\nEOF\necho done", false];
        yield 'timeout prefix' => ['timeout 60 gh issue create -t a', true];
        yield 'timeout with options' => ['timeout -k 5 --preserve-status 60s gh issue create -t a', true];
        yield 'timeout in substitution' => ['U=$(timeout 60 gh issue create -t a)', true];
        yield 'timeout in function' => ['mk(){ echo x; timeout 60 gh issue create -t a; }; mk', true];
        yield 'env unset' => ['env -u GH_TOKEN gh issue create -t a', true];
        yield 'env chdir and assignment' => ['env -C /tmp -u X FOO=1 gh issue create -t a', true];
        yield 'here-string quoted' => ["cat <<<\"hi\"\ngh issue create -t a", true];
        yield 'here-string word' => ["cat <<< hi\ngh issue create -t a", true];
        yield 'arithmetic shift' => ["echo \$((1<<3))\ngh issue create -t a", true];
        yield 'issue list' => ['gh issue list', false];
        yield 'glab issue create' => ['glab issue create -t x', true];
        yield 'glab issue create chained' => ['cd x && glab issue create -t x -d y', true];
        yield 'glab issue new' => ['timeout 60 glab issue new -t x', true];
        yield 'glab issue create env prefix' => ['env GITLAB_HOST=h.example glab issue create -t x', true];
        yield 'glab issue list' => ['glab issue list', false];
        yield 'glab api numeric id' => ['glab api projects/12/issues -X POST -f title=t', true];
        yield 'glab api method flag' => ['glab api --method POST projects/12/issues -f title=t', true];
        yield 'glab api body flags only' => ['glab api projects/12/issues -F title=t', true];
        yield 'glab api --input' => ['glab api projects/12/issues --input b.json', true];
        yield 'glab api encoded path' => ['glab api projects/group%2Fsub%2Fproj/issues -X POST -f title=t', true];
        yield 'glab api :id' => ['glab api projects/:id/issues -f title=t', true];
        yield 'glab api :fullpath' => ['glab api "projects/:fullpath/issues" -X POST', true];
        yield 'glab api leading slash' => ['glab api /projects/12/issues -f title=t', true];
        yield 'glab api v4 prefix' => ['glab api api/v4/projects/12/issues -f title=t', true];
        yield 'glab api leading slash v4' => ['glab api /api/v4/projects/:id/issues -f title=t', true];
        yield 'glab api full url' => ['glab api https://gitlab.example.com/api/v4/projects/g%2Fp/issues -X POST', true];
        yield 'glab api prefixed chain' => ['FOO=1 env -u X glab api projects/12/issues -f title=t && echo ok', true];
        yield 'glab api GET' => ['glab api projects/1/issues', false];
        yield 'glab api GET with method' => ['glab api projects/1/issues -X GET -F per_page=5', false];
        yield 'glab api one issue' => ['glab api projects/1/issues/5 -X PUT -f state_event=close', false];
        yield 'glab api notes' => ['glab api projects/1/issues/5/notes -f body=x', false];
        yield 'gh api graphql createIssue' => ['gh api graphql -f query=\'mutation { createIssue(input: {repositoryId: "R", title: "t"}) { issue { id } } }\'', true];
        yield 'gh api graphql prefixed' => ['cd x && GH_TOKEN=t timeout 30 gh api graphql -f query="mutation{createIssue(input:{title:\"t\"}){issue{id}}}"', true];
        yield 'glab api graphql createIssue' => ['glab api graphql -f query=\'mutation { createIssue(input: {projectPath: "g/p", title: "t"}) { issue { iid } } }\'', true];
        yield 'glab api graphql chained' => ['env X=1 glab api graphql --raw-field query="mutation{createIssue(input:{title:\"t\"}){errors}}"; echo', true];
        yield 'gh api graphql full url' => ['gh api https://api.github.com/graphql -f query=\'mutation { createIssue(input: {title: "t"}) { issue { id } } }\'', true];
        yield 'gh api graphql path' => ['gh api /graphql -f query=\'mutation { createIssue(input: {title: "t"}) { issue { id } } }\'', true];
        yield 'glab api graphql host url' => ['glab api https://gitlab.example.com/api/graphql -f query=\'mutation { createIssue(input: {title: "t"}) { errors } }\'', true];
        yield 'graphql query only' => ['gh api graphql -f query=\'{ viewer { login } }\'', false];
        yield 'graphql query keyword' => ['glab api graphql -f query=\'query { project(fullPath: "g/p") { issues { nodes { id } } } }\'', false];
        yield 'graphql other mutation' => ['gh api graphql -f query=\'mutation { addComment(input: {}) { clientMutationId } }\'', false];
        yield 'graphql createIssue text in grep' => ['grep -rn "mutation createIssue" docs', false];
    }

    #[DataProvider('commandProvider')]
    public function testCreatesIssue(string $command, bool $expected): void
    {
        self::assertSame($expected, PmGateHook::createsIssue($command));
    }

    private static function user(string $text): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => false, 'message' => ['role' => 'user', 'content' => $text]]);
    }

    private static function agentCall(string $id, string $agent, bool $sidechain = false): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => $sidechain, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Agent', 'input' => ['subagent_type' => $agent]],
        ]]]);
    }

    private static function agentResult(string $id, string $status = 'completed', bool $sidechain = false): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => $sidechain, 'toolUseResult' => ['status' => $status], 'message' => ['content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'done'],
        ]]]);
    }

    private static function bash(string $id, string $command = self::CREATE): string
    {
        return (string) json_encode(['type' => 'assistant', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => ['command' => $command]],
        ]]]);
    }

    private static function bashResult(string $id, bool $isError = false): string
    {
        return (string) json_encode(['type' => 'user', 'isSidechain' => false, 'message' => ['content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok', 'is_error' => $isError],
        ]]]);
    }

    /**
     * @return list<string>
     */
    private static function stage(string $id, string $agent, string $status = 'completed'): array
    {
        return [self::agentCall($id, $agent), self::agentResult($id, $status)];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function missing(array $lines): array
    {
        return PmGateHook::missingStages($lines, self::STAGES);
    }

    public function testCompletedStagesPass(): void
    {
        $lines = [self::user('design it'), ...self::stage('a1', 'critic'), ...self::stage('a2', 'verifier')];

        self::assertSame([], self::missing($lines));
    }

    public function testOptionalStageIsNotRequired(): void
    {
        self::assertCount(2, self::missing([self::user('design it')]));
    }

    public function testBackgroundCallDoesNotCount(): void
    {
        $lines = [self::user('x'), ...self::stage('a1', 'critic', 'async_launched'), ...self::stage('a2', 'verifier')];

        self::assertSame(['critique (agent critic)'], self::missing($lines));
    }

    public function testSidechainCallDoesNotCount(): void
    {
        $lines = [
            self::user('x'),
            self::agentCall('a1', 'critic', true), self::agentResult('a1', 'completed', true),
            ...self::stage('a2', 'verifier'),
        ];

        self::assertSame(['critique (agent critic)'], self::missing($lines));
    }

    public function testOrderIsEnforced(): void
    {
        $lines = [self::user('x'), ...self::stage('a2', 'verifier'), ...self::stage('a1', 'critic')];

        $missing = self::missing($lines);
        self::assertCount(1, $missing);
        self::assertStringStartsWith('verify (agent verifier)', $missing[0]);
    }

    public function testRerunInOrderFixesTheOrder(): void
    {
        $lines = [self::user('x'), ...self::stage('a2', 'verifier'), ...self::stage('a1', 'critic'), ...self::stage('a3', 'verifier')];

        self::assertSame([], self::missing($lines));
    }

    public function testWindowStartsAfterAuthorMessageFollowingCreation(): void
    {
        $batch = [
            self::user('design it'), ...self::stage('a1', 'critic'), ...self::stage('a2', 'verifier'),
            self::bash('b1'), self::bashResult('b1'), self::bash('b2'), self::bashResult('b2'),
        ];

        // The first batch passes whole on one set of stages, even at its second issue.
        self::assertSame([], self::missing($batch));
        // The author replies: the next design needs fresh stages.
        self::assertCount(2, self::missing([...$batch, self::user('and another one')]));
        self::assertSame([], self::missing([...$batch, self::user('and another one'), ...self::stage('a3', 'critic'), ...self::stage('a4', 'verifier')]));
    }

    public function testFailedCreationDoesNotCloseTheWindow(): void
    {
        $lines = [
            self::user('x'), ...self::stage('a1', 'critic'), ...self::stage('a2', 'verifier'),
            self::bash('b1'), self::bashResult('b1', true), self::user('retry'),
        ];

        self::assertSame([], self::missing($lines));
    }

    /**
     * @return array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>}
     */
    private static function config(): array
    {
        return ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false, 'review' => true]];
    }

    public function testSkipWithReasonWhereAllowed(): void
    {
        $lines = [self::user('x')];

        $decision = PmGateHook::decide($lines, self::config(), 'review', 'side finding, no design');
        self::assertSame(PmGateHook::ALLOW, $decision['decision']);
        self::assertTrue($decision['skipped']);
    }

    public function testSkipWithoutReasonIsDenied(): void
    {
        $decision = PmGateHook::decide([self::user('x')], self::config(), 'review', "  \n");

        self::assertSame(PmGateHook::DENY, $decision['decision']);
        self::assertStringContainsString(PmGateHook::SKIP_ENV, $decision['reason']);
    }

    public function testReasonIsIgnoredWhereSkipIsNotAllowed(): void
    {
        $decision = PmGateHook::decide([self::user('x')], self::config(), 'pm', 'because');

        self::assertSame(PmGateHook::DENY, $decision['decision']);
        self::assertStringContainsString('critique (agent critic); verify (agent verifier)', $decision['reason']);
        self::assertStringNotContainsString(PmGateHook::SKIP_ENV, $decision['reason']);
    }

    public function testCompleteStagesAllowWithoutSkip(): void
    {
        $lines = [self::user('x'), ...self::stage('a1', 'critic'), ...self::stage('a2', 'verifier')];

        $decision = PmGateHook::decide($lines, self::config(), 'pm', '');
        self::assertSame(PmGateHook::ALLOW, $decision['decision']);
        self::assertFalse($decision['skipped']);
    }

    public function testSnapshotRoundTripAndCleanup(): void
    {
        $config = ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]];
        PmGateHook::writeSnapshot($this->dir, 'run-1', $config + ['warnings' => []]);
        self::assertSame($config, PmGateHook::readSnapshot($this->dir, 'run-1'));
        PmGateHook::cleanup($this->dir, 'run-1');
        $this->expectException(\RuntimeException::class);
        PmGateHook::readSnapshot($this->dir, 'run-1');
    }

    /**
     * @param list<string> $lines
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runScript(string $script, array $lines, string $command, string $kind = 'pm', string $runId = 'run-1', string $skipFile = ''): array
    {
        $transcript = $this->dir.'/transcript.jsonl';
        file_put_contents($transcript, implode("\n", $lines)."\n");
        $input = json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command], 'transcript_path' => $transcript]);
        $proc = proc_open(
            [PHP_BINARY, $script, $this->dir, $kind, $runId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), [PmGateHook::SKIP_ENV => $skipFile]),
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], (string) $input);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function script(): string
    {
        return dirname(__DIR__).'/bin/pm-gate-hook.php';
    }

    public function testScriptDeniesAndLogsAndUsesSnapshot(): void
    {
        PmGateHook::writeSnapshot($this->dir, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);

        [$code, $out, $err] = $this->runScript($this->script(), [self::user('x')], self::CREATE);

        self::assertSame(0, $code, $err);
        $json = json_decode($out, true);
        self::assertIsArray($json);
        self::assertSame('deny', $json['hookSpecificOutput']['permissionDecision']);
        self::assertStringContainsString('critique (agent critic)', $json['hookSpecificOutput']['permissionDecisionReason']);
        self::assertStringContainsString('deny:', (string) file_get_contents($this->dir.'/pm-gate.log'));
    }

    public function testScriptAllowsOtherCommandsAndSkipWithReason(): void
    {
        PmGateHook::writeSnapshot($this->dir, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['review' => true]]);

        [$code, $out] = $this->runScript($this->script(), [self::user('x')], 'ls -la', 'review');
        self::assertSame([0, ''], [$code, $out]);

        $skip = $this->dir.'/skip.txt';
        file_put_contents($skip, 'only a typo fix');
        [$code, $out] = $this->runScript($this->script(), [self::user('x')], self::CREATE, 'review', 'run-1', $skip);
        self::assertSame([0, ''], [$code, $out]);
    }

    public function testScriptDecidesBySnapshotNotByLiveConfig(): void
    {
        // The live .dist config requires other agents and forbids skipping in pm runs; the snapshot differs.
        $lines = [self::user('x'), ...self::stage('a1', 'snapshot-only-agent')];
        PmGateHook::writeSnapshot($this->dir, 'run-1', [
            'stages' => [['id' => 'only', 'agent' => 'snapshot-only-agent', 'required' => true]],
            'skip_allowed' => ['pm' => false],
        ]);
        [$code, $out, $err] = $this->runScript($this->script(), $lines, self::CREATE);
        self::assertSame([0, ''], [$code, $out], $err);

        PmGateHook::writeSnapshot($this->dir, 'run-2', [
            'stages' => [['id' => 'only', 'agent' => 'snapshot-only-agent', 'required' => true]],
            'skip_allowed' => ['pm' => true],
        ]);
        $skip = $this->dir.'/skip.txt';
        file_put_contents($skip, 'reason');
        [$code, $out, $err] = $this->runScript($this->script(), [self::user('x')], self::CREATE, 'pm', 'run-2', $skip);
        self::assertSame([0, ''], [$code, $out], $err);
    }

    public function testScriptWritesAppliedMarkerOnlyWhenSkipIsApplied(): void
    {
        @mkdir($this->dir.'/pm-gate', 0775, true);
        $skip = $this->dir.'/skip.txt';
        file_put_contents($skip, ' only a typo fix ');

        PmGateHook::writeSnapshot($this->dir, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['review' => true, 'pm' => false]]);
        $marker = PmGateHook::appliedPath($this->dir, 'run-1');

        // Denied (skip not allowed for pm): no marker.
        [, $out] = $this->runScript($this->script(), [self::user('x')], self::CREATE, 'pm', 'run-1', $skip);
        self::assertStringContainsString('deny', $out);
        self::assertFileDoesNotExist($marker);

        // All stages passed: no marker even with a reason file.
        $lines = [self::user('x'), ...self::stage('a1', 'critic'), ...self::stage('a2', 'verifier')];
        [$code, $out] = $this->runScript($this->script(), $lines, self::CREATE, 'review', 'run-1', $skip);
        self::assertSame([0, ''], [$code, $out]);
        self::assertFileDoesNotExist($marker);

        // Skip allowed and reason written: marker holds the reason.
        [$code, $out] = $this->runScript($this->script(), [self::user('x')], self::CREATE, 'review', 'run-1', $skip);
        self::assertSame([0, ''], [$code, $out]);
        self::assertSame('only a typo fix', file_get_contents($marker));
    }

    public function testScriptFailsClosedWithoutSnapshot(): void
    {
        [$code, $out, $err] = $this->runScript($this->script(), [self::user('x')], self::CREATE, 'pm', 'missing-run');

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, $err);
    }

    public function testScriptFailsClosedWhenBootstrapFails(): void
    {
        // A copy of the script in a tree without bootstrap.php: the include fails.
        mkdir($this->dir.'/tree/bin', 0775, true);
        copy($this->script(), $this->dir.'/tree/bin/pm-gate-hook.php');

        [$code, $out, $err] = $this->runScript($this->dir.'/tree/bin/pm-gate-hook.php', [self::user('x')], 'ls');

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, $err);
        unlink($this->dir.'/tree/bin/pm-gate-hook.php');
        rmdir($this->dir.'/tree/bin');
        rmdir($this->dir.'/tree');
    }

    public function testSettingsCommandBlocksWhenHookDies(): void
    {
        $settings = json_decode(PmGateHook::settingsJson($this->dir, 'pm', 'run-1', false), true);
        self::assertIsArray($settings);
        self::assertSame('Bash', $settings['hooks']['PreToolUse'][0]['matcher']);
        $command = $settings['hooks']['PreToolUse'][0]['hooks'][0]['command'];
        self::assertStringContainsString('|| exit 2', $command);
        self::assertArrayNotHasKey('Stop', $settings['hooks']);

        // A hook that dies with 255 (PHP fatal) becomes exit 2 with the fixed reason.
        $dying = str_replace(escapeshellarg(dirname(__DIR__).'/bin/pm-gate-hook.php'), "-r 'exit(255);' --", $command);
        self::assertNotSame($command, $dying);
        $proc = proc_open(['sh', '-c', $dying], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(2, proc_close($proc));
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, $err);
    }

    public function testSettingsKeepReviewStopHookInReviewMode(): void
    {
        $settings = json_decode(PmGateHook::settingsJson($this->dir, 'review', 'run-1', true), true);
        self::assertIsArray($settings);

        self::assertArrayHasKey('Stop', $settings['hooks']);
        self::assertArrayHasKey('PreToolUse', $settings['hooks']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kindProvider(): iterable
    {
        foreach (['pm', 'review', 'tick', 'final', 'epic_review'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('kindProvider')]
    public function testKindReachesTheHookAsArgument(string $kind): void
    {
        $settings = json_decode(PmGateHook::settingsJson('/srv/state', $kind, 'run-7', false), true);
        self::assertIsArray($settings);

        self::assertStringContainsString("pm-gate-hook.php' '/srv/state' '{$kind}' 'run-7'", $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
    }
}
