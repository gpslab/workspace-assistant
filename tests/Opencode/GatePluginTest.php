<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\GitHooks;
use GpsLab\WorkspaceAssistant\PmGateHook;
use GpsLab\WorkspaceAssistant\ReviewCommitHook;
use GpsLab\WorkspaceAssistant\SecretGuard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The OpenCode plugin (`opencode-plugin`) under bare `node` with a stub `ctx`.
 *
 * The plugin holds no security logic: it starts the PHP hooks of the bot. These tests pin the wiring
 * the bot relies on — the mode it picks, the deny translation, the fail-closed paths and the
 * engine-neutral input — not the gate rules, which {@see \GpsLab\WorkspaceAssistant\Tests\PmGateHookTest}
 * and {@see \GpsLab\WorkspaceAssistant\Tests\ReviewCommitHookTest} cover through PHP directly, and not
 * the guard rule, which {@see \GpsLab\WorkspaceAssistant\Tests\SecretGuardTest} covers.
 *
 * Two modes: a run that carries the gate kind runs the gate hooks as before, a run that carries none
 * runs the secret guard alone (`bin/secret-guard-hook.php`) and lets ordinary commands pass.
 */
#[CoversNothing]
final class GatePluginTest extends TestCase
{
    private const array STAGES = [
        ['id' => 'stage_a', 'agent' => 'stage_a', 'required' => true],
        ['id' => 'stage_b', 'agent' => 'stage_b', 'required' => true],
    ];

    private const string ISSUE_CREATE = 'gh issue create --title t --body b';
    private const string APPROVE = 'gh pr review 1 --approve';

    private string $dir;
    private string $state;
    private string $harness;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gate-plugin-'.bin2hex(random_bytes(4));
        $this->state = $this->dir.'/state';
        mkdir($this->state, 0775, true);
        $this->harness = $this->dir.'/harness.mjs';
        file_put_contents($this->harness, self::harness());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state.'/{,pm-gate/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->state.'/pm-gate');
        @rmdir($this->state);
        @unlink($this->harness);
        foreach (glob($this->dir.'/{.env,secrets.env}') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** A fake GitHub token: a shape the guard knows without any source. Built by concatenation. */
    private static function fakeToken(): string
    {
        return 'ghp_'.substr(str_repeat('aB3dE5gH7jK9mN1pQ4rS', 2), 0, 36);
    }

    public function testADenyBecomesEffectDenyWithTheHookReason(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);

        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'));

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('Issue creation blocked:', (string) $result['message']);
        self::assertStringContainsString('stage_b', (string) $result['message']);
    }

    public function testAnAllowLeavesTheEventUntouched(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);

        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-sequential'));

