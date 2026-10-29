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
}
