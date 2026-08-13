<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Video;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\Video\SplitVideoInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 6)]
class SplitVideoInFramesStepMessageHandler extends AbstractSplitInFramesStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        VideoAnalyzer $videoAnalyzer,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct(
            $videoRepository,
            $dispatcher,
            $workflowMachine,
            $processingService,
            $videoAnalyzer,
            $entityManager
        );
    }

    public function __invoke(SplitVideoInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $this->startCurrentStep();

        $localVideoPath = $this->resolveVideoPath($video);

        // split the complete video
        $startTime = 0;
        $frameSplitResult = $this->videoAnalyzer->extractFrames(
            $message->getVideoId(),
            $localVideoPath,
            $video->getAnalysisFps(),
            $startTime,
            $video->getDuration()
        );

        if ($frameSplitResult->getFrameCount() === 0) {
            $errorMsg = sprintf(
                'Video file for vide-entity with id "%s" was split into 0 frames',
                $video->getId()
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // prepare framelist for analyzing message queue
        $video->setTotalFrames($frameSplitResult->getFrameCount());
        $video->setProcessedFrames(0);
        $video->setCurrentFrameDirectory($frameSplitResult->getFrameDirPath());

        $this->entityManager->flush();

        $successMsg = sprintf(
            'Split video "%s" into %d frames in path "%s"',
            $video->getTitle(),
            $video->getTotalFrames(),
            $frameSplitResult->getFrameDirPath()
        );

        $this->prepareFirstFramesForAnalyzing($frameSplitResult, $startTime);
        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setRemainingFrames($this->framePathCollection);
    }
}
