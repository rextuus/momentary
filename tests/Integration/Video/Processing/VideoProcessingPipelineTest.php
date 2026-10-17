<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\ChapterGenerator;
use App\Service\Video\Analyze\EmptyScenesMerger;
use App\Service\Video\Analyze\FrameAnalyzer;
use App\Service\Video\Analyze\FrameExtractor;
use App\Service\Video\Analyze\JellyfinUploader;
use App\Service\Video\Analyze\Mp4Converter;
use App\Service\Video\Analyze\PathResolver;
use App\Service\Video\Analyze\Result\ChapterGenerationResult;
use App\Service\Video\Analyze\Result\EmptyScenesMergerResult;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Analyze\Result\JellyfinExportResult;
use App\Service\Video\Analyze\Result\TaggingResult;
use App\Service\Video\Analyze\SceneDetector;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Filesystem\Filesystem;

class VideoProcessingPipelineTest extends VideoPipelineTestCase
{
    private AmazonRekognitionService&Stub $rekognitionServiceMock;
    private Client&Stub $meiliSearchClientMock;
    private Mp4Converter&Stub $mp4Converter;
    private PathResolver&Stub $pathResolver;
    private SceneDetector&Stub $sceneDetector;
    private SceneThumbnailExtractor&Stub $sceneThumbnailExtractor;
    private FrameExtractor&Stub $frameExtractor;
    private FrameAnalyzer&Stub $frameAnalyzer;
    private TaggingService&Stub $taggingService;
    private GeminiService&Stub $geminiServiceMock;
    private ChapterGenerator&Stub $chapterGenerator;
    private JellyfinUploader&Stub $jellyfinUploader;
    private VideoRepository $videoRepository;
    private EntityManagerInterface $entityManager;
    private StoragePathProvider $pathProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rekognitionServiceMock = $this->createStub(AmazonRekognitionService::class);
        $this->meiliSearchClientMock = $this->createStub(Client::class);
        $this->mp4Converter = $this->createStub(Mp4Converter::class);
        $this->pathResolver = $this->createStub(PathResolver::class);
        $this->sceneDetector = $this->createStub(SceneDetector::class);
        $this->sceneThumbnailExtractor = $this->createStub(SceneThumbnailExtractor::class);
        $this->frameExtractor = $this->createStub(FrameExtractor::class);
        $this->frameAnalyzer = $this->createStub(FrameAnalyzer::class);
        $this->taggingService = $this->createStub(TaggingService::class);
        $this->geminiServiceMock = $this->createStub(GeminiService::class);
        $this->chapterGenerator = $this->createStub(ChapterGenerator::class);
        $this->jellyfinUploader = $this->createStub(JellyfinUploader::class);

        $indexMock = $this->createStub(Indexes::class);
        $this->meiliSearchClientMock->method('index')->willReturn($indexMock);

        static::getContainer()->set(AmazonRekognitionService::class, $this->rekognitionServiceMock);
        static::getContainer()->set(Client::class, $this->meiliSearchClientMock);
        static::getContainer()->set(Mp4Converter::class, $this->mp4Converter);
        static::getContainer()->set(PathResolver::class, $this->pathResolver);
        static::getContainer()->set(SceneDetector::class, $this->sceneDetector);
        static::getContainer()->set(SceneThumbnailExtractor::class, $this->sceneThumbnailExtractor);
        static::getContainer()->set(FrameExtractor::class, $this->frameExtractor);
        static::getContainer()->set(FrameAnalyzer::class, $this->frameAnalyzer);
        static::getContainer()->set(TaggingService::class, $this->taggingService);
        static::getContainer()->set(GeminiService::class, $this->geminiServiceMock);
        static::getContainer()->set(ChapterGenerator::class, $this->chapterGenerator);
        static::getContainer()->set(JellyfinUploader::class, $this->jellyfinUploader);

        $this->videoRepository = static::getContainer()->get(VideoRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->pathProvider = static::getContainer()->get(StoragePathProvider::class);
    }

    public function testHappyPath(): void
    {
        $video = $this->arrangeSuccessfulPipeline();

        // 5. Trigger Pipeline
        $this->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        // 6. Assertions
        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());

        self::assertEquals(VideoStatus::COMPLETED, $updatedVideo->getStatus());
        self::assertNull($updatedVideo->getErrorMessage());
        self::assertCount(4, $updatedVideo->getScenes(), 'Alle erkannten Szenen sollten angelegt sein');
        self::assertSame(
            [1, 2, 3, 4],
            $updatedVideo
                ->getScenes()
                ->map(static fn($scene) => $scene->getSceneNumber())
                ->getValues(),
        );
        self::assertNotNull($updatedVideo->getConvertedFile(), 'Konvertierte Datei sollte hinterlegt sein');

