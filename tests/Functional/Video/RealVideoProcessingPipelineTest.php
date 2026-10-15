<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\File;
use App\Entity\Video;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Filesystem;

class RealVideoProcessingPipelineTest extends VideoPipelineTestCase
{
    private MockObject $rekognitionServiceMock;
    private MockObject $meiliSearchClientMock;
    private MockObject $geminiServiceMock;
    private MockObject $sceneThumbnailExtractorMock;
    private MockObject $filesystemMock;

    private VideoRepository $videoRepository;
    private EntityManagerInterface $entityManager;
    private StoragePathProvider $pathProvider;
    private array $createdTempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rekognitionServiceMock = $this->createMock(AmazonRekognitionService::class);
        $this->meiliSearchClientMock = $this->createMock(Client::class);
        $this->geminiServiceMock = $this->createMock(GeminiService::class);
        $this->sceneThumbnailExtractorMock = $this->createMock(\App\Service\Video\Analyze\SceneThumbnailExtractor::class);
        $this->filesystemMock = $this->createMock(\League\Flysystem\FilesystemOperator::class);

        $indexMock = $this->createMock(Indexes::class);
        $this->meiliSearchClientMock->method('index')->willReturn($indexMock);

        static::getContainer()->set(AmazonRekognitionService::class, $this->rekognitionServiceMock);
        static::getContainer()->set(Client::class, $this->meiliSearchClientMock);
        static::getContainer()->set(GeminiService::class, $this->geminiServiceMock);
        static::getContainer()->set(\App\Service\Video\Analyze\SceneThumbnailExtractor::class, $this->sceneThumbnailExtractorMock);
        static::getContainer()->set('default.storage', $this->filesystemMock);
        $this->filesystemMock->method('write')->willReturn(true);

        $this->videoRepository = static::getContainer()->get(VideoRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->pathProvider = static::getContainer()->get(StoragePathProvider::class);
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

    public function testRealPipelineExecution(): void
    {
        $video = $this->createVideo('Test Video', 'test_video.mp4');
        $this->setupMocks([], ['Titel' => 'Test', 'Tags' => ['Personen' => [], 'Aktivitäten' => [], 'Orte' => []]], [['title' => 'Kapitel 1', 'startSeconds' => 0, 'endSeconds' => 5, 'description' => 'Desc']], 'Test Video Name');
        $this->runPipeline($video);
        $this->printTestSummary($video, 'Test RealPipelineExecution');
    }

    public function testRealPipelineExecutionWithPersons(): void
    {
        $video = $this->createVideo('Test Video mit Personen', 'samples/people-detection.mp4');
        $this->setupMocks(
            $this->loadMockData('people-detection_rekognition_response.json'),
            [
                "Titel" => "Leerer Flur mit Parkettboden",
                "Tags" => [
                    "Objekte" => ["Holzfußboden", "Tür", "Fenster", "Säule", "Wand"],
                    "Umgebung" => ["Innenraum", "Flur", "Architektur", "Minimalismus"],
                    "Atmosphäre" => ["leer", "hell", "ruhig", "nüchtern"]
                ]
            ],
            [['title' => 'Kapitel 1', 'startSeconds' => 0, 'endSeconds' => 5, 'description' => 'Desc']],
            'Test Video Name'
        );
        $this->runPipeline($video);
        $this->printTestSummary($video, 'Test RealPipelineExecutionWithPersons');
    }

    public function testRealPipelineExecutionWithWalkingPersons(): void
    {
        $video = $this->createVideo('Test Video mit laufenden Personen', 'samples/face-demographics-walking.mp4');
        $this->setupMocks(
            $this->loadMockData('face-demographics-walking_rekognition_response.json'),
            ['Titel' => 'Test mit laufenden Personen', 'Tags' => ['Personen' => ['Person'], 'Aktivitäten' => ['Gehen'], 'Orte' => ['Strasse']]],
            [['title' => 'Kapitel 1', 'startSeconds' => 0, 'endSeconds' => 5, 'description' => 'Desc']],
            'Test Video Name'
        );
        $this->runPipeline($video);
        $this->printTestSummary($video, 'Test RealPipelineExecutionWithWalkingPersons');
    }

    private function loadMockData(string $filename): array
    {
        $path = __DIR__ . '/samples/' . $filename;
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true);
    }

