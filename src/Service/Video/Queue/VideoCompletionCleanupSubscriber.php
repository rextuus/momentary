<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

use App\Repository\VideoRepository;
use App\Service\Storage\VideoOriginalCleanupService;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Nach dem letzten Step der Verarbeitungskette: Original und Frames aufräumen.
 */
final class VideoCompletionCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoOriginalCleanupService $cleanupService,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageHandledEvent::class => 'onHandled'];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof VideoProcessStepMessageInterface || $message->getNextStepMessageClass() !== null) {
            return; // nur der letzte Step der Kette
        }

        try {
            $video = $this->videoRepository->find($message->getVideoId());
            if ($video !== null) {
                $this->cleanupService->cleanupCompleted($video);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('VideoCompletionCleanupSubscriber: Cleanup fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
