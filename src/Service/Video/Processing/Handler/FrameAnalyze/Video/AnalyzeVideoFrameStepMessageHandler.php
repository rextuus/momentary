<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze\Video;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\FrameAnalyze\AbstractAnalyzeFrameStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeLastVideoFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeVideoFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 1)]
class AnalyzeVideoFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
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

    public function __invoke(AnalyzeVideoFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $analyzed = $this->analyzeCurrentAndPrepareNext($message);
        if ($analyzed === null) {
            $this->finishCurrentStep(sprintf('No frames left for video "%s".', $video->getTitle()));

            return;
        }

        $successMsg = sprintf(
            'Frame of video "%s" at timestamp %d analyzed. %d left.',
            $video->getTitle(),
            $analyzed->getTimestamp(),
            $this->countFramesLeft(),
        );

        // Höchstens noch ein Frame übrig: den übernimmt der abschließende Schritt
        if ($this->remainingFrames === []) {
            $this->finishCurrentStep($successMsg);

            return;
        }

        $this->dispatchNextMessageOfCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeLastVideoFrameStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentFrame($this->frame);
        $nextStepMessage->setRemainingFrames($this->remainingFrames);
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeVideoFrameStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentFrame($this->frame);
        $nextStepMessage->setRemainingFrames($this->remainingFrames);
    }
}
