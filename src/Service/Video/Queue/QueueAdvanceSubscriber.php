<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Stößt nach jedem Video-Step die Import-Queue an, damit freie Slots
 * (fertig, fehlgeschlagen, Video gelöscht) sofort neu belegt werden.
 */
final class QueueAdvanceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ImportQueueManager $importQueueManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onWorkerEvent',
            WorkerMessageFailedEvent::class => 'onWorkerEvent',
        ];
    }

    public function onWorkerEvent(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        if (!$event->getEnvelope()->getMessage() instanceof VideoProcessStepMessageInterface) {
            return;
        }

        try {
            $this->importQueueManager->processQueue();
        } catch (\Throwable $e) {
            $this->logger->warning('QueueAdvanceSubscriber: processQueue fehlgeschlagen: ' . $e->getMessage());
        }
    }
}