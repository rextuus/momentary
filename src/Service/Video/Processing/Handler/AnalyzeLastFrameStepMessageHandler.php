<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractAnalyzeFrameStepMessageHandler;
use App\Service\Video\Processing\Message\AnalyzeLastFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 1)]
class AnalyzeLastFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        VideoAnalyzer $videoAnalyzer,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService, $videoAnalyzer);
    }

    public function __invoke(AnalyzeLastFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();


        // Special-Case: There is already no frame to analyze left
        $successMsg = sprintf(
            'All frames for video %d analyzed already successfully. Nothing to do here.',
            $video->getId()
        );

        if ($message->getFramePath() !== null) {
            $framePath = $this->videoAnalyzer->resolvePath($message->getFramePath());

            $this->videoAnalyzer->analyzeFrame(
                $message->getVideoId(),
                $framePath,
                $message->getTimestamp()
            );

            $successMsg = sprintf(
                'Last frame for video %d analyzed successfully.',
                $video->getId()
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}
