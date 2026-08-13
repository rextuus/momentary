<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Scene;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitLastSceneInFramesStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 12)]
class SplitSceneInFramesStepMessageHandler extends AbstractSplitInFramesStepMessageHandler
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

    public function __invoke(SplitSceneInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();
        $this->processingService->startStep($video, $processStepStatus);

        // there is no scene => no refinment needed
        if ($message->getCurrentSceneId() === null) {
            $successMsg = sprintf(
                'No more scenes for refinement found for video with id %s. Next step will be skipped.',
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
            'Split scene with id "%s" into %d frames in path "%s"',
            $scene->getId(),
            $frameSplitResult->getFrameCount(),
            $frameSplitResult->getFrameDirPath()
        );

        // append the frames to list
        $this->addFramesToAnalyzingStep($frameSplitResult, $scene->getStartSeconds(), $successMsg);

        // check if there are more scenes needing refining
        $remainingScenes = $this->remainingSceneIds;
        $this->currentSceneId = array_shift($remainingScenes);
        $this->remainingSceneIds = $remainingScenes;

        if ($this->remainingSceneIds === []){
            $successMsg = sprintf(
                'Added %d frames for analysis for scene %d of video %d to global frame array. There are no more scenes to split. %',
                $frameSplitResult->getFrameCount(),
                $scene->getId(),
                $video->getId(),
                count($this->remainingSceneIds)
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $logMessage = sprintf(
            'Added %d frames for analysis for scene %d of video %d to global frame array. There are still %d scenes we need to split. %',
            $frameSplitResult->getFrameCount(),
            $scene->getId(),
            $video->getId(),
            count($this->remainingSceneIds)
        );

        $this->dispatchNextMessageOfCurrentStep($logMessage);
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var SplitSceneInFramesStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);

        // we will pack all the frames of all scenes into one big array
        $nextStepMessage->setFramePathCollection($this->framePathCollection);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var SplitLastSceneInFramesStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);

        // we will pack all the frames of all scenes into one big array
        $nextStepMessage->setFramePathCollection($this->framePathCollection);
    }
}
