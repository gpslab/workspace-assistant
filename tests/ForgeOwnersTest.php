<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\ForgeOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForgeOwners::class)]
final class ForgeOwnersTest extends TestCase
{
    public function testParsesFullTask(): void
    {
        self::assertSame(['gpslab', 'lib', 12], (new ForgeOwners(['anime-db', 'gpslab']))->parse('gpslab/lib#12'));
    }

    public function testShortTaskGetsFirstOwner(): void
    {
        self::assertSame(['anime-db', 'lib', 12], (new ForgeOwners(['anime-db', 'gpslab']))->parse('lib#12'));
    }

    public function testShortAndDefaultOwnerFormsAreOneKey(): void
    {
        $owners = new ForgeOwners(['anime-db', 'gpslab']);

        self::assertSame('lib', $owners->repoKey('lib#1'));
        self::assertSame('lib', $owners->repoKey('anime-db/lib#2'));
        self::assertSame('lib', $owners->repoKey('ANIME-DB/lib'));
        self::assertSame('gpslab/lib', $owners->repoKey('gpslab/lib#1'));
        self::assertSame('anime-db/lib', $owners->slug('lib#1'));
        self::assertSame('lib#1', $owners->canonical('anime-db/lib#1'));
    }

    public function testListIsTrimmedAndEmptyItemsSkipped(): void
    {
        self::assertSame(['a', 'b'], (new ForgeOwners([' a ', '', 'b']))->all());
    }

    public function testUnsetOwnersGiveExplicitError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ForgeOwners::NOT_SET);

        (new ForgeOwners())->parse('lib#1');
    }

    public function testRejectsMalformedReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ForgeOwners(['a']))->parse('lib#0');
    }

    public function testGitlabNamespaceOwnerIsSplitAtTheLastSlash(): void
    {
        $owners = new ForgeOwners(['top', 'group/sub'], true);

        self::assertSame(['group/sub', 'proj', 12], $owners->parse('group/sub/proj#12'));
        self::assertSame(['top', 'proj', 12], $owners->parse('proj#12'));
        self::assertSame('proj#12', $owners->canonical('top/proj#12'));
        self::assertSame('group/sub/proj', $owners->repoKey('group/sub/proj#1'));
        self::assertTrue($owners->isNested());
    }

    public function testGithubRejectsNamespaceOwner(): void
    {
        $owners = new ForgeOwners(['group/sub']);

        self::assertFalse($owners->isNested());
        $this->expectException(\InvalidArgumentException::class);
        $owners->parse('group/sub/proj#12');
    }

    public function testGitlabStillRejectsUnlistedNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not in FORGE_OWNERS');

        (new ForgeOwners(['group/sub'], true))->parse('other/sub/proj#1');
    }
}
