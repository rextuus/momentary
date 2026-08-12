<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractExtractSceneThumbnailStepMessageHandler;
use App\Service\Video\Processing\Message\ExtractFirstSceneThumbnailStepMessage;
use App\Service\Video\Processing\Message\ExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 3)]
class ExtractFirstSceneThumbnailStepMessageHandler extends AbstractExtractSceneThumbnailStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        VideoSceneRepository $videoSceneRepository,
        VideoAnalyzer $videoAnalyzer,
        EntityManagerInterface $entityManager,
    ) {
        parent::__construct(
            $videoRepository,
            $dispatcher,
            $workflowMachine,
            $processingService,
            $videoSceneRepository,
            $videoAnalyzer,
            $entityManager,
        );
    }

    public function __invoke(ExtractFirstSceneThumbnailStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();

        $this->processingService->startStep($video, $processStepStatus);

        echo "Starte Asynchrone Szenen-Thumbnail-Generierung für Video {$message->getVideoId()}..." . PHP_EOL;
        $sceneIds = array_values(array_map(fn($s) => $s->getId(), $video->getScenes()->toArray()));

        // should in fact never occur cause last step already marked chain as failed/stopped
        if ($sceneIds === []) {
            $this->stopProcessing("Abbruch: Keine Szenen für Video {$message->getVideoId()} gefunden");

            return;
        }

        $firstId = array_shift($sceneIds);
        $this->nextSceneId = $firstId;
        $this->remainingSceneIds = $sceneIds;

        $successMsg = sprintf(
            'Dispatche erste message für %d Szenen',
            count($sceneIds)
        );

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var ExtractSceneThumbnailStepMessage $nextStepMessage */
        $nextStepMessage->setSceneId($this->nextSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
        // if we come to this there should be exactly 1 scene processed
        $nextStepMessage->setProcessedScenes(1);
        $nextStepMessage->setTotalScenes(1 + count($this->remainingSceneIds));
    }
}
