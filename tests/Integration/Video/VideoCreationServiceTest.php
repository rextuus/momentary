<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video;

use App\Entity\Video;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use App\Service\Video\VideoCreationService;
use App\Tests\Factory\VideoFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoCreationServiceTest extends KernelTestCase
{
    private ImportQueueManager&MockObject $importQueueManager;
    private StoragePathProvider $pathProvider;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->importQueueManager = $this->createMock(ImportQueueManager::class);
        $container->set(ImportQueueManager::class, $this->importQueueManager);

        $gemini = $this->createStub(GeminiService::class);
        $gemini->method('suggestVideoName')->willReturn('Vorgeschlagener Titel');
        $container->set(GeminiService::class, $gemini);

        $this->pathProvider = $container->get(StoragePathProvider::class);
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->pathProvider->getStorageRoot() . '/videos');
        parent::tearDown();
    }

    public function testUploadedFileIsStoredInVideoDirectoryAndLinked(): void
    {
        $this->importQueueManager->expects(self::never())->method('processQueue');

        $video = new Video()->setTitle('Geburtstag');
        $result = $this->service()->createVideo($video, $this->upload('Mein Geburtstag.mp4'), null, null);

        self::assertNotNull($result->getId());
        self::assertSame(VideoStatus::PENDING, $result->getStatus());

        $file = $result->getSourceFile();
        self::assertNotNull($file);
        self::assertSame(FilePurpose::VIDEO_SOURCE, $file->getPurpose());
        self::assertMatchesRegularExpression(
            sprintf('#^videos/%d/Mein-Geburtstag-[0-9a-f]{12}\.mp4$#', $result->getId()),
            $file->getRelativePath(),
        );
        self::assertFileExists($this->pathProvider->getAbsolutePath($file));
        self::assertSame(filesize($this->pathProvider->getAbsolutePath($file)), $file->getFileSize());
    }

    public function testDuplicateTitleIsRejected(): void
    {
        VideoFactory::createOne(['title' => 'Urlaub']);
        $this->importQueueManager->expects(self::never())->method('processQueue');

        $this->expectExceptionMessage('Ein Video mit diesem Titel existiert bereits.');

        $this->service()->createVideo(new Video()->setTitle('Urlaub'), $this->upload('urlaub.mp4'), null, null);
    }

    public function testAutoProcessQueuesVideoAndSuggestsMissingTitle(): void
    {
        $this->importQueueManager->expects(self::once())->method('processQueue');

        $result = $this->service()->createVideo(new Video(), $this->upload('clip.mp4'), null, null, autoProcess: true);

        self::assertSame('Vorgeschlagener Titel', $result->getTitle());
        self::assertSame(VideoStatus::QUEUED, $result->getStatus());
    }

    private function service(): VideoCreationService
    {
        return self::getContainer()->get(VideoCreationService::class);
    }

    private function upload(string $originalName): UploadedFile
    {
        $tmp = $this->filesystem->tempnam(sys_get_temp_dir(), 'upload');
        $this->filesystem->copy(__DIR__ . '/../../fixtures/samples/people-detection.mp4', $tmp, true);

        return new UploadedFile($tmp, $originalName, 'video/mp4', test: true);
    }
}
