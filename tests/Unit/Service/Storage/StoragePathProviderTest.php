<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Storage;

use App\Entity\File;
use App\Enum\FilePurpose;
use App\Service\Storage\StoragePathProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoragePathProviderTest extends TestCase
{
    public function testRelativeStorageRootIsResolvedAgainstProjectDir(): void
    {
        self::assertSame('/app/var/storage', new StoragePathProvider('/app', 'var/storage')->getStorageRoot());
    }

    public function testAbsoluteStorageRootIsUsedAsIs(): void
    {
        self::assertSame('/mnt/media', new StoragePathProvider('/app', '/mnt/media')->getStorageRoot());
    }

    public function testAbsolutePathsDoNotProduceDoubleSlashes(): void
    {
        $provider = new StoragePathProvider('/app', '/mnt/media/');
        $file = new File()->setRelativePath('/videos/1/a.mp4');

        self::assertSame('/mnt/media/videos/1/a.mp4', $provider->getAbsoluteFilePath('/videos/1/a.mp4'));
        self::assertSame('/mnt/media/videos/1/a.mp4', $provider->getAbsolutePath($file));
    }

    /**
     * @return iterable<string, array{FilePurpose, ?string, string}>
     */
    public static function relativePaths(): iterable
    {
        yield 'Quellvideo' => [FilePurpose::VIDEO_SOURCE, '7', 'videos/7/clip.mp4'];
        yield 'Konvertiertes Video' => [FilePurpose::VIDEO_CONVERTED, '7', 'videos/7/clip.mp4'];
        yield 'Video ohne Verzeichnis' => [FilePurpose::VIDEO_SOURCE, null, 'videos/0/clip.mp4'];
        yield 'Szenen-Thumbnail' => [FilePurpose::THUMBNAIL_SCENE, '7', 'images/videos/7/thumbnails/clip.mp4'];
        yield 'Thumbnail ohne Video' => [FilePurpose::THUMBNAIL_VIDEO, null, 'images/thumbnails/clip.mp4'];
        yield 'Thumbnail mit leerem Verzeichnis' => [FilePurpose::THUMBNAIL_VIDEO, '', 'images/thumbnails/clip.mp4'];
        yield 'Profilbild' => [FilePurpose::IMAGE_PROFILE, '3', 'persons/3/clip.mp4'];
        yield 'Gesicht' => [FilePurpose::IMAGE_FACE, '3', 'images/faces/3/clip.mp4'];
    }

    #[DataProvider('relativePaths')]
    public function testRelativePathPerPurpose(FilePurpose $purpose, ?string $directory, string $expected): void
    {
        self::assertSame($expected, new StoragePathProvider('/app', 'var/storage')->getRelativePath(
            $purpose,
            'clip.mp4',
            $directory,
        ));
    }

    public function testFramePathsSeparateRefinementFrames(): void
    {
        $provider = new StoragePathProvider('/app', 'var/storage');

        self::assertSame('videos/7/frames/f1.jpg', $provider->createFramePath('7', 'f1.jpg'));
        self::assertSame('videos/7/refinement_frames/f1.jpg', $provider->createFramePath(
            '7',
            'f1.jpg',
            isRefinement: true,
        ));
    }

    public function testImportPaths(): void
    {
        $provider = new StoragePathProvider('/app', 'var/storage');

        self::assertSame('imports', $provider->getImportRelativePath());
        self::assertSame('imports/a.mp4', $provider->getImportRelativePath('/a.mp4'));
        self::assertSame('/app/var/storage/imports', $provider->getImportAbsolutePath());
    }
}
