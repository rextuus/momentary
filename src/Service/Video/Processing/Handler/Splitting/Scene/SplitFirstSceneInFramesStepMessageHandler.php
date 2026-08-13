<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Scene;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitFirstSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 11)]
class SplitFirstSceneInFramesStepMessageHandler extends AbstractSplitInFramesStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        VideoAnalyzer $videoAnalyzer,
        EntityManagerInterface $entityManager,
        private readonly VideoSceneRepository $sceneRepository
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

    public function __invoke(SplitFirstSceneInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();
        $this->processingService->startStep($video, $processStepStatus);

        // there is no scene => no refinment needed
        if ($message->getSceneId() === null) {
            $successMsg = sprintf(
                'No scenes for refinement found for video with id %s. Next two steps will be skipped.',
                $video->getId()
            );
            $this->finishCurrentStep($successMsg);

            return;
        }

        $localVideoPath = $this->videoAnalyzer->resolvePath($video->getLocalPath());

        if (!file_exists($localVideoPath)) {
            $errorMsg = sprintf(
                'Video file for vide-entity with id "%s" not found at "%s"',
                $video->getId(),
                $localVideoPath
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // split the current scene
        $scene = $this->sceneRepository->find($message->getSceneId());
        $startTime = $scene->getStartSeconds();
        $endTime = $scene->getEndSeconds();

        $frameSplitResult = $this->videoAnalyzer->extractFrames(
            $message->getVideoId(),
            $localVideoPath,
            $video->getAnalysisFps(),
            $startTime,
            $endTime - $startTime
        );

        $successMsg = sprintf(
            'Split scene with id "%s" into %d frames in path "%s"',
            $scene->getId(),
            $frameSplitResult->getFrameCount(),
            $frameSplitResult->getFrameDirPath()
        );
        $this->prepareFramesForAnalyzing($frameSplitResult, $startTime, $successMsg);

        // check if there are more scenes needing refining
        $remainingScenes = $this->remainingScene;
        $nextScene = array_shift($remainingScenes);
        $this->remainingScene = $remainingScenes;

        $successMsg = sprintf(
            'First scene (%d) for video with id %d split into frames. Go on with next one',
            $scene->getId(),
            $video->getId(),
        );
        if ($nextScene === null) {
            $successMsg = sprintf(
                'Last scene (%d) for video with id %d split into frames',
                $scene->getId(),
                $video->getId(),
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->firstFramePath);
        $nextStepMessage->setRemainingFrames($this->remainingFramePaths);
    }
}
