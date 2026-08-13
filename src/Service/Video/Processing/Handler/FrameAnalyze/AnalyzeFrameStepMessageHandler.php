<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 1)]
class AnalyzeFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
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

    public function __invoke(AnalyzeFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();

        // Special-Case: There was only one frame which was already analyzed in the firstFrameMessage
        if ($message->getFramePath() === null){
            $successMsg = sprintf(
                'Video %d contains only one frame. This is already analyzed. Go to final frame analyze step. Next message will be immediately set to finished',
                $video->getId()
            );
            $this->finishCurrentStep($successMsg);

            return;
        }

        $framePath = $this->videoAnalyzer->resolvePath($message->getFramePath());

        // Special-Case: This is already last frame
        if ($message->getRemainingFrames() === []) {
            $successMsg = sprintf(
                'All frames for video %d analyzed already successfully. Next message will be immediately set to finished',
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
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->framePath);
        $nextStepMessage->setRemainingFrames($this->remainingFrames);
    }
}
