<?php

namespace App\MessageHandler;

use App\Message\TagScenesMessage;
use App\Message\AnalyzeSceneMessage;
use App\Repository\VideoRepository;
use App\Service\WorkflowMachine;
use App\Enum\VideoStatus;
use App\Service\VideoProcessingService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class TagScenesMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly WorkflowMachine $workflowMachine,
        #[Target('tagging')] private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly VideoProcessingService $processingService
    ) {
    }

    public function __invoke(TagScenesMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }

        if ($this->workflowMachine->can($video, 'start_tagging')) {
            $this->workflowMachine->apply($video, 'start_tagging');
            $this->processingService->startStep($video, VideoStatus::TAGGING_SCENES);
        }

        $scenes = $video->getScenes();
        $this->logger->info("Dispatching " . count($scenes) . " scenes for video " . $video->getId());
        
        foreach ($scenes as $scene) {
            $this->logger->info("Dispatching AnalyzeSceneMessage for scene " . $scene->getId());
            $this->messageBus->dispatch(new AnalyzeSceneMessage($scene->getId()));
        }
    }
}
