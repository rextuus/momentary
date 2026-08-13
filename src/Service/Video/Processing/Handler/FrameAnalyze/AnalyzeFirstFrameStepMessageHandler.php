<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 1)]
class AnalyzeFirstFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
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

    public function __invoke(AnalyzeFirstFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();


        // Special-Case: This is the only Frame => Finish this step => next handler will immediately go on
        if (count($message->getRemainingFrames()) === 1) {
            $framePaths = $message->getRemainingFrames();
            $firstFrame = array_shift($framePaths);
            $framePath = $this->videoAnalyzer->resolvePath($firstFrame);

            $successMsg = sprintf(
                'Video %d contains only one frame. Analyzed this successfully',
                $video->getId()
            );

            $this->videoAnalyzer->analyzeFrame(
                $message->getVideoId(),
                $framePath,
                $message->getTimestamp()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        // go on with next ones otherwise
        $this->analyzeFrame($message);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->framePath);
        $nextStepMessage->setRemainingFrames($this->remainingFrames);
    }
}
