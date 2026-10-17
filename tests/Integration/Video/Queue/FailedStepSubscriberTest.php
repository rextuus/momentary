<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Queue;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\Message\SceneDetectionStepMessage;
use App\Service\Video\Queue\FailedStepSubscriber;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

use function Zenstruck\Foundry\Persistence\refresh;

/**
 * Sicherheitsnetz des Workers: scheitert ein Pipeline-Schritt endgültig, darf das Video
 * nicht in einem "processing"-Status hängen bleiben (sonst blockiert es einen Queue-Slot).
 */
final class FailedStepSubscriberTest extends KernelTestCase
{
    public function testSubscriberIsRegisteredForWorkerFailures(): void
    {
        $listeners = self::getContainer()
            ->get(EventDispatcherInterface::class)
            ->getListeners(WorkerMessageFailedEvent::class);
        $subscribers = array_map(static fn(callable $listener): string => \is_array($listener)
            ? $listener[0]::class
            : '', $listeners);

        self::assertContains(FailedStepSubscriber::class, $subscribers);
    }

    public function testFinalFailureMarksVideoAsErrorAndRecordsStep(): void
    {
        $video = VideoFactory::createOne(['status' => VideoStatus::SCENE_DETECTION]);

        $this->dispatchWorkerFailure(
            new SceneDetectionStepMessage(self::idOf($video), 1, ConvertStepMessage::class),
            new \RuntimeException('Scene detection failed: Could not open video file'),
            willRetry: false,
        );

        $video = refresh($video);
        self::assertSame(VideoStatus::ERROR, $video->getStatus());

        $failedStep = $video
            ->getProcessingSteps()
            ->findFirst(static fn($key, $step): bool => $step->getStep() === VideoStatus::SCENE_DETECTION);
        self::assertNotNull($failedStep);
        self::assertStringContainsString('Could not open video file', (string) $failedStep->getErrorMessage());
    }

    public function testFailureThatWillBeRetriedLeavesVideoUntouched(): void
    {
        $video = VideoFactory::createOne(['status' => VideoStatus::SCENE_DETECTION]);

        $this->dispatchWorkerFailure(
            new SceneDetectionStepMessage(self::idOf($video), 1, ConvertStepMessage::class),
            new \RuntimeException('temporär'),
            willRetry: true,
        );

        self::assertSame(VideoStatus::SCENE_DETECTION, refresh($video)->getStatus());
    }

    public function testFailureForDeletedVideoIsIgnored(): void
    {
        $this->dispatchWorkerFailure(
            new SceneDetectionStepMessage(999999, 1, ConvertStepMessage::class),
            new \RuntimeException('egal'),
            willRetry: false,
        );

        $this->addToAssertionCount(1); // keine Exception
    }

    private function dispatchWorkerFailure(object $message, \Throwable $error, bool $willRetry): void
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async_video_processing', $error);
        if ($willRetry) {
            $event->setForRetry();
        }

        // Direkt aufrufen: Messengers eigene Retry-/Failure-Listener würden die Message im
        // Test-Env (alle Transports sync://) sonst sofort erneut verarbeiten.
        ob_start();
        try {
            self::getContainer()->get(FailedStepSubscriber::class)->onFailed($event);
        } finally {
            ob_end_clean();
        }
    }

    private static function idOf(Video $video): int
    {
        $id = $video->getId();
        self::assertNotNull($id);

        return $id;
    }
}
