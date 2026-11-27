<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Coder\IntakeConfig;
use GpsLab\WorkspaceAssistant\Coder\Routing;
use GpsLab\WorkspaceAssistant\Coder\RoutingGuard;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Env;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Routing::class)]
#[CoversClass(IntakeConfig::class)]
#[CoversClass(RoutingGuard::class)]
#[CoversClass(Database::class)]
final class RoutingTest extends TestCase
{
    private string $dir;
    private string $envFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/coder-routing-'.uniqid('', true);
        mkdir($this->dir);
        $this->envFile = $this->dir.'/intake.env';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function config(string $env): IntakeConfig
    {
        file_put_contents($this->envFile, $env);

        return IntakeConfig::fromEnv(Env::load($this->envFile, true));
    }

    public function testRoutingIsEmptyByDefaultAndUnlistedRepositoriesAreNotInferred(): void
    {
        $config = $this->config('');

        self::assertTrue($config->routing->isEmpty());
        self::assertSame([], $config->routing->coderRepositories());
        self::assertFalse($config->routing->isCoderRouted('acme/one'));
        self::assertSame(30, $config->pollSeconds);
        self::assertSame(2, $config->tickBudgetSeconds);
        self::assertSame(4, $config->workBudget);
        self::assertSame(1, $config->requestTimeoutSeconds);
    }

    public function testOneTypedMapDefinesExclusiveExecutorsAndTheCoderAllowlist(): void
    {
        $config = $this->config(<<<'ENV'
            REPOSITORY_EXECUTORS=acme/one:coder,acme/two:openronin,ACME/three:coder
            CODER_INTAKE_AUTHOR=peter
            CODER_INTAKE_POLL_SECONDS=9
            CODER_INTAKE_TICK_BUDGET=3
            CODER_INTAKE_WORK_BUDGET=7
            CODER_INTAKE_REQUEST_TIMEOUT=2
            ENV);

        self::assertSame(['acme/one', 'acme/three'], $config->routing->coderRepositories());
        self::assertTrue($config->routing->isCoderRouted('acme/ONE'));
        self::assertFalse($config->routing->isCoderRouted('acme/two'));
        self::assertFalse($config->routing->isCoderRouted('acme/unlisted'));
        self::assertSame('peter', $config->trustedAuthor);
        self::assertSame([9, 3, 7, 2], [$config->pollSeconds, $config->tickBudgetSeconds, $config->workBudget, $config->requestTimeoutSeconds]);
    }

    public function testDuplicateAndConflictingAssignmentsAreRejectedEvenAcrossCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate/conflicting');
        $this->config("REPOSITORY_EXECUTORS=acme/one:coder,ACME/one:openronin\nCODER_INTAKE_AUTHOR=peter");
    }

    public function testUnknownExecutorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown executor 'both'");
        $this->config("REPOSITORY_EXECUTORS=acme/one:both\nCODER_INTAKE_AUTHOR=peter");
    }

    public function testCoderRoutingRequiresTheSeparateTrustedHumanLogin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CODER_INTAKE_AUTHOR is required');
        $this->config('REPOSITORY_EXECUTORS=acme/one:coder');
    }

    public function testRequestTimeoutCannotOutliveTheTickBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');
        new IntakeConfig(new Routing(), '', 30, 2, 4, 3);
    }

    public function testRoutingIsRejectedForRunningEpicsButAllowedWhenPausedBlockedOrDone(): void
    {
        $db = new Database($this->dir);
        $db->epicCreate('active', 1, 2, [['task' => 'acme/other#1', 'after' => []]], [
            ['repo' => 'acme/one', 'version' => '1.0.0', 'after' => []],
        ]);
        $guard = new RoutingGuard(new Routing(['acme/one' => 'coder']), $db, new ForgeOwners(['acme']));

        $this->assertRoutingRejected($guard, 'running');
        $db->epicSetStatus('active', 'paused');
        $guard->assertRoutingAllowed();
        $db->epicSetStatus('active', 'blocked');
        $guard->assertRoutingAllowed();
        $db->epicSetStatus('active', 'done');
        $guard->assertRoutingAllowed();

        self::assertSame('done', $db->epicGet('active')['status'] ?? null);
    }

    public function testNewPlansForCoderRepositoriesFailClearlyAndOtherPlansPass(): void
    {
        $guard = new RoutingGuard(
            new Routing(['acme/one' => 'coder', 'acme/two' => 'openronin']),
            new Database($this->dir),
            new ForgeOwners(['acme']),
        );

        try {
            $guard->assertPlanAllowed([['task' => 'acme/one#8', 'after' => []]], []);
            self::fail('A coder-routed repository must not enter an epic plan.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('routed to the standalone coder', $e->getMessage());
            self::assertStringContainsString('acme/one', $e->getMessage());
        }

        $guard->assertPlanAllowed(
            [['task' => 'acme/two#8', 'after' => []], ['task' => 'acme/unlisted#9', 'after' => []]],
            [],
        );
        self::assertNull($guard->coderRepository('acme/two#8'));
        self::assertSame('acme/one', $guard->coderRepository('acme/one#8'));
    }

    private function assertRoutingRejected(RoutingGuard $guard, string $status): void
    {
        try {
            $guard->assertRoutingAllowed();
            self::fail("Routing must reject a {$status} epic.");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("epic active is {$status}", $e->getMessage());
            self::assertStringContainsString('pause or complete it first', $e->getMessage());
        }
    }
}
