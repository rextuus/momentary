<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze\Scene;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\FrameAnalyze\AbstractAnalyzeFrameStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\Scene\AnalyzeSceneFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeLastVideoFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeVideoFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 15)]
class AnalyzeSceneFrameStepMessageHandler extends AbstractAnalyzeFrameStepMessageHandler
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

    public function __invoke(AnalyzeSceneFrameStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        // Special-Case: There was only one frame which was already analyzed in the firstFrameMessage
        if ($message->getCurrentFrame() === null){
            $successMsg = sprintf(
                'Video %d contains only one frame. This is already analyzed. Go to final frame analyze step. Next message will be immediately set to finished',
                $video->getId()
            );
            $this->finishCurrentStep($successMsg);

            return;
        }

        // Special-Case: This is already last frame
        if ($message->getRemainingFrames() === []) {
            $successMsg = sprintf(
                'All frames for video %d analyzed already successfully. Next message will be immediately set to finished',
                $video->getId()
            );

            $this->analyzeFrame($message);

            $this->finishCurrentStep($successMsg);

            return;
        }

        // go on with next ones otherwise
        $this->frame = $message->getCurrentFrame();

        $this->analyzeFrame($message);
        $oldFrame = $this->frame;

        $remainingFrames = $message->getRemainingFrames();
        $this->frame = array_shift($remainingFrames);
        $this->remainingFrames = $remainingFrames;

        if ($this->remainingFrames === []){
            $successMsg = sprintf(
                'Frame #? of Video "%s" at timestamp "%d" analyzed. Only 1 left. Dispatch final message',
                $video->getTitle(),
                $oldFrame['timestamp']
            );
            $remainingFrames = $message->getRemainingFrames();
            $this->frame = array_shift($remainingFrames);
            $this->remainingFrames = $remainingFrames;

            $this->finishCurrentStep($successMsg);

            return;
        }

        $successMsg = sprintf(
            'Frame #? of Video "%s" at timestamp "%d" analyzed. %d left',
            $video->getTitle(),
            $oldFrame['timestamp'],
            count($this->remainingFrames)
        );

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