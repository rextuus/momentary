<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting\Scene;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Splitting\AbstractSplitInFramesStepMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitLastSceneInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
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
        BetterVideoAnalyzer $videoAnalyzer,
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
        $this->startCurrentStep();

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

        // store collection form message in handler
        $this->framePathCollection = $message->getFramePathCollection();
        // append the frames to list
        $this->addFramesToAnalyzingStep($frameSplitResult, $scene->getStartSeconds());

        $successMsg = sprintf(
            'Added %d frames for analysis for scene %d of video "%d" to global frame array. There are no more scenes to split. Go to refinement analyzing.  Collected already: %d frames',
            $frameSplitResult->getFrameCount(),
            $scene->getId(),
            $video->getTitle(),
            count($this->framePathCollection)
        );

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentFrame($this->firstFramePath);
        $nextStepMessage->setRemainingFrames($this->framePathCollection);
    }
}
