<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Entity\File;
use App\Entity\TranscriptionSettings;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Entity\VideoTranscript;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\DetectedFace;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Transcription\TranscriptionSettingsProvider;
use App\Service\Transcription\VideoTranscriber;
use App\Service\Video\Analyze\ChapterGenerator;
use App\Service\Video\Analyze\EmptyScenesMerger;
use App\Service\Video\Analyze\FrameAnalyzer;
use App\Service\Video\Analyze\FrameExtractor;
use App\Service\Video\Analyze\JellyfinUploader;
use App\Service\Video\Analyze\Mp4Converter;
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
    private SceneDetector&Stub $sceneDetector;
    private SceneThumbnailExtractor&Stub $sceneThumbnailExtractor;
    private FrameExtractor&Stub $frameExtractor;
    private FrameAnalyzer&Stub $frameAnalyzer;
    private TaggingService&Stub $taggingService;
    private GeminiService&Stub $geminiServiceMock;
    private ChapterGenerator&Stub $chapterGenerator;
    private JellyfinUploader&Stub $jellyfinUploader;
    private EntityManagerInterface $entityManager;
    private StoragePathProvider $pathProvider;

    /** @var list<string> */
    private array $analyzedFrames = [];

    /** @var list<int|null> */
    private array $transcribedVideos = [];

    /** @var list<array{refinement: bool, subDirectory: ?string, fps: ?float}> */
    private array $extractCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rekognitionServiceMock = $this->createStub(AmazonRekognitionService::class);
        $this->meiliSearchClientMock = $this->createStub(Client::class);
        $this->mp4Converter = $this->createStub(Mp4Converter::class);
        $this->mp4Converter
            ->method('probe')
            ->willReturn(['video' => ['codec' => 'h264', 'pixFmt' => 'yuv420p'], 'audio' => [], 'duration' => 60.0]);
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
        static::getContainer()->set(SceneDetector::class, $this->sceneDetector);
        static::getContainer()->set(SceneThumbnailExtractor::class, $this->sceneThumbnailExtractor);
        static::getContainer()->set(FrameExtractor::class, $this->frameExtractor);
        static::getContainer()->set(FrameAnalyzer::class, $this->frameAnalyzer);
        static::getContainer()->set(TaggingService::class, $this->taggingService);
        static::getContainer()->set(GeminiService::class, $this->geminiServiceMock);
        static::getContainer()->set(ChapterGenerator::class, $this->chapterGenerator);
        static::getContainer()->set(JellyfinUploader::class, $this->jellyfinUploader);

        $transcriber = $this->createStub(VideoTranscriber::class);
        $transcriber
            ->method('transcribe')
            ->willReturnCallback(function (Video $video): VideoTranscript {
                $this->transcribedVideos[] = $video->getId();
                // Eigenes Dummy-Video: das echte Video darf der Stub nicht verändern
                $transcript = new VideoTranscript(new Video());
                $transcript->start('large-v3-turbo', 60.0);
                $transcript->complete('de', []);

                return $transcript;
            });
        static::getContainer()->set(VideoTranscriber::class, $transcriber);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->pathProvider = static::getContainer()->get(StoragePathProvider::class);
    }

    public function testHappyPath(): void
    {
        $video = $this->arrangeSuccessfulPipeline();

        // 5. Trigger Pipeline
        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        // 6. Assertions
        $updatedVideo = $this->reload($video);

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
        self::assertEqualsWithDelta(60.0, $updatedVideo->getDuration(), 0.01, 'Videolänge für Zeitschätzungen');
        $tagged = $updatedVideo->getProgressFor(VideoStatus::TAGGING_SCENES);
        self::assertNotNull($tagged, 'Tagging zählt seinen Fortschritt');
        self::assertSame($tagged['total'], $tagged['done']);

        self::assertNotEmpty($updatedVideo->getProcessingSteps());
        foreach ($updatedVideo->getProcessingSteps() as $step) {
            self::assertNull($step->getErrorMessage(), sprintf(
                'Schritt %s sollte ohne Fehler laufen',
                $step->getStep()?->value,
            ));
        }
    }

    public function testTranscriptionIsTheLastStep(): void
    {
        $video = $this->arrangeSuccessfulPipeline();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $updatedVideo = $this->reload($video);
        self::assertSame([$video->getId()], $this->transcribedVideos);
        self::assertSame(VideoStatus::COMPLETED, $updatedVideo->getStatus());
        $steps = $updatedVideo->getProcessingSteps()->toArray();
        usort($steps, static fn($a, $b): int => $a->getId() <=> $b->getId());
        $last = end($steps);
        self::assertNotFalse($last);
        self::assertSame(VideoStatus::TRANSCRIBING, $last->getStep());
        self::assertNotNull($last->getFinishedAt(), 'Dauer des Schritts wird erfasst');
    }

    public function testTranscriptionCanBeSwitchedOff(): void
    {
        $settings = new TranscriptionSettings()->setTranscribeInPipeline(false);
        static::getContainer()->get(TranscriptionSettingsProvider::class)->save($settings);
        $video = $this->arrangeSuccessfulPipeline();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        self::assertSame([], $this->transcribedVideos);
        self::assertSame(VideoStatus::COMPLETED, $this->reload($video)->getStatus());
    }

    public function testEveryFrameIncludingRefinementIsAnalyzedExactlyOnce(): void
    {
        $video = $this->arrangeSuccessfulPipeline();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        // Erst die Frames des ganzen Videos, danach die Refinement-Frames der vier (noch) gesichtslosen Szenen
        $expected = array_map(static fn(int $n): string => sprintf('frame_%04d.jpg', $n), range(1, 12));
        self::assertSame($expected, $this->analyzedFrames);
    }

    /**
     * Refinement-Frames dürfen weder im Ordner der Erstanalyse landen noch sich gegenseitig überschreiben:
     * jede Szene bekommt einen eigenen Refinement-Ordner und die Refinement-Bildrate des Videos.
     */
    public function testRefinementSplitsEachSceneIntoItsOwnFolder(): void
    {
        $video = $this->arrangeSuccessfulPipeline();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        self::assertFalse($this->extractCalls[0]['refinement'], 'Erstanalyse des ganzen Videos');
        $refinement = \array_slice($this->extractCalls, 1);
        self::assertCount(4, $refinement);
        foreach ($refinement as $call) {
            self::assertTrue($call['refinement']);
            self::assertNull($call['fps'], 'Bildrate kommt aus den Refinement-Einstellungen des Videos');
        }
        $folders = array_column($refinement, 'subDirectory');
        self::assertCount(4, array_unique($folders), 'ein eigener Ordner pro Szene');
        self::assertStringStartsWith('scene_', (string) $folders[0]);
    }

    /**
     * Ist die Erstanalyse schon so dicht wie das Refinement (FRAME_ANALYSIS_FPS >= Refinement-fps), wird nichts
     * erneut extrahiert. Die State Machine muss trotzdem sauber durch alle Refinement-Zustände bis zum Ende laufen.
     */
    public function testRefinementIsSkippedWhenFirstAnalysisIsAlreadyDenseEnough(): void
    {
        $video = $this->arrangeSuccessfulPipeline();
        $video->setAnalysisFps(1.0);
        $video->setRefinedAnalysisFps(1.0);
        $this->entityManager->flush();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $updatedVideo = $this->reload($video);
        self::assertSame(VideoStatus::COMPLETED, $updatedVideo->getStatus());
        self::assertCount(1, $this->extractCalls, 'nur die Erstanalyse');
        self::assertFalse($this->extractCalls[0]['refinement']);
        self::assertSame(
            array_map(static fn(int $n): string => sprintf('frame_%04d.jpg', $n), range(1, 4)),
            $this->analyzedFrames,
            'keine Refinement-Frames',
        );

        $steps = [];
        foreach ($updatedVideo->getProcessingSteps() as $step) {
            self::assertNull($step->getErrorMessage(), (string) $step->getStep()?->value);
            $steps[] = $step->getStep();
        }
        foreach ([
            VideoStatus::REFINING_EXTRACTION,
            VideoStatus::REFINING_SPLITTING,
            VideoStatus::ANALYZING_FACES_REFINEMENT,
            VideoStatus::CONSOLIDATING_FACES,
            VideoStatus::MERGING_SCENES,
        ] as $status) {
            self::assertContains($status, $steps, sprintf('Zustand %s wird durchlaufen', $status->value));
        }
    }

    /**
     * Eine Exception in einem Schritt darf das Video nicht still im Zustand stehen lassen: Fehler mit Grund und
     * Schritt, sichtbar am Video und am Schritt.
     */
    public function testExceptionInAStepSetsVideoToErrorWithReason(): void
    {
        // Vor arrangeSuccessfulPipeline(): die erste Konfiguration eines Stubs gilt
        $this->chapterGenerator
            ->method('generateChapters')
            ->willThrowException(new \RuntimeException('Gemini antwortet nicht'));
        $video = $this->arrangeSuccessfulPipeline();

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $updated = $this->reload($video);
        self::assertSame(VideoStatus::ERROR, $updated->getStatus());
        self::assertSame('Kapitel erzeugen: Gemini antwortet nicht', $updated->getErrorMessage());
        $failed = self::singleFailedStep($updated);
        self::assertSame(VideoStatus::CHAPTER_GENERATION, $failed->getStep());
        self::assertSame('Gemini antwortet nicht', $failed->getErrorMessage());
    }

    public function testFailedTaggingStopsPipelineWithError(): void
    {
        $video = $this->arrangeSuccessfulPipeline(new TaggingResult(false, false, [], 'Gemini API Error'));

        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        $updatedVideo = $this->reload($video);

        self::assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
        $failedStep = self::singleFailedStep($updatedVideo);
        self::assertSame(VideoStatus::TAGGING_SCENES, $failedStep->getStep());
        self::assertStringContainsString('Gemini API Error', (string) $failedStep->getErrorMessage());
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
        $this->frameAnalyzer
            ->method('analyzeFrame')
            ->willReturnCallback(function (int $videoId, string $framePath): bool {
                $this->analyzedFrames[] = basename($framePath);

                return true;
            });
        $this->mp4Converter
            ->method('convertToMp4')
            ->willReturnCallback(function (string $sourcePath, string $targetPath) use ($filesystem) {
                $filesystem->mkdir(dirname($targetPath));
                file_put_contents($targetPath, 'dummy-mp4-content');
                $this->trackCreatedPath($targetPath);
                return true;
            });
        $this->taggingService->method('tagScene')->willReturn($taggingResult ?? new TaggingResult(true, true, [1]));
        $result = new ChapterGenerationResult(true, 11);
        $this->chapterGenerator->method('generateChapters')->willReturn($result);

        $result = new JellyfinExportResult(true, 'test', '1111');
        $this->jellyfinUploader->method('exportVideo')->willReturn($result);

        // 3. Mock RekognitionService
        $this->rekognitionServiceMock
            ->method('processAllFacesInImage')
            ->willReturn([
                new DetectedFace(
                    'face1',
                    ['Width' => 0.2, 'Height' => 0.2, 'Left' => 0.4, 'Top' => 0.3],
                    99.8,
                    20,
                    'Male',
                    'Happy',
                    [],
                ),
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
                    $scene->getVideo()?->getId(),
                    $scene->getId(),
                ));
                $file->setMimeType('image/jpeg');
                $file->setPurpose(FilePurpose::THUMBNAIL_VIDEO);
                return $file;
            });

        $results = [
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
        ];
        $this->frameExtractor
            ->method('extractFrames')
            ->willReturnCallback(function (
                int $videoId,
                string $path,
                ?float $fps = null,
                mixed $start = null,
                mixed $duration = null,
                bool $isRefinement = false,
                ?string $subDirectory = null,
            ) use (&$results): FrameSplittingResult {
                $this->extractCalls[] = ['refinement' => $isRefinement, 'subDirectory' => $subDirectory, 'fps' => $fps];

                return array_shift($results) ?? new FrameSplittingResult([], '/tmp/frames/empty');
            });

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
        $this->mp4Converter->method('convertToMp4')->willReturn(false);

        // 3. Trigger Pipeline
        $this->dispatch(new ConvertStepMessage(self::idOf($video), 0, 'INITIAL'));

        // 4. Assertions
        $updatedVideo = $this->reload($video);
        self::assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
        self::assertCount(
            0,
            $updatedVideo->getScenes(),
            'Nach fehlgeschlagener Konvertierung darf es keine Szenen geben',
        );

        self::assertSame(VideoStatus::CONVERTING, self::singleFailedStep($updatedVideo)->getStep());
    }
}
