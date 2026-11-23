<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Kimi\KimiGateGuard;
use GpsLab\WorkspaceAssistant\Kimi\KimiGateSelfTest;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The refusal predicate of the gated modes and the epics on Kimi: where its three inputs come from,
 * the text each failing condition is refused with, and the one that is not a refusal at all.
 *
 * The inputs are read from the installation: `$HOME/.agents`, the `metadata.protocol_version` of the
 * newest session on the source home, and the self-test of the gate shim. A test gives each of them a
 * home of its own, so none of them depends on the machine the suite runs on.
 */
#[CoversClass(KimiGateGuard::class)]
#[CoversClass(KimiGateSelfTest::class)]
final class KimiGateGuardTest extends TestCase
{
    private string $root;
    private string $home;
    private string $kimiHome;
    private string $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/kimi-gate-guard-'.uniqid('', true);
        $this->home = $this->root.'/home';
        $this->kimiHome = $this->root.'/kimi-home';
        $this->state = $this->root.'/state';
        foreach ([$this->home, $this->kimiHome.'/sessions', $this->state] as $dir) {
            mkdir($dir, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    /** The refusal is Kimi's alone: every other engine runs the gates it has. */
    public function testNoEngineButKimiIsRefused(): void
    {
        foreach ([Config::DEFAULT_ENGINE_FINGERPRINT, Config::ENGINE_OPENCODE] as $engine) {
            $guard = $this->guard($engine);

            self::assertNull($guard->refusal());
            self::assertNull($guard->refusalLog());
        }
    }

    /** The preconditions hold: nothing is refused, and the run starts. */
    public function testNothingIsRefusedWhileThePreconditionsHold(): void
    {
        $this->session('1.5');

        $guard = $this->guard();

        self::assertNull($guard->refusal());
        self::assertNull($guard->refusalLog());
    }

    /**
     * A home with `.agents` is refused with its own text: Kimi reads agents, skills and `AGENTS.md`
     * from it even with a relocated run home.
     */
    public function testTheHomeAgentsDirectoryRefusesWithItsReason(): void
    {
        mkdir($this->home.'/.agents', 0o700);
        $this->session('1.5');

        $guard = $this->guard();

        self::assertSame($this->text('gate.kimi_home_agents'), $guard->refusal());
        self::assertStringContainsString('.agents', (string) $guard->refusalLog());
    }

    /** An unknown protocol version is refused with the version the sessions carry. */
    public function testAnUnknownProtocolVersionRefusesWithTheVersion(): void
    {
        $this->session('9.9');

        $guard = $this->guard();

        self::assertSame($this->text('gate.kimi_protocol_unknown', ['version' => '9.9']), $guard->refusal());
        self::assertSame('the wire protocol version 9.9 is unknown to the bot', $guard->refusalLog());
    }

    /** The version of a session the engine wrote is read from the newest one on the source home. */
    public function testTheProtocolVersionComesFromTheNewestSession(): void
    {
        $this->session('9.9', 'session_11111111-1111-1111-1111-111111111111', time() - 3600);
        $this->session('1.5', 'session_22222222-2222-2222-2222-222222222222');

        self::assertNull($this->guard()->refusal());
    }

    /** A home with no session at all has no version to distrust: the format is unread, not changed. */
    public function testASourceHomeWithoutASessionIsNotRefused(): void
    {
        self::assertNull($this->guard()->refusal());
    }

    /** A shim that fails its self-test is refused with the failure it printed. */
    public function testASelfTestFailureRefusesWithItsText(): void
    {
        $this->session('1.5');
        $shim = $this->root.'/broken-shim.php';
        file_put_contents($shim, "<?php\nfwrite(STDERR, 'the run home is not writable');\nexit(1);\n");

        $guard = $this->guard(selfTestShim: $shim);

        self::assertSame(
            $this->text('gate.kimi_self_test_failed', ['detail' => 'Kimi gate self-test failed: the run home is not writable']),
            $guard->refusal(),
        );
        self::assertStringContainsString('the run home is not writable', (string) $guard->refusalLog());
    }

    /** A shim that cannot be started is a failure of the self-test, not a pass. */
    public function testAMissingShimRefuses(): void
    {
        $this->session('1.5');

        $guard = $this->guard(selfTestShim: $this->root.'/no-such-shim.php');

        self::assertStringContainsString('is not installed', (string) $guard->refusalLog());
    }

    /** Every failing condition is named, in the order the predicate checks them. */
    public function testEveryFailingConditionIsNamed(): void
    {
        mkdir($this->home.'/.agents', 0o700);
        $this->session('9.9');
        $shim = $this->root.'/broken-shim.php';
        file_put_contents($shim, "<?php\nexit(1);\n");

        $guard = $this->guard(selfTestShim: $shim);

        $refusal = (string) $guard->refusal();
        self::assertStringContainsString($this->text('gate.kimi_home_agents'), $refusal);
        self::assertStringContainsString($this->text('gate.kimi_protocol_unknown', ['version' => '9.9']), $refusal);
        self::assertStringContainsString('the gate shim fails its self-test: Kimi gate self-test failed (exit 1)', $refusal);
    }

    /**
     * The self-test belongs to the installation and is taken once per process: a shim that is probed
     * twice for two messages of the same bot does not spawn twice.
     */
    public function testTheSelfTestIsTakenOncePerProcess(): void
    {
        $this->session('1.5');
        $calls = $this->root.'/self-test.calls';
        $shim = $this->root.'/counting-shim.php';
        file_put_contents($shim, '<?php'."\nfile_put_contents(".var_export($calls, true).", \"x\\n\", FILE_APPEND);\nexit(0);\n");

        $guard = $this->guard(selfTestShim: $shim);
        self::assertNull($guard->refusal());
        self::assertNull($guard->refusal());
        self::assertNull($guard->refusalLog());

        self::assertSame("x\n", (string) file_get_contents($calls));
    }

    /** `$HOME/.agents` is read on every call: removing it while the bot runs lifts the refusal. */
    public function testTheHomeAgentsDirectoryIsReadOnEveryCall(): void
    {
        $this->session('1.5');
        mkdir($this->home.'/.agents', 0o700);
        $guard = $this->guard();
        self::assertNotNull($guard->refusal());

        rmdir($this->home.'/.agents');

        self::assertNull($guard->refusal());
    }

    /**
     * The text of one catalog key of the locale the bot runs in.
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, array $params = []): string
    {
        return Locale::fromConfig($this->config())->t($key, $params);
    }

    /**
     * @param string|null $selfTestShim the shim to self-test; null = the one of this installation
     */
    private function guard(string $engine = Config::ENGINE_KIMI, ?string $selfTestShim = null): KimiGateGuard
    {
        $config = $this->config($engine);

        return new KimiGateGuard($config, Locale::fromConfig($config), $this->home, $selfTestShim);
    }

    private function config(string $engine = Config::ENGINE_KIMI): Config
    {
        return new Config(
            botToken: 't',
            allowedUserIds: [1],
            repoPath: $this->root,
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 0,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->state,
            outboxDir: $this->root,
            inboxDir: $this->root,
            pollTimeout: 30,
            gitTimeout: 30,
            engine: $engine,
            kimiHome: $this->kimiHome,
        );
    }

    /** A session of the source home whose `wire.jsonl` carries the given protocol version. */
    private function session(string $protocolVersion, string $id = 'session_00000000-0000-0000-0000-000000000000', ?int $at = null): void
    {
        $dir = $this->kimiHome.'/sessions/wd_test/'.$id.'/agents/main';
        mkdir($dir, 0o700, true);
        $wire = $dir.'/wire.jsonl';
        file_put_contents($wire, json_encode(['type' => 'metadata', 'protocol_version' => $protocolVersion, 'created_at' => ($at ?? time()) * 1000])."\n");
        if ($at !== null) {
            touch($wire, $at);
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rrmdir($dir.'/'.$entry);
        }
        @rmdir($dir);
    }
}
