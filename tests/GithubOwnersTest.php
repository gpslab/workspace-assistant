<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\GithubOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GithubOwners::class)]
final class GithubOwnersTest extends TestCase
{
    public function testParsesFullTask(): void
    {
        self::assertSame(['gpslab', 'lib', 12], (new GithubOwners(['anime-db', 'gpslab']))->parse('gpslab/lib#12'));
    }

    public function testShortTaskGetsFirstOwner(): void
    {
        self::assertSame(['anime-db', 'lib', 12], (new GithubOwners(['anime-db', 'gpslab']))->parse('lib#12'));
    }

    public function testShortAndDefaultOwnerFormsAreOneKey(): void
    {
        $owners = new GithubOwners(['anime-db', 'gpslab']);

        self::assertSame('lib', $owners->repoKey('lib#1'));
        self::assertSame('lib', $owners->repoKey('anime-db/lib#2'));
        self::assertSame('lib', $owners->repoKey('ANIME-DB/lib'));
        self::assertSame('gpslab/lib', $owners->repoKey('gpslab/lib#1'));
        self::assertSame('anime-db/lib', $owners->slug('lib#1'));
        self::assertSame('lib#1', $owners->canonical('anime-db/lib#1'));
    }

    public function testListIsTrimmedAndEmptyItemsSkipped(): void
    {
        self::assertSame(['a', 'b'], (new GithubOwners([' a ', '', 'b']))->all());
    }

    public function testUnsetOwnersGiveExplicitError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GithubOwners::NOT_SET);

        (new GithubOwners())->parse('lib#1');
    }

    public function testRejectsMalformedReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GithubOwners(['a']))->parse('lib#0');
    }
}
