<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\IncomingFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncomingFile::class)]
final class IncomingFileTest extends TestCase
{
    public function testExtractsLargestPhotoSize(): void
    {
        $message = [
            'photo' => [
                ['file_id' => 'small', 'width' => 90, 'height' => 90],
                ['file_id' => 'large', 'width' => 1280, 'height' => 960, 'file_size' => 1234],
                ['file_id' => 'medium', 'width' => 320, 'height' => 240],
            ],
        ];

        self::assertSame(
            ['file_id' => 'large', 'extension' => 'jpg', 'name' => null, 'file_size' => 1234],
            IncomingFile::extractFrom($message),
        );
    }

    public function testExtractsImageDocumentExtensionFromMimeNotFileName(): void
    {
        $file = IncomingFile::extractFrom(['document' => [
            'file_id' => 'doc123',
            'mime_type' => 'image/png',
            'file_name' => 'photo.JPG',
        ]]);

        self::assertNotNull($file);
        self::assertSame('png', $file['extension']);
        self::assertSame('abc-photo.png', IncomingFile::diskName($file, 'abc'));
    }

    public function testDropsImageExtensionFromNameWhenMimeIsNotImage(): void
    {
        foreach (['application/pdf', 'application/octet-stream', 'text/plain'] as $mime) {
            $file = IncomingFile::extractFrom(['document' => [
                'file_id' => 'x',
                'mime_type' => $mime,
                'file_name' => 'fake.png',
            ]]);

            self::assertNotNull($file);
            self::assertNull($file['extension']);
            self::assertSame('abc-fake', IncomingFile::diskName($file, 'abc'));
        }
    }

    public function testAcceptsNonImageDocument(): void
    {
        $file = IncomingFile::extractFrom(['document' => [
            'file_id' => 'd1',
            'mime_type' => 'application/octet-stream',
            'file_name' => 'Config.YAML',
            'file_size' => 10,
        ]]);

        self::assertSame(
            ['file_id' => 'd1', 'extension' => 'yaml', 'name' => 'Config.YAML', 'file_size' => 10],
            $file,
        );
        self::assertSame('abc-Config.yaml', IncomingFile::diskName($file, 'abc'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function typedMediaProvider(): array
    {
        return [
            'audio' => [['audio' => ['file_id' => 'x', 'mime_type' => 'audio/mpeg']], 'mp3'],
            'audio named' => [['audio' => ['file_id' => 'x', 'file_name' => 'song.flac']], 'flac'],
            'video' => [['video' => ['file_id' => 'x', 'mime_type' => 'video/mp4']], 'mp4'],
            'voice' => [['voice' => ['file_id' => 'x']], 'ogg'],
            'video_note' => [['video_note' => ['file_id' => 'x']], 'mp4'],
            'animation' => [['animation' => ['file_id' => 'x', 'mime_type' => 'video/mp4']], 'mp4'],
            'voice audio/ogg' => [['voice' => ['file_id' => 'x', 'mime_type' => 'audio/ogg']], 'ogg'],
            'unknown' => [['document' => ['file_id' => 'x', 'mime_type' => 'application/x-foo']], null],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('typedMediaProvider')]
    public function testExtractsEachMediaTypeWithExtension(array $message, ?string $extension): void
    {
        $file = IncomingFile::extractFrom($message);

        self::assertNotNull($file);
        self::assertSame('x', $file['file_id']);
        self::assertSame($extension, $file['extension']);
    }

    public function testAnimationDuplicatedInDocumentYieldsSingleFile(): void
    {
        $file = IncomingFile::extractFrom([
            'animation' => ['file_id' => 'same', 'mime_type' => 'video/mp4', 'file_name' => 'a.mp4'],
            'document' => ['file_id' => 'same', 'mime_type' => 'video/mp4', 'file_name' => 'a.mp4'],
        ]);

        self::assertSame('same', $file['file_id'] ?? null);
    }

    public function testStickerIsNotAFile(): void
    {
        $message = ['sticker' => ['file_id' => 's1']];

        self::assertNull(IncomingFile::extractFrom($message));
        self::assertTrue(IncomingFile::isSticker($message));
    }

    public function testDiskNameStripsPathTraversal(): void
    {
        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => '../../etc/pass wd.txt']]);

        self::assertNotNull($file);
        self::assertSame('abc-pass_wd.txt', IncomingFile::diskName($file, 'abc'));

        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => '..\\..\\evil.sh']]);
        self::assertNotNull($file);
        self::assertSame('abc-evil.sh', IncomingFile::diskName($file, 'abc'));
    }

    public function testDiskNameTruncatesBaseButKeepsExtension(): void
    {
        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => str_repeat('a', 200).'.PDF']]);

        self::assertNotNull($file);
        self::assertSame('abc-'.str_repeat('a', 80).'.pdf', IncomingFile::diskName($file, 'abc'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function degenerateNameProvider(): array
    {
        return [
            'dotdot' => ['..', 'abc'],
            'dots' => ['...', 'abc'],
            'dotenv' => ['.env', 'abc.env'],
            // Deliberately Cyrillic: a file name made only of non-ASCII characters
            'non-ascii only' => ['файл', 'abc-'.str_repeat('_', 8)],
        ];
    }

    #[DataProvider('degenerateNameProvider')]
    public function testDiskNameWithEmptyBaseDropsName(string $fileName, string $expected): void
    {
        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => $fileName]]);

        self::assertNotNull($file);
        self::assertSame($expected, IncomingFile::diskName($file, 'abc'));
    }

    public function testDiskNameWithoutExtensionHasNoTrailingDot(): void
    {
        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => 'Makefile']]);
        self::assertNotNull($file);
        self::assertSame('abc-Makefile', IncomingFile::diskName($file, 'abc'));

        $file = IncomingFile::extractFrom(['document' => ['file_id' => 'x', 'file_name' => 'notes', 'mime_type' => 'audio/mpeg']]);
        self::assertNotNull($file);
        self::assertSame('abc-notes.mp3', IncomingFile::diskName($file, 'abc'));
    }

    public function testDiskNameWithoutNameUsesExtensionOnly(): void
    {
        $file = IncomingFile::extractFrom(['voice' => ['file_id' => 'x']]);
        self::assertNotNull($file);
        self::assertSame('abc.ogg', IncomingFile::diskName($file, 'abc'));
    }

    public function testRejectsMessageWithoutPhotoOrDocument(): void
    {
        self::assertNull(IncomingFile::extractFrom(['text' => 'hello']));
    }

    public function testRejectsEmptyPhotoArray(): void
    {
        self::assertNull(IncomingFile::extractFrom(['photo' => []]));
    }

    public function testMediaGroupIdReturnsNullWhenAbsent(): void
    {
        self::assertNull(IncomingFile::mediaGroupId(['text' => 'hi']));
    }

    public function testMediaGroupIdReturnsValueWhenPresent(): void
    {
        self::assertSame('12345', IncomingFile::mediaGroupId(['media_group_id' => '12345']));
    }

    public function testCaptionOfTrimsAndNormalizesEmptyToNull(): void
    {
        self::assertSame('look at this', IncomingFile::captionOf(['caption' => '  look at this  ']));
        self::assertNull(IncomingFile::captionOf(['caption' => '   ']));
        self::assertNull(IncomingFile::captionOf([]));
    }
}
