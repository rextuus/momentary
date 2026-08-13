<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Scene;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitLastSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 13)]
class SplitLastSceneInFramesStepMessageHandler extends AbstractSplitInFramesStepMessageHandler
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

    public function __invoke(SplitLastSceneInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();
        $this->processingService->startStep($video, $processStepStatus);

        // Special case < 2 scenes => here comes null as currentScene
        if ($message->getCurrentSceneId() === null) {
            $successMsg = sprintf(
                'Last scene for video with id %d already split into frames',
                $video->getId()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $localVideoPath = $this->resolveVideoPath($video);

        // split the current scene
        $scene = $this->sceneRepository->find($message->getCurrentSceneId());

        $frameSplitResult = $this->splitSceneIntoFrames($scene, $video, $localVideoPath);
        $successMsg = sprintf(
            'Split last scene with id "%s" into %d frames in path "%s"',
            $scene->getId(),
            $frameSplitResult->getFrameCount(),
            $frameSplitResult->getFrameDirPath()
        );

        // append the frames to list
        $this->addFramesToAnalyzingStep($frameSplitResult, $scene->getStartSeconds(), $successMsg);

        $successMsg = sprintf(
            'Added %d frames for analysis for scene %d of video %d to global frame array. There are no more scenes to split. Go to refinement analyzing',
            $frameSplitResult->getFrameCount(),
            $scene->getId(),
            $video->getId()
        );

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->firstFramePath);
        $nextStepMessage->setRemainingFrames($this->remainingFramePaths);
    }
}
