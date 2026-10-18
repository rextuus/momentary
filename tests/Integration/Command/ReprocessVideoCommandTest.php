<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Enum\VideoStatus;
use App\Service\Video\Queue\ImportQueueManager;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function Zenstruck\Foundry\Persistence\refresh;

final class ReprocessVideoCommandTest extends KernelTestCase
{
    public function testFailedVideoIsQueuedAgain(): void
    {
        $kernel = self::bootKernel();
        $queue = $this->createMock(ImportQueueManager::class);
        $queue->expects(self::once())->method('processQueue')->willReturn(1);
        self::getContainer()->set(ImportQueueManager::class, $queue);

        $failed = VideoFactory::createOne([
            'status' => VideoStatus::ERROR,
            'errorMessage' => 'kaputt',
            'sourceFile' => FileFactory::new()->videoSource('videos/1/a.mov'),
        ]);
        $running = VideoFactory::createOne([
            'status' => VideoStatus::SCENE_DETECTION,
            'sourceFile' => FileFactory::new()->videoSource('videos/2/b.mov'),
        ]);

        $tester = new CommandTester(new Application($kernel)->find('app:video:reprocess'));
        $tester->execute(['videoIds' => [(string) $failed->getId(), (string) $running->getId()]]);

        self::assertSame(VideoStatus::QUEUED, refresh($failed)->getStatus());
        self::assertNull($failed->getErrorMessage());
        self::assertSame(
            VideoStatus::SCENE_DETECTION,
            refresh($running)->getStatus(),
            'laufende Videos bleiben unangetastet',
        );
        self::assertStringContainsString('1 Video(s) eingereiht', $tester->getDisplay());
    }
}
