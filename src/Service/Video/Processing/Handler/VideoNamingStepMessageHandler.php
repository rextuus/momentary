<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\VideoNamingStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\VideoNamingService;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 23)]
class VideoNamingStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoNamingService $videoNamingService,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(VideoNamingStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $newName = $this->videoNamingService->suggestName($video);
        $video->setTitle($newName);

        $this->finishCurrentStep(sprintf('Video named to "%s".', $video->getTitle()));
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void {}
}
