<?php

namespace App\MessageHandler;

use App\Message\SplitVideoIntoFramesMessage;
use App\Service\VideoAnalyzer;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SplitVideoIntoFramesMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private WorkflowMachine $workflowMachine,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager
    ) {}

    public function __invoke(SplitVideoIntoFramesMessage $message): void
    {
        $video = $this->videoAnalyzer->getVideoRepository()->find($message->getVideoId());
        if ($video) {
            if ($this->workflowMachine->can($video, 'start_splitting')) {
                $this->workflowMachine->apply($video, 'start_splitting');
            }
            $video->setErrorMessage(null);
        }

        $localVideoPath = $this->videoAnalyzer->resolvePath($message->getLocalVideoPath());
        
        if (!file_exists($localVideoPath)) {
            $this->logger->error("Source video for frame extraction not found: $localVideoPath");
            if ($video) {
                if ($this->workflowMachine->can($video, 'fail')) {
                    $this->workflowMachine->apply($video, 'fail');
                }
                $video->setErrorMessage("Source video not found: $localVideoPath");
                $this->entityManager->flush();
            }
            return;
        }

        $this->videoAnalyzer->extractFrames($message->getVideoId(), $localVideoPath);
    }
}