    public function testRealPipelineExecutionWithError(): void
    {
        $video = $this->createVideo('Test Video mit Fehler', 'test_video_error.mp4');
        
        $this->geminiServiceMock
            ->method('analyzeImage')
            ->willThrowException(new \Exception('Gemini API Error'));
        
        $bus = static::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());

        $this->assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
    }

    private function createVideo(string $title, string $filename): Video
    {
        $video = new Video();
        $video->setTitle($title);
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::PENDING);
        
        $file = new File();
        $file->setRelativePath($filename);
        $file->setMimeType('video/mp4');
        $file->setPurpose(FilePurpose::VIDEO_SOURCE);
        $video->setSourceFile($file);
        
        // Ensure source file exists in storage
        $sourceAbsolutePath = $this->pathProvider->getAbsoluteFilePath($file->getRelativePath());
        
        // Copy our dummy test video to the right place
        $filesystem = new Filesystem();
        $filesystem->mkdir(dirname($sourceAbsolutePath));
        $filesystem->copy(__DIR__ . '/' . $filename, $sourceAbsolutePath);
        $this->createdTempFiles[] = $sourceAbsolutePath;
        
        $this->entityManager->persist($video);
        $this->entityManager->flush();

        return $video;
    }

    private function setupMocks(array $rekognitionResponse, array $geminiAnalysis, array $chapters, string $videoName): void
    {
        $this->rekognitionServiceMock
            ->method('processAllFacesInImage')
            ->willReturnCallback(function($path) use ($rekognitionResponse) {
                return $rekognitionResponse;
            });

        $this->geminiServiceMock
            ->method('analyzeImage')
            ->willReturn($geminiAnalysis);
        $this->geminiServiceMock
            ->method('suggestChapters')
            ->willReturn($chapters);
        $this->geminiServiceMock
            ->method('suggestVideoName')
            ->willReturn($videoName);

        $this->sceneThumbnailExtractorMock
            ->method('extractThumbnail')
            ->willReturnCallback(function (Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): File {
                $file = new File();
                $file->setRelativePath('images/videos/1/thumbnails/scene_1.jpg');
                $file->setMimeType('image/jpeg');
                $file->setPurpose(FilePurpose::THUMBNAIL_VIDEO);
                return $file;
            });
    }

    private function runPipeline(Video $video): void
    {
        // 3. Trigger Pipeline
        $bus = static::getContainer()->get('messenger.bus.default');

        $bus->dispatch(new ConvertStepMessage($video->getId(), 0, 'INITIAL'));

        // 4. Assertions
        $this->entityManager->flush();
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());

        // Expectation: The video should at least process successfully or move past PENDING
        $this->assertNotEquals(VideoStatus::PENDING, $updatedVideo->getStatus());
        $this->assertNotEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
    }

    private function printTestSummary(Video $video, string $testName): void
    {
        // Refresh video entity to ensure all relations are loaded
        $this->entityManager->clear();
        $video = $this->videoRepository->find($video->getId());
        
        $totalScenes = $video->getScenes()->count();
        
        $totalPersons = 0;
        foreach ($video->getScenes() as $scene) {
            $totalPersons += $scene->getVideoFaces()->count();
        }

        echo "\n--- Summary for {$testName} ---\n";
        echo "Getestet: {$testName}\n";
        echo "Anzahl Szenen: {$totalScenes}\n";
        echo "Anzahl Personen erkannt (via Szenen): {$totalPersons}\n";
        echo "---------------------------------\n";
    }
}
