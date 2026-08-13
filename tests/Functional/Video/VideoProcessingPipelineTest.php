<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class VideoProcessingPipelineTest extends VideoPipelineTestCase
{
    private MockObject $videoAnalyzerMock;
    private MockObject $videoProcessingServiceMock;
    private MockObject $meiliSearchClientMock;
    private VideoRepository $videoRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->videoAnalyzerMock = $this->createMock(VideoAnalyzer::class);
        $this->meiliSearchClientMock = $this->createMock(\Meilisearch\Client::class);
        $indexMock = $this->createMock(\Meilisearch\Endpoints\Indexes::class);
        $this->meiliSearchClientMock->method('index')->willReturn($indexMock);

        static::getContainer()->set(VideoAnalyzer::class, $this->videoAnalyzerMock);
        static::getContainer()->set(\Meilisearch\Client::class, $this->meiliSearchClientMock);

        $this->videoRepository = static::getContainer()->get(VideoRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

        // 2. Mock Success
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->videoAnalyzerMock->method('resolvePath')->willReturn('test.mov');
        $this->videoAnalyzerMock->method('getProjectDir')->willReturn($projectDir);
        $this->videoAnalyzerMock->method('convertToMp4')->willReturn(true);
        $this->videoAnalyzerMock
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
                    'start_seconds' => 10.0,
                    'end_seconds' => 25.5,
                    'start_frame' => 251,
                    'end_frame' => 637,
                ],
                [
                    'scene_number' => 4,
                    'start_seconds' => 10.0,
                    'end_seconds' => 25.5,
                    'start_frame' => 251,
                    'end_frame' => 637,
                ],
            ]);
        $this->videoAnalyzerMock
            ->method('extractThumbnail')
            ->willReturnCallback(function (Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): string {
                $filename = $customFilename ?? sprintf('video_%d.jpg', $video->getId());
                return sprintf('videos/%d/thumbnails/%s?t=%d', $video->getId(), $filename, time());
            });
        $this->videoAnalyzerMock
            ->expects($this->exactly(4))
            ->method('extractFrames')
            ->willReturnOnConsecutiveCalls(
            // 1. Aufruf mit 4 Frames
                new FrameSplittingResult([
                    ['path' => '/tmp/frames/analysis/frame_0001.jpg', 'timestamp' => 0.0],
                    ['path' => '/tmp/frames/analysis/frame_0002.jpg', 'timestamp' => 1.0],
                    ['path' => '/tmp/frames/analysis/frame_0003.jpg', 'timestamp' => 2.0],
                    ['path' => '/tmp/frames/analysis/frame_0004.jpg', 'timestamp' => 3.0],
                ], '/tmp/frames/analysis'),

                // 2. Aufruf
                new FrameSplittingResult([
                    ['path' => '/tmp/frames/analysis/frame_0005.jpg', 'timestamp' => 4.0],
                    ['path' => '/tmp/frames/analysis/frame_0006.jpg', 'timestamp' => 5.0],
                ], '/tmp/frames/analysis'),

                // 3. Aufruf
                new FrameSplittingResult([
                    ['path' => '/tmp/frames/analysis/frame_0007.jpg', 'timestamp' => 6.0],
                    ['path' => '/tmp/frames/analysis/frame_0008.jpg', 'timestamp' => 7.0],
                ], '/tmp/frames/analysis'),

                // 4. Aufruf
                new FrameSplittingResult([
                    ['path' => '/tmp/frames/refinement/frame_0009.jpg', 'timestamp' => 8.0],
                    ['path' => '/tmp/frames/refinement/frame_0010.jpg', 'timestamp' => 9.0],
                ], '/tmp/frames/refinement')
            );
        $this->videoAnalyzerMock->method('resolvePath')->willReturn('test.mov');
        $this->videoAnalyzerMock->method('analyzeFrame');

        // 3. Trigger Pipeline
        $bus = static::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new ConvertStepMessage($video->getId()));

        // 4. Assertions
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
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->videoAnalyzerMock->method('resolvePath')->willReturn('test.mov');
        $this->videoAnalyzerMock->method('getProjectDir')->willReturn($projectDir);
        $this->videoAnalyzerMock->method('convertToMp4')->willReturn(false);

        // 3. Trigger Pipeline
        $bus = static::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new ConvertStepMessage($video->getId()));

        // 4. Assertions
        $this->entityManager->clear();
        $updatedVideo = $this->videoRepository->find($video->getId());
        $this->assertEquals(VideoStatus::ERROR, $updatedVideo->getStatus());
    }
}
