<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Scene;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitFirstSceneInFramesStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
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
        BetterVideoAnalyzer $videoAnalyzer,
        StoragePathProvider $pathProvider,
        EntityManagerInterface $entityManager,
        private readonly VideoSceneRepository $sceneRepository
    ) {
        parent::__construct(
            $videoRepository,
            $dispatcher,
            $workflowMachine,
            $processingService,
            $videoAnalyzer,
            $pathProvider,
            $entityManager
        );
    }

    public function __invoke(SplitFirstSceneInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $this->startCurrentStep();

        // there is no scene => no refinment needed
        if ($message->getCurrentSceneId() === null) {
            $successMsg = sprintf(
                'No scenes for refinement found for video with id %s. Next two steps will be skipped.',
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

        // add the first frames to global array
        $this->prepareFirstFramesForAnalyzing($frameSplitResult, $scene->getStartSeconds());

        // check if there are more scenes needing refining
        $remainingScenes = $message->getRemainingSceneIds();
        $this->currentSceneId = array_shift($remainingScenes)->getId();
        $this->remainingSceneIds = $remainingScenes;

        $successMsg = sprintf(
            'First Scene split. Added %d frames for analysis for scene %d of video "%s" to global frame array. There are still %d scenes we need to split. Collected already: %d frames',
            $frameSplitResult->getFrameCount(),
            $scene->getId(),
            $video->getTitle(),
            count($this->remainingSceneIds) + 1,
            count($this->framePathCollection)
        );
        if ($this->currentSceneId === null) {
            $successMsg = sprintf(
                'Last scene (%d) for video "%s" split into frames',
                $scene->getId(),
                $video->getTitle(),
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var SplitSceneInFramesStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);

        // we will pack all the frames of all scenes into one big array
        $nextStepMessage->setFramePathCollection($this->framePathCollection);
    }
}
