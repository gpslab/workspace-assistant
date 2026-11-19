<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Epic\Signal;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use PHPUnit\Framework\TestCase;

final class SignalTest extends TestCase
{
    public function testDefaultOwnerFormsAreNormalizedToShortOnes(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $s = Signal::parse('{"added":[{"task":"anime-db/p#12","after":["anime-db/x#1","gpslab/y#2"]}],"hold":["ANIME-DB/p#12"]}', $owners);

        self::assertSame([['task' => 'p#12', 'after' => ['x#1', 'gpslab/y#2']]], $s->added);
        self::assertSame(['p#12'], $s->hold);
    }

    public function testForeignOwnerIsRejected(): void
    {
        $owners = new ForgeOwners(['anime-db']);

        foreach ([
            '{"added":[{"task":"foreign/r#1"}]}',
            '{"added":[{"task":"r#1","after":["foreign/r#2"]}]}',
            '{"hold":["foreign/r#1"]}',
        ] as $json) {
            try {
                Signal::parse($json, $owners);
                self::fail('foreign owner must be rejected: '.$json);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('FORGE_OWNERS', $e->getMessage());
            }
        }
    }

    public function testWithoutOwnersFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ForgeOwners::NOT_SET);

        Signal::parse('{}', new ForgeOwners());
    }

    public function testShortRefIsResolvedToTheOwnerOfTheEpicTask(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $s = Signal::parse('{"added":[{"task":"workspace-assistant#157"}]}', $owners, ['gpslab/workspace-assistant#157']);

        self::assertSame([['task' => 'gpslab/workspace-assistant#157', 'after' => []]], $s->added);
    }

    public function testShortRefOfAnUnknownRepositoryKeepsTheDefaultOwner(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $s = Signal::parse('{"added":[{"task":"brand-new#5"}]}', $owners, ['gpslab/workspace-assistant#157']);

        self::assertSame([['task' => 'brand-new#5', 'after' => []]], $s->added);
    }

    public function testKnownShortRefStaysAsStored(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        // The epic holds both forms; the short one is not rewritten, because task identity is the string.
        $s = Signal::parse('{"hold":["workspace-assistant#157"]}', $owners, ['workspace-assistant#157', 'gpslab/workspace-assistant#157']);

        self::assertSame(['workspace-assistant#157'], $s->hold);
    }

    public function testExplicitDefaultOwnerRefIsNotResolved(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        // The epic holds the task of another owner, but the explicit default-owner form is kept as written.
        $s = Signal::parse('{"added":[{"task":"anime-db/workspace-assistant#157"}]}', $owners, ['gpslab/workspace-assistant#157']);

        self::assertSame([['task' => 'workspace-assistant#157', 'after' => []]], $s->added);
    }

    public function testAmbiguousShortRefIsRejectedAskingForTheFullForm(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('write the full form owner/repo#N');

        Signal::parse('{"added":[{"task":"x#3"}]}', $owners, ['anime-db/x#1', 'gpslab/x#2']);
    }

    public function testShortRefsInHoldAndAfterResolveToTheEpicOwner(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $s = Signal::parse(
            '{"added":[{"task":"gpslab/new#1","after":["workspace-assistant#157"]}],"hold":["workspace-assistant#157"]}',
            $owners,
            ['gpslab/workspace-assistant#157'],
        );

        self::assertSame([['task' => 'gpslab/new#1', 'after' => ['gpslab/workspace-assistant#157']]], $s->added);
        self::assertSame(['gpslab/workspace-assistant#157'], $s->hold);
    }

    public function testStoredTaskOfAnUnknownOwnerIsNotACandidate(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        $s = Signal::parse('{"added":[{"task":"workspace-assistant#157"}]}', $owners, ['gone/workspace-assistant#1', 'gpslab/workspace-assistant#2']);

        self::assertSame([['task' => 'gpslab/workspace-assistant#157', 'after' => []]], $s->added);
    }
}
