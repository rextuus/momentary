<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Queue\ImportQueueManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\Event;

final class VideoWorkflowSubscriber
{
    public function __construct(
        private readonly ImportQueueManager $importQueueManager
    ) {}

    #[AsEventListener(event: 'workflow.video_processing.completed')]
    public function onWorkflowCompleted(Event $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof Video) {
            return;
        }
        
        $status = $subject->getStatus();
        if ($status === VideoStatus::COMPLETED || $status === VideoStatus::ERROR) {
            $this->importQueueManager->processQueue();
        }
    }
}
