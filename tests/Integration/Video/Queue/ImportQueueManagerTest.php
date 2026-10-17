<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Queue;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Queue\ImportQueueManager;
use App\Tests\Factory\VideoFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function Zenstruck\Foundry\Persistence\refresh;

final class ImportQueueManagerTest extends KernelTestCase
{
    private VideoProcessMessageDispatcher&MockObject $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->dispatcher = $this->createMock(VideoProcessMessageDispatcher::class);
        self::getContainer()->set(VideoProcessMessageDispatcher::class, $this->dispatcher);
    }

    public function testStartsOldestQueuedVideosUpToParallelLimit(): void
    {
        $newest = $this->queuedVideo('-1 minute');
        $oldest = $this->queuedVideo('-3 minutes');
        $middle = $this->queuedVideo('-2 minutes');

        $started = [];
        $this->dispatcher
            ->expects(self::exactly(2))
            ->method('dispatchInitialProcessMessage')
            ->willReturnCallback(static function (Video $video) use (&$started): bool {
                $started[] = $video->getId();

                return true;
            });

        self::assertSame(2, $this->manager()->processQueue());
        self::assertSame([$oldest->getId(), $middle->getId()], $started);

        self::assertSame(VideoStatus::CONVERTING, refresh($oldest)->getStatus());
        self::assertSame(VideoStatus::CONVERTING, refresh($middle)->getStatus());
        self::assertSame(VideoStatus::QUEUED, refresh($newest)->getStatus());
    }

    public function testRunningVideosCountAgainstTheLimit(): void
    {
        VideoFactory::createOne(['status' => VideoStatus::SCENE_DETECTION]);
        $first = $this->queuedVideo('-2 minutes');
        $second = $this->queuedVideo('-1 minute');

        $this->dispatcher->expects(self::once())->method('dispatchInitialProcessMessage')->willReturn(true);

        self::assertSame(1, $this->manager()->processQueue());
        self::assertSame(VideoStatus::CONVERTING, refresh($first)->getStatus());
        self::assertSame(VideoStatus::QUEUED, refresh($second)->getStatus());
    }

    public function testNothingStartsWhenLimitIsReached(): void
    {
        VideoFactory::createMany(2, ['status' => VideoStatus::ANALYZING_FACES_INITIAL]);
        $this->queuedVideo('-1 minute');

        $this->dispatcher->expects(self::never())->method('dispatchInitialProcessMessage');

        self::assertSame(0, $this->manager()->processQueue());
    }

    public function testFailedDispatchPutsVideoBackIntoQueue(): void
    {
        $video = $this->queuedVideo('-1 minute');
        $this->dispatcher->expects(self::once())->method('dispatchInitialProcessMessage')->willReturn(false);

        self::assertSame(0, $this->manager()->processQueue());
        self::assertSame(VideoStatus::QUEUED, refresh($video)->getStatus());
    }

    public function testCompletedAndFailedVideosAreIgnored(): void
    {
        VideoFactory::createOne(['status' => VideoStatus::COMPLETED]);
        VideoFactory::createOne(['status' => VideoStatus::ERROR]);
        VideoFactory::createOne(['status' => VideoStatus::PENDING]);

        $this->dispatcher->expects(self::never())->method('dispatchInitialProcessMessage');

        self::assertSame(0, $this->manager()->processQueue());
    }

    private function queuedVideo(string $createdAt): Video
    {
        return VideoFactory::createOne([
            'status' => VideoStatus::QUEUED,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function manager(): ImportQueueManager
    {
        return self::getContainer()->get(ImportQueueManager::class);
    }
}
