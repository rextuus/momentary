<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\File;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
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
use App\Service\Video\Analyze\SceneDetector;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Filesystem;

class VideoProcessingPipelineTest extends VideoPipelineTestCase
{
    private MockObject $rekognitionServiceMock;
    private MockObject $meiliSearchClientMock;
    private MockObject $mp4Converter;
    private MockObject $pathResolver;
    private MockObject $sceneDetector;
    private MockObject $sceneThumbnailExtractor;
    private MockObject $frameExtractor;
    private MockObject $frameAnalyzer;
    private MockObject $emptyScenesMerger;
    private MockObject $taggingService;

    private MockObject $chapterGenerator;

    private MockObject $jellyfinUploader;
    private VideoRepository $videoRepository;
    private EntityManagerInterface $entityManager;
    private array $createdTempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rekognitionServiceMock = $this->createMock(AmazonRekognitionService::class);
        $this->meiliSearchClientMock = $this->createMock(Client::class);
        $this->mp4Converter = $this->createMock(Mp4Converter::class);
        $this->pathResolver = $this->createMock(PathResolver::class);
        $this->sceneDetector = $this->createMock(SceneDetector::class);
        $this->sceneThumbnailExtractor = $this->createMock(SceneThumbnailExtractor::class);
        $this->frameExtractor = $this->createMock(FrameExtractor::class);
        $this->frameAnalyzer = $this->createMock(FrameAnalyzer::class);
        $this->emptyScenesMerger = $this->createMock(EmptyScenesMerger::class);
        $this->taggingService = $this->createMock(TaggingService::class);
        $this->chapterGenerator = $this->createMock(ChapterGenerator::class);
        $this->jellyfinUploader = $this->createMock(JellyfinUploader::class);

        $indexMock = $this->createMock(Indexes::class);
        $this->meiliSearchClientMock->method('index')->willReturn($indexMock);

        static::getContainer()->set(AmazonRekognitionService::class, $this->rekognitionServiceMock);
        static::getContainer()->set(Client::class, $this->meiliSearchClientMock);
        static::getContainer()->set(Mp4Converter::class, $this->mp4Converter);
        static::getContainer()->set(PathResolver::class, $this->pathResolver);
        static::getContainer()->set(SceneDetector::class, $this->sceneDetector);
        static::getContainer()->set(SceneThumbnailExtractor::class, $this->sceneThumbnailExtractor);
        static::getContainer()->set(FrameExtractor::class, $this->frameExtractor);
        static::getContainer()->set(FrameAnalyzer::class, $this->frameAnalyzer);
        static::getContainer()->set(EmptyScenesMerger::class, $this->emptyScenesMerger);
        static::getContainer()->set(TaggingService::class, $this->taggingService);
        static::getContainer()->set(ChapterGenerator::class, $this->chapterGenerator);
        static::getContainer()->set(JellyfinUploader::class, $this->jellyfinUploader);

        $this->videoRepository = static::getContainer()->get(VideoRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->createdTempFiles as $filePath) {
            if ($filesystem->exists($filePath)) {
                $filesystem->remove($filePath);
            }
        }

        parent::tearDown();
    }

    public function testHappyPath(): void
    {
        // 1. Setup Data
        $video = new Video();
        $video->setTitle('Urlaub 2024');
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::PENDING);
        $video->setLocalPath('test.mov');
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
            $this->createdTempFiles[] = $path;
        }

        // Mocks konfigurieren
        $this->frameAnalyzer->method('analyzeFrame')->willReturn(true);
        $this->mp4Converter->method('convertToMp4')->willReturn(true);
        $this->pathResolver->method('resolvePath')->willReturn('test.mov');
        $this->taggingService->method('tagScene')->willReturn(true);
        $result = new ChapterGenerationResult(
            true,
            11,
        );
        $this->chapterGenerator->method('generateChapters')->willReturn($result);

        $result = new JellyfinExportResult(
            true,
            'test',
            '1111',
        );
        $this->jellyfinUploader->method('exportVideo')->willReturn($result);

        // 3. Mock RekognitionService
        $this->rekognitionServiceMock
            ->method('processAllFacesInImage')
            ->willReturn([
                [
                    'BoundingBox' => ['Width' => 0.2, 'Height' => 0.2, 'Left' => 0.4, 'Top' => 0.3],
                    'Confidence' => 99.8,
                ],
            ]);

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
            ->method('extractThumbnail')
            ->willReturnCallback(function (Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): File {
                $filename = $customFilename ?? sprintf('video_%d.jpg', $video->getId());
                $file = new File();
                $file->setRelativePath(sprintf('videos/%d/thumbnails/%s', $video->getId(), $filename));
                return $file;
            });

        $this->frameExtractor
            ->expects($this->any())
            ->method('extractFrames')
            ->willReturnOnConsecutiveCalls(
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
                ], '/tmp/frames/refinement')
            );

        $this->emptyScenesMerger
            ->expects($this->once())
            ->method('mergeEmptyScenes')
            ->willReturn(EmptyScenesMergerResult::success(4, 2));

        // 5. Trigger Pipeline
        $bus = static::getContainer()->get('messenger.bus.default');

        $bus->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        // 6. Assertions
        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());

        $this->assertEquals(VideoStatus::COMPLETED, $updatedVideo->getStatus());
    }

    public function testErrorCase(): void
    {
        // 1. Setup Data
        $video = new Video();
        $video->setTitle('Error Video');
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::PENDING);
        $video->setLocalPath('test.mov');
        $this->entityManager->persist($video);
        $this->entityManager->flush();

        // 2. Mock Error
        $this->pathResolver->method('resolvePath')->willReturn('test.mov');
        $this->mp4Converter->method('convertToMp4')->willReturn(false);

        // 3. Trigger Pipeline
        $bus = static::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        // 4. Assertions
        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());
        $this->assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
    }
}