        self::assertNotEmpty($updatedVideo->getProcessingSteps());
        foreach ($updatedVideo->getProcessingSteps() as $step) {
            self::assertNull($step->getErrorMessage(), sprintf(
                'Schritt %s sollte ohne Fehler laufen',
                $step->getStep()->value,
            ));
        }
    }

    public function testFailedTaggingStopsPipelineWithError(): void
    {
        $video = $this->arrangeSuccessfulPipeline(new TaggingResult(false, false, [], 'Gemini API Error'));

        $this->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());

        self::assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
        $failedSteps = $updatedVideo
            ->getProcessingSteps()
            ->filter(static fn($step) => $step->getErrorMessage() !== null);
        self::assertCount(1, $failedSteps);
        self::assertSame(VideoStatus::TAGGING_SCENES, $failedSteps->first()->getStep());
        self::assertStringContainsString('Gemini API Error', $failedSteps->first()->getErrorMessage());
    }

    private function arrangeSuccessfulPipeline(?TaggingResult $taggingResult = null): Video
    {
        // 1. Setup Data
        $video = new Video();
        $video->setTitle('Urlaub 2024');
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::PENDING);
        $file = new File();
        $file->setRelativePath('test.mov');
        $file->setMimeType('video/mp4');
        $file->setPurpose(FilePurpose::VIDEO_SOURCE);
        $video->setSourceFile($file);

        // Ensure source file exists in storage
        $sourceAbsolutePath = $this->pathProvider->getAbsoluteFilePath($file->getRelativePath());
        $filesystem = new Filesystem();
        $filesystem->mkdir(dirname($sourceAbsolutePath));
        file_put_contents($sourceAbsolutePath, 'dummy-video-content');
        $this->trackCreatedPath($sourceAbsolutePath);

        $this->entityManager->persist($video);
        $this->entityManager->flush();

        // 2. Setup Dummy Frame Files
        $framePaths = [
            '/tmp/frames/analysis/frame_0001.jpg',
            '/tmp/frames/analysis/frame_0002.jpg',
            '/tmp/frames/analysis/frame_0003.jpg',
            '/tmp/frames/analysis/frame_0004.jpg',
            '/tmp/frames/analysis/frame_0005.jpg',
            '/tmp/frames/analysis/frame_0006.jpg',
            '/tmp/frames/analysis/frame_0007.jpg',
            '/tmp/frames/analysis/frame_0008.jpg',
            '/tmp/frames/refinement/frame_0009.jpg',
            '/tmp/frames/refinement/frame_0010.jpg',
        ];

        $filesystem = new Filesystem();
        foreach ($framePaths as $path) {
            $filesystem->mkdir(dirname($path));
            file_put_contents($path, 'fake-image-binary-content');
            $this->trackCreatedPath($path);
        }

        // Mocks konfigurieren
        $this->frameAnalyzer->method('analyzeFrame')->willReturn(true);
        $this->mp4Converter
            ->method('convertToMp4')
            ->willReturnCallback(function (string $sourcePath, string $targetPath) use ($filesystem) {
                $filesystem->mkdir(dirname($targetPath));
                file_put_contents($targetPath, 'dummy-mp4-content');
                $this->trackCreatedPath($targetPath);
                return true;
            });
        $this->pathResolver->method('resolvePath')->willReturn('test.mov');
        $this->taggingService->method('tagScene')->willReturn($taggingResult ?? new TaggingResult(true, true, [1]));
        $result = new ChapterGenerationResult(true, 11);
        $this->chapterGenerator->method('generateChapters')->willReturn($result);

        $result = new JellyfinExportResult(true, 'test', '1111');
        $this->jellyfinUploader->method('exportVideo')->willReturn($result);

        // 3. Mock RekognitionService
        $this->rekognitionServiceMock
            ->method('processAllFacesInImage')
            ->willReturn([
                [
                    'BoundingBox' => ['Width' => 0.2, 'Height' => 0.2, 'Left' => 0.4, 'Top' => 0.3],
                    'Confidence' => 99.8,
                    'faceId' => 'face1',
                    'age' => 20,
                    'gender' => 'Male',
                    'emotion' => 'Happy',
                    'similarity' => 90.0,
                ],
            ]);

        // 3.1 Mock GeminiService
        $this->geminiServiceMock
            ->method('analyzeImage')
            ->willReturn(['Titel' => 'Test', 'Tags' => ['Personen' => ['Kind'], 'Aktivitäten' => [], 'Orte' => []]]);
        $this->geminiServiceMock
            ->method('suggestChapters')
            ->willReturn([['title' => 'Kapitel 1', 'startSeconds' => 0, 'endSeconds' => 60, 'description' => 'Desc']]);
        $this->geminiServiceMock->method('suggestVideoName')->willReturn('Test Video Name');

        // 4. Mock SceneDetector & Extractor
        $this->sceneDetector
            ->method('detectScenes')
            ->willReturn([
                [
                    'scene_number' => 1,
                    'start_seconds' => 0.0,
                    'end_seconds' => 10.0,
                    'start_frame' => 0,
                    'end_frame' => 250,
                ],
                [
                    'scene_number' => 2,
                    'start_seconds' => 10.0,
                    'end_seconds' => 25.5,
                    'start_frame' => 251,
                    'end_frame' => 637,
                ],
                [
                    'scene_number' => 3,
                    'start_seconds' => 25.5,
                    'end_seconds' => 40.0,
                    'start_frame' => 638,
                    'end_frame' => 1000,
                ],
                [
                    'scene_number' => 4,
                    'start_seconds' => 40.0,
                    'end_seconds' => 60.0,
                    'start_frame' => 1001,
                    'end_frame' => 1500,
                ],
            ]);

        $this->sceneThumbnailExtractor
            ->method('extractSceneThumbnail')
            ->willReturnCallback(static function (VideoScene $scene): File {
                $file = new File();
                $file->setRelativePath(sprintf(
                    'videos/%d/thumbnails/scene_%d.jpg',
                    $scene->getVideo()->getId(),
                    $scene->getId(),
                ));
                $file->setMimeType('image/jpeg');
                $file->setPurpose(FilePurpose::THUMBNAIL_VIDEO);
                return $file;
            });

        $this->frameExtractor->method('extractFrames')->willReturnOnConsecutiveCalls(
            new FrameSplittingResult([
                ['path' => '/tmp/frames/analysis/frame_0001.jpg', 'timestamp' => 3.0],
                ['path' => '/tmp/frames/analysis/frame_0002.jpg', 'timestamp' => 5.0],
                ['path' => '/tmp/frames/analysis/frame_0003.jpg', 'timestamp' => 7.0],
                ['path' => '/tmp/frames/analysis/frame_0004.jpg', 'timestamp' => 9.0],
            ], '/tmp/frames/analysis'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/analysis/frame_0005.jpg', 'timestamp' => 4.0],
                ['path' => '/tmp/frames/analysis/frame_0006.jpg', 'timestamp' => 5.0],
            ], '/tmp/frames/analysis'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/analysis/frame_0007.jpg', 'timestamp' => 6.0],
                ['path' => '/tmp/frames/analysis/frame_0008.jpg', 'timestamp' => 7.0],
            ], '/tmp/frames/analysis'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0009.jpg', 'timestamp' => 8.0],
                ['path' => '/tmp/frames/refinement/frame_0010.jpg', 'timestamp' => 9.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0011.jpg', 'timestamp' => 11.0],
                ['path' => '/tmp/frames/refinement/frame_0012.jpg', 'timestamp' => 13.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0013.jpg', 'timestamp' => 15.0],
                ['path' => '/tmp/frames/refinement/frame_0014.jpg', 'timestamp' => 17.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0013.jpg', 'timestamp' => 15.0],
                ['path' => '/tmp/frames/refinement/frame_0014.jpg', 'timestamp' => 17.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0013.jpg', 'timestamp' => 15.0],
                ['path' => '/tmp/frames/refinement/frame_0014.jpg', 'timestamp' => 17.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0013.jpg', 'timestamp' => 15.0],
                ['path' => '/tmp/frames/refinement/frame_0014.jpg', 'timestamp' => 17.0],
            ], '/tmp/frames/refinement'),
            new FrameSplittingResult([
                ['path' => '/tmp/frames/refinement/frame_0013.jpg', 'timestamp' => 15.0],
                ['path' => '/tmp/frames/refinement/frame_0014.jpg', 'timestamp' => 17.0],
            ], '/tmp/frames/refinement'),
        );

        $emptyScenesMerger = $this->createMock(EmptyScenesMerger::class);
        $emptyScenesMerger
            ->expects($this->once())
            ->method('mergeEmptyScenes')
            ->willReturn(EmptyScenesMergerResult::success(4, 2));
        static::getContainer()->set(EmptyScenesMerger::class, $emptyScenesMerger);

        return $video;
    }

    public function testErrorCase(): void
    {
        // 1. Setup Data
        $video = new Video();
        $video->setTitle('Error Video');
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::PENDING);
        $file = new File();
        $file->setRelativePath('test.mov');
        $file->setMimeType('video/mp4');
        $file->setPurpose(FilePurpose::VIDEO_SOURCE);
        $video->setSourceFile($file);
        $this->entityManager->persist($video);
        $this->entityManager->flush();

        // 2. Mock Error
        $this->pathResolver->method('resolvePath')->willReturn('test.mov');
        $this->mp4Converter->method('convertToMp4')->willReturn(false);

        // 3. Trigger Pipeline
        $this->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        // 4. Assertions
        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());
        self::assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
        self::assertCount(
            0,
            $updatedVideo->getScenes(),
            'Nach fehlgeschlagener Konvertierung darf es keine Szenen geben',
        );

        $failedSteps = $updatedVideo
            ->getProcessingSteps()
            ->filter(static fn($step) => $step->getErrorMessage() !== null);
        self::assertCount(1, $failedSteps);
        self::assertSame(VideoStatus::CONVERTING, $failedSteps->first()->getStep());
    }
}
