<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

use App\Service\Video\Processing\VideoFailure;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Scheitert ein Step endgültig (keine Retries mehr), wird das Video auf FAIL gesetzt.
 * Sonst bliebe es in einem "processing"-Status hängen und würde einen Queue-Slot blockieren.
 */
final class FailedStepSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VideoFailure $videoFailure,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorität 10: läuft vor dem QueueAdvanceSubscriber, damit der Slot schon frei ist
        return [WorkerMessageFailedEvent::class => ['onFailed', 10]];
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof VideoProcessStepMessageInterface) {
            return;
        }

        try {
            $this->videoFailure->fail(
                $message->getVideoId(),
                $message->getVideoStatusForCurrentProcessStepEntity(),
                $event->getThrowable()->getMessage(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'FailedStepSubscriber: Video konnte nicht auf FAIL gesetzt werden: ' . $e->getMessage(),
            );
        }
    }
}
