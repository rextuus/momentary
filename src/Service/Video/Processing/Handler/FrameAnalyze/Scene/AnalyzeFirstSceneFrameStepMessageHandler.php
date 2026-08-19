<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze\Scene;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\FrameAnalyze\AbstractAnalyzeFrameStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\Scene\AnalyzeFirstSceneFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Scene\AnalyzeSceneFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 14)]
class AnalyzeFirstSceneFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        BetterVideoAnalyzer $videoAnalyzer,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService, $videoAnalyzer);
    }

    public function __invoke(AnalyzeFirstSceneFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        // Special-Case: There is no frame=> Finish this step => next handler will immediately go on
        if ($this->frame === null) {
            $this->finishCurrentStep('Video has no frames');

            return;
        }

        // Special-Case: This is the only Frame => Finish this step => next handler will immediately go on
        if (count($message->getRemainingFrames()) === 1) {
            $framePaths = $message->getRemainingFrames();
            $this->analyzeFrame($message);

            $this->frame = array_shift($framePaths);
            $this->remainingFrames = $framePaths;

            $successMsg = sprintf(
                'Video %d contains only one frame. Analyzed this successfully',
                $video->getId()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $this->frame = $message->getCurrentFrame();

        // analyze the first frame
        $this->analyzeFrame($message);
        $successMsg = sprintf(
            'Frame #1 of Video "%s" at timestamp "%d" analyzed. %d left',
            $video->getTitle(),
            $this->frame['timestamp'],
            count($message->getRemainingFrames())
        );

        // prepare the next
        $remainingFrames = $message->getRemainingFrames();
        $this->frame = array_shift($remainingFrames);
        $this->remainingFrames = $remainingFrames;

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeSceneFrameStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentFrame($this->frame);
        $nextStepMessage->setRemainingFrames($this->remainingFrames);
    }
}
