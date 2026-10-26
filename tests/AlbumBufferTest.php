<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\AlbumBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlbumBuffer::class)]
final class AlbumBufferTest extends TestCase
{
    private const array IMAGE_A = ['file_id' => 'a', 'extension' => 'jpg', 'name' => null, 'file_size' => null];
    private const array IMAGE_B = ['file_id' => 'b', 'extension' => 'jpg', 'name' => null, 'file_size' => null];

    public function testGroupNotReadyBeforeDebounceElapses(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);

        self::assertSame([], $buffer->collectReady(1001, 1.5));
    }

    public function testGroupReadyAfterDebounceElapses(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, 'look', self::IMAGE_A, 1000);

        $ready = $buffer->collectReady(1002, 1.5);

        self::assertCount(1, $ready);
        self::assertSame(100, $ready[0]['chat_id']);
        self::assertSame(0, $ready[0]['thread_id']);
        self::assertSame('look', $ready[0]['caption']);
        self::assertSame([self::IMAGE_A], $ready[0]['files']);
    }

    public function testCollectedGroupIsRemovedAndNotReturnedTwice(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);

        self::assertCount(1, $buffer->collectReady(1002, 1.5));
        self::assertSame([], $buffer->collectReady(2000, 1.5));
    }

    public function testMultipleFramesAccumulateIntoOneGroup(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);
        $buffer->add('g1', 100, 0, null, self::IMAGE_B, 1000);

        $ready = $buffer->collectReady(1002, 1.5);

        self::assertCount(1, $ready);
        self::assertSame([self::IMAGE_A, self::IMAGE_B], $ready[0]['files']);
    }

    public function testEachNewFrameResetsDebounceWindow(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);
        // The second frame arrived almost 1.5s after the first, but the group is not ready yet —
        // the debounce window is counted from the LAST frame, not the first.
        $buffer->add('g1', 100, 0, null, self::IMAGE_B, 1001);

        self::assertSame([], $buffer->collectReady(1002, 1.5));

        $ready = $buffer->collectReady(1003, 1.5);
        self::assertCount(1, $ready);
        self::assertCount(2, $ready[0]['files']);
    }

    public function testCaptionFromAnyFrameInGroupIsKept(): void
    {
        $buffer = new AlbumBuffer();
        // Telegram usually puts the caption on one of the frames, not necessarily the first.
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);
        $buffer->add('g1', 100, 0, 'caption here', self::IMAGE_B, 1000);

        $ready = $buffer->collectReady(1002, 1.5);

        self::assertSame('caption here', $ready[0]['caption']);
    }

    public function testDistinctGroupsAreIndependent(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);
        $buffer->add('g2', 200, 5, null, self::IMAGE_B, 1000);

        $ready = $buffer->collectReady(1002, 1.5);

        self::assertCount(2, $ready);
    }

    public function testIsEmptyReflectsBufferState(): void
    {
        $buffer = new AlbumBuffer();
        self::assertTrue($buffer->isEmpty());

        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000);
        self::assertFalse($buffer->isEmpty());

        $buffer->collectReady(1002, 1.5);
        self::assertTrue($buffer->isEmpty());
    }

    public function testMessageIdsAreCollectedInOrder(): void
    {
        $buffer = new AlbumBuffer();
        $buffer->add('g1', 100, 0, null, self::IMAGE_A, 1000, 7);
        $buffer->add('g1', 100, 0, 'caption', self::IMAGE_B, 1000, 8);

        $ready = $buffer->collectReady(1002, 1.5);

        self::assertSame([7, 8], $ready[0]['message_ids']);
    }
}