        self::assertNull($result['effect']);
        self::assertNull($result['message']);
    }

    public function testACommandTheGateDoesNotCheckIsAllowed(): void
    {
        // No snapshot: a PM hook that did run would fail closed. `ls` never reaches that point.
        $result = $this->evaluate('ls -la', self::fixture('stage-single'));

        self::assertNull($result['effect']);
    }

    /**
     * The gate environment is fail-closed: an empty state directory or run id in a run that carries a
     * gate kind is invalid and denied, whatever the command is. `ls` is not gated by the PM gate, so
     * only the environment can deny it — and the deny comes from the PHP hook, which owns the input
     * validation.
     */
    public function testAnEmptyStateDirectoryDenies(): void
    {
        $result = $this->evaluate('ls -la', self::fixture('stage-single'), stateDir: '');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, (string) $result['message']);
    }

    public function testAnEmptyRunIdDenies(): void
    {
        $result = $this->evaluate('ls -la', self::fixture('stage-single'), runId: '');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, (string) $result['message']);
    }

    /**
     * A run that does not need the commit hook carries no flag at all; a flag that is present must be
     * `1`. An empty flag is a present flag: `env()` folds it into the same `""` as an unset one, so the
     * check reads `process.env` and this case must deny.
     */
    public function testAReviewFlagThatIsNotOneDenies(): void
    {
        foreach (['', '0', 'yes'] as $flag) {
            $result = $this->evaluate('ls -la', self::fixture('stage-single'), review: $flag);

            self::assertSame('deny', $result['effect'], 'flag "'.$flag.'"');
            self::assertStringContainsString(ReviewCommitHook::ENABLED_ENV, (string) $result['message'], 'flag "'.$flag.'"');
        }
    }

    /** A `review` run without the flag would run no commit hook, so the call is denied. */
    public function testAReviewRunWithoutTheFlagDenies(): void
    {
        foreach (['review', 'epic_review'] as $kind) {
            $result = $this->evaluate('ls -la', self::fixture('stage-single'), kind: $kind);

            self::assertSame('deny', $result['effect'], $kind);
            self::assertStringContainsString(ReviewCommitHook::ENABLED_ENV, (string) $result['message'], $kind);
        }
    }

    /**
     * The PM gate needs no review flag: `ls` on a `pm` run passes with it absent (the flag rules must
     * not deny every shell call of a run that does not carry one).
     */
    public function testAPmRunNeedsNoReviewFlag(): void
    {
        $result = $this->evaluate('ls -la', self::fixture('stage-single'), kind: 'pm');

        self::assertNull($result['effect']);
    }

    /** The skip-reason file reaches the PHP hook: the hook records the skip it applied. */
    public function testTheSkipReasonFileReachesThePhpGate(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => true]]);
        $skip = $this->dir.'/skip.txt';
        file_put_contents($skip, 'only a typo fix');

        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'), extraEnv: [PmGateHook::SKIP_ENV => $skip]);

        self::assertNull($result['effect']);
        self::assertSame('only a typo fix', PmGateHook::skipReason($this->state, 'run-1'));
    }

    /** The recorded-SHAs file reaches the PHP review hook: the commit of the command is compared with it. */
    public function testTheRecordedShasFileReachesTheReviewHook(): void
    {
        $shas = $this->dir.'/shas.json';
        file_put_contents($shas, json_encode(['owner/repo#1' => str_repeat('a', 40)]));
        $command = 'gh api repos/owner/repo/pulls/1/reviews -f commit_id='.str_repeat('b', 40).' -f event=APPROVE -f body=b';

        $result = $this->evaluate($command, self::fixture('stage-single'), kind: 'review', review: '1', extraEnv: [ReviewCommitHook::SHAS_ENV => $shas]);

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('is not the commit recorded for PR #1', (string) $result['message']);
    }

    /** A history of an unrecognised shape denies with the hook failure reason instead of "no stages". */
    public function testAHistoryOfAnUnknownShapeDenies(): void
    {
        $result = $this->evaluate(self::ISSUE_CREATE, null, context: 'unknown-shape');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, (string) $result['message']);
    }

    /**
     * An empty object `{}` from `context()` is written to the file as `{}`, which the reader rejects:
     * it must not be read as "no messages yet". `[]` (a real empty history) stays a valid bare list.
     */
    public function testAnEmptyObjectHistoryDenies(): void
    {
        $result = $this->evaluate(self::ISSUE_CREATE, null, context: 'empty-object');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, (string) $result['message']);
    }

    public function testAFailingSessionContextDenies(): void
    {
        $result = $this->evaluate(self::ISSUE_CREATE, null, context: 'throw');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('The command was blocked: the gate failed.', (string) $result['message']);
        self::assertStringContainsString('session context failed', (string) $result['message']);
    }

    public function testAFailingHookDenies(): void
    {
        // No snapshot for this run id: bin/pm-gate-hook.php fails closed with exit 2.
        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'));

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::FAILURE_REASON, (string) $result['message']);
    }

    public function testAPromiseSessionContextIsAwaited(): void
    {
        PmGateHook::writeSnapshot($this->state, 'run-1', ['stages' => self::STAGES, 'skip_allowed' => ['pm' => false]]);

        $sequential = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-sequential'), context: 'promise');
        $single = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'), context: 'promise');

        self::assertNull($sequential['effect']);
        self::assertSame('deny', $single['effect']);
    }

    public function testAHistoryThatCannotBeSerialisedDeniesOnThePromisePath(): void
    {
        // The history resolves to an object holding a BigInt: JSON.stringify inside `decide()` throws
        // after the promise has resolved, so only the try/catch around the fulfilled callback denies.
        $result = $this->evaluate(self::ISSUE_CREATE, null, context: 'promise-unserializable');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('The command was blocked: the gate failed.', (string) $result['message']);
    }

    public function testTheReviewCommitGateRunsOnlyOnAReviewRun(): void
    {
        $review = $this->evaluate(self::APPROVE, self::fixture('stage-single'), kind: 'review', review: '1');
        $pm = $this->evaluate(self::APPROVE, self::fixture('stage-single'), kind: 'pm');

        self::assertSame('deny', $review['effect']);
        self::assertStringContainsString('attaches the review to the PR head', (string) $review['message']);
        self::assertNull($pm['effect']);
    }

    public function testANonShellActionIsNotEvaluated(): void
    {
        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'), action: 'read');

        self::assertNull($result['effect']);
    }

    /**
     * A run with no gate kind (`arch`, `/compact`) runs the guard alone: a command that carries a secret
     * is denied, the reason names its type and never a value.
     */
    public function testWithoutAGateKindACommandWithASecretIsDenied(): void
    {
        $token = self::fakeToken();

        $result = $this->evaluate('curl -H "Authorization: token '.$token.'" https://api.github.com/user', null, kind: '');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('github_token', (string) $result['message']);
        self::assertStringNotContainsString($token, (string) $result['message']);
    }

    /** The same run lets an ordinary command through — the guard is no deny-all. */
    public function testWithoutAGateKindAnOrdinaryCommandPasses(): void
    {
        $result = $this->evaluate('git status --short && ls -la', null, kind: '');

        self::assertNull($result['effect']);
        self::assertNull($result['message']);
    }

    /**
     * A `shell` event whose command the plugin cannot read (no `resources`) has no legitimate use, and a
     * command the guard cannot scan must not pass: the guard denies it fail-closed.
     */
    public function testWithoutAGateKindAnEventWithNoCommandIsDenied(): void
    {
        $result = $this->evaluate('', null, kind: '');

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(SecretGuard::FAILURE_REASON, (string) $result['message']);
    }

    /**
     * The guard needs the command alone: without a gate kind the session history is never read, so a
     * `context()` that throws does not deny an ordinary command.
     */
    public function testWithoutAGateKindTheSessionHistoryIsNotRead(): void
    {
        $result = $this->evaluate('ls -la', null, context: 'throw', kind: '');

        self::assertNull($result['effect']);
    }

    /** The known values of the bot reach the guard: the `.env` of the run names a `SECRET_ENV_FILES` file. */
    public function testWithoutAGateKindAKnownValueOfTheBotIsDenied(): void
    {
        $value = 'Zq7Xm2Vb9Lk4Pw8';
        $secrets = $this->dir.'/secrets.env';
        file_put_contents($secrets, 'DB_PASSWORD='.$value."\n");
        $envFile = $this->dir.'/.env';
        file_put_contents($envFile, 'SECRET_ENV_FILES='.$secrets."\n");

        $result = $this->evaluate('psql "postgres://db:'.$value.'@localhost/app" -c select', null, kind: '', extraEnv: [GitHooks::ENV_FILE_ENV => $envFile]);

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString('known:', (string) $result['message']);
        self::assertStringNotContainsString($value, (string) $result['message']);
    }

    /**
     * The gate variables of a run are set together: some of them without the gate kind is a broken
     * wiring, not an `arch` run, and the run is denied instead of silently running ungated.
     */
    public function testAGateVariableWithoutTheGateKindDenies(): void
    {
        $result = $this->evaluate('ls -la', null, kind: '', extraEnv: [PmGateHook::STATE_ENV => $this->state]);

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(PmGateHook::STATE_ENV, (string) $result['message']);
    }

    /**
     * A guard run fails closed too: a guard that cannot run (here: no PHP binary) denies the call rather
     * than letting it through, and the reason is the guard's, not the gate's.
     */
    public function testAFailingGuardDenies(): void
    {
        $result = $this->evaluate('ls -la', null, kind: '', extraEnv: [GitHooks::PHP_ENV => $this->dir.'/no-such-php']);

        self::assertSame('deny', $result['effect']);
        self::assertStringContainsString(SecretGuard::FAILURE_REASON, (string) $result['message']);
    }

    /**
     * A gated run keeps its old behaviour: the guard is not run, so a command the gate does not check
     * passes even when it carries something the guard would deny.
     */
    public function testAGatedRunDoesNotRunTheGuard(): void
    {
        $token = self::fakeToken();

        $result = $this->evaluate('echo '.$token, self::fixture('stage-sequential'), kind: 'pm');

        self::assertNull($result['effect']);
    }

    /**
     * @param string|null           $stateDir the state directory of the run; null: the one of the test.
     *                                        Only a run with a gate kind carries it at all
     * @param string|null           $runId    the run id; null: `run-1` (a run with a gate kind only)
     * @param string|null           $review   the value of {@see ReviewCommitHook::ENABLED_ENV}; null: the
     *                                        variable is not in the environment at all, an empty string is
     *                                        a present flag
     * @param array<string, string> $extraEnv further variables of the run (the skip file, the SHAs file)
     *
     * @return array{effect: ?string, message: ?string}
     */
    private function evaluate(string $resources, ?string $history, string $context = 'sync', string $kind = 'pm', ?string $review = null, string $action = 'shell', ?string $stateDir = null, ?string $runId = null, array $extraEnv = []): array
    {
        $node = self::nodeBin();
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }

        $env = getenv();
        foreach ([PmGateHook::STATE_ENV, PmGateHook::KIND_ENV, PmGateHook::RUN_ENV, PmGateHook::PHP_ENV, PmGateHook::SKIP_ENV, ReviewCommitHook::ENABLED_ENV, ReviewCommitHook::SHAS_ENV, GitHooks::PHP_ENV] as $name) {
            unset($env[$name]);
        }
        // The PHP binary of the run: `GitHooks::PHP_ENV` is set on every run of both engines, the gate
        // variable (`JobRunner`) only where there is a gate kind. An `arch` run has the first one alone.
        $env[GitHooks::PHP_ENV] = PHP_BINARY;
        if ($kind !== '') {
            $env[PmGateHook::STATE_ENV] = $stateDir ?? $this->state;
            $env[PmGateHook::KIND_ENV] = $kind;
            $env[PmGateHook::RUN_ENV] = $runId ?? 'run-1';
            $env[PmGateHook::PHP_ENV] = PHP_BINARY;
        }
        // The review flag goes through the stub, not through this array: an empty value would not
        // survive `proc_open` (it drops an entry whose value is), and a present, empty flag is one of
        // the cases the shim must tell from an absent one. The stub plants it in its own environment.
        $env['STUB_REVIEW_NAME'] = ReviewCommitHook::ENABLED_ENV;
        $env['STUB_REVIEW_PRESENT'] = $review === null ? '0' : '1';
        $env['STUB_REVIEW_VALUE'] = $review ?? '';
        $env = array_merge($env, $extraEnv);
        $env['STUB_ACTION'] = $action;
        $env['STUB_COMMAND'] = $resources;
        $env['STUB_CONTEXT'] = $context;
        $env['STUB_HISTORY_FILE'] = $history ?? '';
        unset($env['STUB_HISTORY']);

        $proc = proc_open(
            [$node, $this->harness, dirname(__DIR__, 2).'/opencode-plugin/index.js'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        self::assertSame(0, $code, $err);
        $result = json_decode($out, true);
        self::assertIsArray($result, $out."\n".$err);

        return ['effect' => $result['effect'] ?? null, 'message' => $result['message'] ?? null];
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__).'/fixtures/opencode/history/'.$name.'.json';
    }

    private static function nodeBin(): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir.'/node') && is_executable($dir.'/node')) {
                return $dir.'/node';
            }
        }

        return '';
    }

    /**
     * The stub `ctx` of OpenCode 2.3.0: `permission.hook("evaluate", cb)` and a synchronous
     * `session.context({sessionID})` (or a promise, or a throw, by `STUB_CONTEXT`).
     *
     * `context()` returns the bare message array, as OpenCode does; the `{sessionID, command, messages}`
     * wrapper of the fixtures is the probe's, so the stub unwraps it here ({@see tests/fixtures/opencode/README.md}).
     */
    private static function harness(): string
    {
        return <<<'JS'
            import {readFileSync} from "node:fs";
            import {pathToFileURL} from "node:url";

            // The review flag of the run, planted before the plugin reads `process.env` (see `evaluate()`).
            if (process.env.STUB_REVIEW_PRESENT === "1") {
                process.env[process.env.STUB_REVIEW_NAME] = process.env.STUB_REVIEW_VALUE;
            }

            const plugin = (await import(pathToFileURL(process.argv[2]).href)).default;
            let hook = null;
            const ctx = {
                permission: {
                    hook(name, callback) {
                        if (name !== "evaluate") {
                            throw new Error("unexpected permission hook " + name);
                        }
                        hook = callback;
                    },
                },
                session: {
                    context() {
                        if (process.env.STUB_CONTEXT === "throw") {
                            throw new Error("session context failed");
                        }
                        if (process.env.STUB_CONTEXT === "promise-unserializable") {
                            // JSON.parse never yields a BigInt, so this history only exists where the
                            // stub builds it: resolved in a promise, it makes the shim's
                            // JSON.stringify throw inside the fulfilled callback.
                            return Promise.resolve({note: 1n});
                        }
                        if (process.env.STUB_CONTEXT === "unknown-shape") {
                            // Neither a list of messages nor an object with a `messages` list.
                            return {sessionID: "ses_test", command: "echo GATE"};
                        }
                        if (process.env.STUB_CONTEXT === "empty-object") {
                            // `context()` returning an empty object: the shim writes `{}`, which the
                            // reader must reject, not read as "no messages yet".
                            return {};
                        }
                        // The bare message array `context()` returns on real OpenCode: the wrapper of
                        // the fixture file holds it under `messages`.
                        const history = process.env.STUB_HISTORY_FILE === ""
                            ? null
                            : JSON.parse(readFileSync(process.env.STUB_HISTORY_FILE, "utf8")).messages;
                        return process.env.STUB_CONTEXT === "promise" ? Promise.resolve(history) : history;
                    },
                },
            };

            plugin.setup(ctx);
            if (typeof hook !== "function") {
                throw new Error("the plugin registered no evaluate hook");
            }

            // No `resources` key when the command is empty (or dropped by `proc_open`): that is the
            // event shape the guard must deny, an unreadable command is an unscanned command.
            const event = {action: process.env.STUB_ACTION, sessionID: "ses_test"};
            if (process.env.STUB_COMMAND !== undefined && process.env.STUB_COMMAND !== "") {
                event.resources = process.env.STUB_COMMAND;
            }
            const result = hook(event);
            if (result !== null && typeof result === "object" && typeof result.then === "function") {
                await result;
            }
            process.stdout.write(JSON.stringify({effect: event.effect ?? null, message: event.message ?? null}));
            JS;
    }
}
