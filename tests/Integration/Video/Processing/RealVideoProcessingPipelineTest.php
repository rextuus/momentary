<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\Message\SceneDetectionStepMessage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lässt die Pipeline mit echtem ffmpeg und PySceneDetect über die Beispielvideos laufen.
 * Nur die externen APIs (Rekognition, Gemini, Meilisearch) sind ersetzt.
 *
 * Läuft nicht im Standard-Lauf. Im Worker-Container sind alle Tools vorhanden:
 *   docker compose exec messenger-worker vendor/bin/phpunit --group slow
 */
#[Group('slow')]
final class RealVideoProcessingPipelineTest extends VideoPipelineTestCase
{
    private const string SAMPLES_DIR = __DIR__ . '/../../../fixtures/samples';

    private AmazonRekognitionService&Stub $rekognitionService;
    private GeminiService&Stub $geminiService;
    private EntityManagerInterface $entityManager;
    private StoragePathProvider $pathProvider;

    protected function setUp(): void
    {
        self::skipUnlessVideoToolsAreInstalled();

        parent::setUp();
        $container = static::getContainer();

        $this->rekognitionService = $this->createStub(AmazonRekognitionService::class);
        $this->geminiService = $this->createStub(GeminiService::class);

        $meilisearch = $this->createStub(Client::class);
        $meilisearch->method('index')->willReturn($this->createStub(Indexes::class));

        $thumbnailExtractor = $this->createStub(SceneThumbnailExtractor::class);
        $thumbnailExtractor
            ->method('extractSceneThumbnail')
            ->willReturnCallback(static function (VideoScene $scene): File {
                $file = new File();
                $file->setRelativePath(sprintf(
                    'images/videos/%d/thumbnails/scene_%d.jpg',
                    $scene->getVideo()?->getId(),
                    $scene->getId(),
                ));
                $file->setMimeType('image/jpeg');
                $file->setPurpose(FilePurpose::THUMBNAIL_VIDEO);

                return $file;
            });

        $container->set(AmazonRekognitionService::class, $this->rekognitionService);
        $container->set(GeminiService::class, $this->geminiService);
        $container->set(Client::class, $meilisearch);
        $container->set(SceneThumbnailExtractor::class, $thumbnailExtractor);
        $container->set('default.storage', $this->createStub(FilesystemOperator::class));
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->pathProvider = $container->get(StoragePathProvider::class);
    }

    public function testVideoWithoutFacesIsProcessedCompletely(): void
    {
        $this->stubExternalServices(faces: []);
        $video = $this->createVideoFromSample('people-detection.mp4');

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $video = $this->reload($video);
        self::assertSame(VideoStatus::COMPLETED, $video->getStatus(), (string) $video->getErrorMessage());
        self::assertGreaterThan(0, $video->getScenes()->count(), 'PySceneDetect sollte mindestens eine Szene liefern');
        // MP4-Quellen werden absichtlich nicht neu konvertiert
        self::assertNull($video->getConvertedFile());
    }

    public function testDetectedFacesAreAssignedToScenes(): void
    {
        $this->stubExternalServices(faces: $this->loadFixture('face-demographics-walking_rekognition_response.json'));
        $video = $this->createVideoFromSample('face-demographics-walking.mp4');

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $video = $this->reload($video);
        self::assertSame(VideoStatus::COMPLETED, $video->getStatus(), (string) $video->getErrorMessage());

        $facesInScenes = 0;
        foreach ($video->getScenes() as $scene) {
            $facesInScenes += $scene->getVideoFaces()->count();
        }
        self::assertGreaterThan(0, $facesInScenes, 'Erkannte Gesichter sollten Szenen zugeordnet sein');
    }

    /**
     * Im Worker führt die Exception zu Retries und danach zu FailedStepSubscriber (siehe FailedStepSubscriberTest).
     */
    public function testSceneDetectionFailsLoudlyForCorruptVideo(): void
    {
        $video = $this->createVideoFromSample('people-detection.mp4');
        file_put_contents($this->pathProvider->getAbsoluteFilePath('videos/test/people-detection.mp4'), 'kein Video');

        try {
            $this->dispatch(new SceneDetectionStepMessage(self::idOf($video), 1, ConvertStepMessage::class));
            self::fail('Die Szenenerkennung hätte fehlschlagen müssen.');
        } catch (HandlerFailedException $e) {
            self::assertStringContainsString('Could not open video file', $e->getMessage());
        }

        self::assertCount(0, $this->reload($video)->getScenes());
    }

    /**
     * @param list<array<string, mixed>> $faces
     */
    private function stubExternalServices(array $faces): void
    {
        $this->rekognitionService->method('processAllFacesInImage')->willReturn($faces);
        $this->geminiService->method('analyzeImage')->willReturn(['Titel' => 'Test', 'Tags' => ['Orte' => ['Flur']]]);
        $this->geminiService
            ->method('suggestChapters')
            ->willReturn([
                ['title' => 'Kapitel 1', 'startSeconds' => 0, 'endSeconds' => 5, 'description' => 'Beschreibung'],
            ]);
        $this->geminiService->method('suggestVideoName')->willReturn('Testvideo');
    }

    private function createVideoFromSample(string $sample): Video
    {
        $relativePath = 'videos/test/' . $sample;
        $absolutePath = $this->pathProvider->getAbsoluteFilePath($relativePath);
        new Filesystem()->copy(self::SAMPLES_DIR . '/' . $sample, $absolutePath, true);
        $this->trackCreatedPath($absolutePath);

        $file = new File();
        $file->setRelativePath($relativePath);
        $file->setMimeType('video/mp4');
        $file->setPurpose(FilePurpose::VIDEO_SOURCE);

        $video = new Video();
        $video->setTitle('Sample ' . $sample);
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setSourceFile($file);

        $this->entityManager->persist($video);
        $this->entityManager->flush();

        return $video;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFixture(string $filename): array
    {
        return json_decode(
            (string) file_get_contents(self::SAMPLES_DIR . '/' . $filename),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
    }

    private static function skipUnlessVideoToolsAreInstalled(): void
    {
        if (new ExecutableFinder()->find('ffmpeg') === null) {
            self::markTestSkipped('ffmpeg ist nicht installiert.');
        }

        $python = new Process(['python3', '-c', 'import scenedetect']);
        if ($python->run() !== 0) {
            self::markTestSkipped('PySceneDetect ist nicht installiert (pip install scenedetect[av]).');
        }
    }
}
