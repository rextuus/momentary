<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractExtractSceneThumbnailStepMessageHandler;
use App\Service\Video\Processing\Message\ExtractLastSceneThumbnailStepMessage;
use App\Service\Video\Processing\Message\ExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 4)]
class ExtractSceneThumbnailStepMessageHandler extends AbstractExtractSceneThumbnailStepMessageHandler
{
    private int $currentSceneNumber = 1;
    private int $totalSceneNumber = 1;

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

    public function __invoke(ExtractSceneThumbnailStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $this->currentSceneNumber = $message->getProcessedScenes() + 1;
        $this->totalSceneNumber = $message->getTotalScenes() + 1;

        $video = $this->getVideo();
        $this->generateThumbnail($message);

        // prepare the next scene
        $sceneIds = $message->getRemainingSceneIds();
        $this->nextSceneId = array_shift($sceneIds);
        $this->remainingSceneIds = $sceneIds;

        // Normal-Case: There is still a scene but no remaining ones => we dispatch same type of message again
        if ($sceneIds !== []){
            $logMessage = sprintf(
              'Decorated scene with id "%s" with thumbnail. Remaining Scenes for video with id "%s": %d',
                $message->getSceneId(),
                $video->getId(),
                count($sceneIds)
            );
            $this->dispatchNextMessageOfCurrentStep($logMessage);

            return;
        }

        // We have only one scene left => we dispatch the lastScene message, and its handler will do process it
        $successMsg = sprintf(
            'All scenes for video with id "%s" successfully thumbnail decorate. Next step will process the last scene',
            $video->getId()
        );

        // Special-Case: Video has only one single scene => we dispatch the lastScene message, but its handler will do nothing
        if ($this->nextSceneId === null) {
            $successMsg = sprintf(
                'All scenes for video with id "%s" successfully thumbnail decorate. Next step will immoderately finish',
                $video->getId()
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var ExtractLastSceneThumbnailStepMessage $nextStepMessage */
        $nextStepMessage->setSceneId($this->nextSceneId);
        $nextStepMessage->setTotalScenes($this->totalSceneNumber);
        $nextStepMessage->setProcessedScenes($this->currentSceneNumber);
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var ExtractSceneThumbnailStepMessage $nextStepMessage */
        $nextStepMessage->setSceneId($this->nextSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
        $nextStepMessage->setTotalScenes($this->totalSceneNumber);
        $nextStepMessage->setProcessedScenes($this->currentSceneNumber);
    }
}
