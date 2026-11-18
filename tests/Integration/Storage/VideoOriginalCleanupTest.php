<?php

declare(strict_types=1);

namespace App\Tests\Integration\Storage;

use App\Entity\File;
use App\Entity\Video;
use App\Enum\FilePurpose;
use App\Service\Storage\StoragePathProvider;
use App\Service\Storage\VideoOriginalCleanupService;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Interlaced Originale bleiben nach der Verarbeitung liegen: die MP4 ist deinterlacet, eine spätere KI-Aufbereitung
 * braucht die echten Halbbilder.
 */
final class VideoOriginalCleanupTest extends KernelTestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->created);
        parent::tearDown();
    }

    public function testInterlacedOriginalIsKept(): void
    {
        $video = $this->completedVideo('tt');

        self::assertSame(0, $this->cleanup()->cleanupOriginal($video));

        self::assertFileExists($this->path($video->getSourceFile()));
        self::assertNotNull($video->getConvertedFile(), 'nichts umgehängt');
    }

    public function testInterlacedOriginalIsDeletedOnlyWhenExplicitlyRequested(): void
    {
        $video = $this->completedVideo('bb');
        $original = $this->path($video->getSourceFile());

        self::assertGreaterThan(0, $this->cleanup()->cleanupOriginal($video, includeInterlaced: true));

        self::assertFileDoesNotExist($original);
        self::assertNull($video->getConvertedFile());
        self::assertSame('bb', $video->getFieldOrder(), 'bleibt als Information für die KI-Aufbereitung');
    }

    public function testProgressiveOriginalIsDeletedAsBefore(): void
    {
        $video = $this->completedVideo('progressive');
        $original = $this->path($video->getSourceFile());

        self::assertGreaterThan(0, $this->cleanup()->cleanupOriginal($video));
        self::assertFileDoesNotExist($original);
    }

    private function cleanup(): VideoOriginalCleanupService
    {
        return self::getContainer()->get(VideoOriginalCleanupService::class);
    }

    private function completedVideo(string $fieldOrder): Video
    {
        $video = VideoFactory::new()->completed()->create();
        $video->setFieldOrder($fieldOrder);
        $video->setJellyfinPath('/uploads/video.mp4');
        $video->setSourceFile($this->file($video, 'original.dv', FilePurpose::VIDEO_SOURCE));
        $video->setConvertedFile($this->file($video, 'converted.mp4', FilePurpose::VIDEO_CONVERTED));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $video;
    }

    private function file(Video $video, string $name, FilePurpose $purpose): File
    {
        $file = new File();
        $file->setRelativePath(sprintf('videos/%d/%s', (int) $video->getId(), $name));
        $file->setMimeType('video/mp4');
        $file->setPurpose($purpose);
        $file->setFileSize(4);
        self::getContainer()->get(EntityManagerInterface::class)->persist($file);
        new Filesystem()->dumpFile($this->path($file), 'data');
        $this->created[] = \dirname($this->path($file));

        return $file;
    }

    private function path(?File $file): string
    {
        self::assertNotNull($file);

        return self::getContainer()->get(StoragePathProvider::class)->getAbsolutePath($file);
    }
}
