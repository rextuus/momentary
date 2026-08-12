<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\SceneDetectionStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
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
        private readonly VideoAnalyzer $videoAnalyzer
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(SceneDetectionStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();

        $this->processingService->startStep($video, $processStepStatus);

        echo "Starte Asynchrone Szenenerkennung für Video {$message->getVideoId()}..." . PHP_EOL;

        $videoPath = $this->videoAnalyzer->resolvePath($video->getLocalPath());

        if (!file_exists($videoPath)) {
            $this->stopProcessing("Source video for scene detection not found: $videoPath");

            return;
        }


        $scenes = $this->videoAnalyzer->detectScenes(
            $videoPath,
            $message->getVideoId()
        );

        // Save scenes
        $this->videoAnalyzer->storeScenes($message->getVideoId(), $scenes);

        if($scenes === []){
            $errorMsg = sprintf(
                'Szenen-Splitting für Video %s nicht erfolgreich abgeschlossen! 0 Szenen in DB verewigt.',
                $video->getId()
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        $successMsg = sprintf(
            'Szenen-Splitting für Video %s erfolgreich abgeschlossen! %d Szenen in DB verewigt.',
            $video->getId(),
            count($scenes)
        );
        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}
