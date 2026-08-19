<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\SceneDetectionStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\VideoSceneService;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\VideoFileService;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 2)]
class SceneDetectionStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly BetterVideoAnalyzer $videoAnalyzer,
        private readonly VideoFileService $videoFileService,
        private readonly VideoSceneService $videoSceneService
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(SceneDetectionStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $sourceFile = $video->getSourceFile();
        if ($sourceFile === null) {
            $this->stopProcessing("Source file key is missing for video: " . $video->getId());
            return;
        }

        $videoPath = $this->videoFileService->getAbsolutePath($sourceFile);

        if (!file_exists($videoPath)) {
            $this->stopProcessing("Source video for scene detection not found: $videoPath");

            return;
        }

        $scenes = $this->videoAnalyzer->detectScenes(
            $videoPath,
            $message->getVideoId()
        );

        // Save scenes
        $this->videoSceneService->storeScenes($video, $scenes);

        if($scenes === []){
            $errorMsg = sprintf(
                '[⚠] Splitting Scenes for video "%s" failed! 0 scenes added to DB!',
                $video->getTitle()
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        $successMsg = sprintf(
            'Splitting Scenes for video "%s" successfully! %d scenes added to DB!',
            $video->getTitle(),
            count($scenes)
        );
        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}