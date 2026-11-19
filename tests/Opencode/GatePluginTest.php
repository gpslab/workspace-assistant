<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Opencode;

use GpsLab\WorkspaceAssistant\PmGateHook;
use GpsLab\WorkspaceAssistant\ReviewCommitHook;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The OpenCode gate shim (`opencode-plugin`) under bare `node` with a stub `ctx`.
 *
 * The shim holds no gate logic: it starts the PHP hooks of the bot. These tests pin the wiring the
 * bot relies on — the deny translation, the fail-closed paths and the engine-neutral input — not the
 * gate rules, which {@see \GpsLab\WorkspaceAssistant\Tests\PmGateHookTest} and
 * {@see \GpsLab\WorkspaceAssistant\Tests\ReviewCommitHookTest} cover through PHP directly.
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
        @rmdir($this->dir);
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

    public function testWithoutTheGateEnvironmentNothingIsChecked(): void
    {
        // An arch run: no gate kind, no snapshot, a command the PM gate would block.
        $result = $this->evaluate(self::ISSUE_CREATE, self::fixture('stage-single'), gate: false);

        self::assertNull($result['effect']);
        self::assertNull($result['message']);
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
        $review = $this->evaluate(self::APPROVE, self::fixture('stage-single'), kind: 'review', review: true);
        $pm = $this->evaluate(self::APPROVE, self::fixture('stage-single'), kind: 'pm', review: false);

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
     * @return array{effect: ?string, message: ?string}
     */
    private function evaluate(string $resources, ?string $history, bool $gate = true, string $context = 'sync', string $kind = 'pm', bool $review = false, string $action = 'shell'): array
    {
        $node = self::nodeBin();
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }

        $env = getenv();
        foreach ([PmGateHook::STATE_ENV, PmGateHook::KIND_ENV, PmGateHook::RUN_ENV, PmGateHook::PHP_ENV, ReviewCommitHook::ENABLED_ENV] as $name) {
            unset($env[$name]);
        }
        if ($gate) {
            $env[PmGateHook::STATE_ENV] = $this->state;
            $env[PmGateHook::KIND_ENV] = $kind;
            $env[PmGateHook::RUN_ENV] = 'run-1';
            $env[PmGateHook::PHP_ENV] = PHP_BINARY;
        }
        if ($review) {
            $env[ReviewCommitHook::ENABLED_ENV] = '1';
        }
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
     */
    private static function harness(): string
    {
        return <<<'JS'
            import {readFileSync} from "node:fs";
            import {pathToFileURL} from "node:url";

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
                        const history = process.env.STUB_HISTORY_FILE === ""
                            ? null
                            : JSON.parse(readFileSync(process.env.STUB_HISTORY_FILE, "utf8"));
                        return process.env.STUB_CONTEXT === "promise" ? Promise.resolve(history) : history;
                    },
                },
            };

            plugin.setup(ctx);
            if (typeof hook !== "function") {
                throw new Error("the plugin registered no evaluate hook");
            }

            const event = {action: process.env.STUB_ACTION, resources: process.env.STUB_COMMAND, sessionID: "ses_test"};
            const result = hook(event);
            if (result !== null && typeof result === "object" && typeof result.then === "function") {
                await result;
            }
            process.stdout.write(JSON.stringify({effect: event.effect ?? null, message: event.message ?? null}));
            JS;
    }
}
