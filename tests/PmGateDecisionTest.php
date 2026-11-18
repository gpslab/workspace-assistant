<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\PmGateHook;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(PmGateHook::class, 'counts')]
#[CoversMethod(PmGateHook::class, 'lastStagePositions')]
#[CoversMethod(PmGateHook::class, 'lastStageResults')]
final class PmGateDecisionTest extends TestCase
{
    /**
     * @return array{agent: string, callAt: int, resultAt: int|null, completed: bool, asyncLaunched: bool, isSidechain: bool}
     */
    private static function call(string $agent = 'critic', int $callAt = 0, ?int $resultAt = 1, bool $completed = true, bool $asyncLaunched = false, bool $isSidechain = false): array
    {
        return [
            'agent' => $agent,
            'callAt' => $callAt,
            'resultAt' => $resultAt,
            'completed' => $completed,
            'asyncLaunched' => $asyncLaunched,
            'isSidechain' => $isSidechain,
        ];
    }

    public function testCountsCompletedSynchronousMainChainCall(): void
    {
        self::assertTrue(PmGateHook::counts(self::call()));
    }

    public function testCountsRequiresCompletion(): void
    {
        self::assertFalse(PmGateHook::counts(self::call(completed: false)));
    }

    public function testCountsRejectsAsyncLaunchedCall(): void
    {
        self::assertFalse(PmGateHook::counts(self::call(completed: true, asyncLaunched: true)));
    }

    public function testCountsRejectsSidechainCall(): void
    {
        self::assertFalse(PmGateHook::counts(self::call(isSidechain: true)));
    }

    public function testLastStagePositionsMapsStagesToLastCountingCall(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [['id' => 'critique', 'agent' => 'critic', 'required' => true]];
        $calls = [
            self::call('critic', callAt: 2),
            self::call('critic', callAt: 3, completed: false),
            self::call('critic', callAt: 5, completed: true, asyncLaunched: true),
            self::call('critic', callAt: 7, isSidechain: true),
            self::call('critic', callAt: 9),
        ];

        self::assertSame(['critique' => 9], PmGateHook::lastStagePositions($calls, $stages));
    }

    public function testLastStagePositionsMapMultipleAgents(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [
            ['id' => 'critique', 'agent' => 'critic', 'required' => true],
            ['id' => 'verify', 'agent' => 'verifier', 'required' => true],
        ];
        $calls = [self::call('critic', callAt: 2), self::call('verifier', callAt: 5)];

        self::assertSame(['critique' => 2, 'verify' => 5], PmGateHook::lastStagePositions($calls, $stages));
    }

    public function testLastStagePositionsExcludeNonCountingCalls(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [['id' => 'critique', 'agent' => 'critic', 'required' => true]];
        $calls = [self::call('critic', callAt: 4, completed: true, asyncLaunched: true)];

        self::assertSame([], PmGateHook::lastStagePositions($calls, $stages));
    }

    public function testLastStageResultsMapsStagesToLastCountingResult(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [['id' => 'critique', 'agent' => 'critic', 'required' => true]];
        $calls = [
            self::call('critic', callAt: 2, resultAt: 3),
            self::call('critic', callAt: 4, resultAt: 5, completed: false),
            self::call('critic', callAt: 6, resultAt: 7, completed: true, asyncLaunched: true),
            self::call('critic', callAt: 8, resultAt: 9, isSidechain: true),
            self::call('critic', callAt: 10, resultAt: 11),
        ];

        self::assertSame(['critique' => 11], PmGateHook::lastStageResults($calls, $stages));
    }

    public function testLastStageResultsMapMultipleAgents(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [
            ['id' => 'critique', 'agent' => 'critic', 'required' => true],
            ['id' => 'verify', 'agent' => 'verifier', 'required' => true],
        ];
        $calls = [self::call('critic', callAt: 2, resultAt: 3), self::call('verifier', callAt: 5, resultAt: 6)];

        self::assertSame(['critique' => 3, 'verify' => 6], PmGateHook::lastStageResults($calls, $stages));
    }

    public function testLastStageResultsExcludeNonCountingCalls(): void
    {
        /** @var list<array{id: string, agent: string, required: bool}> $stages */
        $stages = [['id' => 'critique', 'agent' => 'critic', 'required' => true]];
        $calls = [self::call('critic', callAt: 4, resultAt: 5, completed: true, asyncLaunched: true)];

        self::assertSame([], PmGateHook::lastStageResults($calls, $stages));
    }
}
