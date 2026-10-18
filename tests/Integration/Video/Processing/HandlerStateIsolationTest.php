<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\VideoStatus;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\FrameExtractor;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Processing\Handler\Splitting\Scene\SplitFirstSceneInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitFirstSceneInFramesStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Handler sind im Worker langlebige Services. Der Zustand einer Message (Szenen, Frames) darf nicht in die
 * Message eines anderen Videos geraten – sonst analysiert Video B die Frames von Video A.
 */
final class HandlerStateIsolationTest extends VideoPipelineTestCase
{
    /** @var list<VideoProcessStepMessageInterface> */
    private array $dispatched = [];

    public function testSceneListOfPreviousVideoDoesNotLeakIntoNextMessage(): void
    {
        $dispatcher = $this->createStub(VideoProcessMessageDispatcher::class);
        $dispatcher
            ->method('dispatch')
            ->willReturnCallback(function (VideoProcessStepMessageInterface $message): bool {
                $this->dispatched[] = $message;

                return true;
            });
        $frameExtractor = $this->createStub(FrameExtractor::class);
        $frameExtractor->method('extractFrames')->willReturn(new FrameSplittingResult([[
            'path' => '/tmp/frames/a/frame_0001.jpg',
            'timestamp' => 1.0,
        ]], '/tmp/frames/a'));
        self::getContainer()->set(VideoProcessMessageDispatcher::class, $dispatcher);
        self::getContainer()->set(FrameExtractor::class, $frameExtractor);

        [$videoA, $sceneA] = $this->videoWithScene('A');
        [$videoB] = $this->videoWithScene('B');

        $handler = self::getContainer()->get(SplitFirstSceneInFramesStepMessageHandler::class);

        $messageA = new SplitFirstSceneInFramesStepMessage(self::idOf($videoA), 1, 'test');
        $messageA->setCurrentSceneId(self::idOf($sceneA));
        $messageA->setRemainingSceneIds([self::idOf($sceneA) + 1000]);
        // Video B braucht keine Verfeinerung: früher Return ohne eigene Szenenliste
        $messageB = new SplitFirstSceneInFramesStepMessage(self::idOf($videoB), 1, 'test');

        ob_start();
        try {
            $handler($messageA);
            $handler($messageB);
        } finally {
            ob_end_clean();
        }

        self::assertCount(2, $this->dispatched);
        $nextForB = $this->dispatched[1];
        self::assertInstanceOf(SplitSceneInFramesStepMessage::class, $nextForB);
        self::assertSame(self::idOf($videoB), $nextForB->getVideoId());
        self::assertNull($nextForB->getCurrentSceneId());
        self::assertSame([], $nextForB->getRemainingSceneIds());
        self::assertSame([], $nextForB->getFramePathCollection());
    }

    /**
     * @return array{Video, VideoScene}
     */
    private function videoWithScene(string $name): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $file = new File();
        $file->setRelativePath(sprintf('isolation_%s.mp4', $name));
        $file->setMimeType('video/mp4');
        $file->setPurpose(FilePurpose::VIDEO_SOURCE);
        $path = self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($file->getRelativePath());
        new Filesystem()->dumpFile($path, 'dummy');
        $this->trackCreatedPath($path);

        $video = new Video();
        $video->setTitle($name);
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::REFINING_SPLITTING);
        $video->setSourceFile($file);

        $scene = new VideoScene();
        $scene->setSceneNumber(1);
        $scene->setStartSeconds(0.0);
        $scene->setEndSeconds(10.0);
        $video->addScene($scene);

        $entityManager->persist($video);
        $entityManager->persist($scene);
        $entityManager->flush();

        return [$video, $scene];
    }
}
