<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\ThumbnailExtraction;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\ThumbnailExtraction\ExtractLastSceneThumbnailStepMessage;
use App\Service\Video\Processing\Message\ThumbnailExtraction\ExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 4)]
class ExtractSceneThumbnailStepMessageHandler extends AbstractExtractSceneThumbnailStepMessageHandler
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT_SCENE_THUMBNAIL';

    private int $currentSceneNumber = 1;
    private int $totalSceneNumber = 1;

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        VideoSceneRepository $videoSceneRepository,
        BetterVideoAnalyzer $videoAnalyzer,
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
        $this->startCurrentStep();

        $currentSceneId = $this->nextSceneId;
        $thumbnailGenerationError = $this->generateThumbnail($message);

        if ($thumbnailGenerationError !== null){
            $errorMsg = sprintf(
                '[⚠] Error during thumbnail generation for video "%s" in scene: %d: %s',
                $video->getTitle(),
                $currentSceneId,
                $thumbnailGenerationError
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // prepare the next scene
        $sceneIds = $message->getRemainingSceneIds();
        $this->nextSceneId = array_shift($sceneIds);
        $this->remainingSceneIds = $sceneIds;

        // Normal-Case: There is still a scene but no remaining ones => we dispatch same type of message again
        if ($sceneIds !== []){
            $logMessage = sprintf(
              'Decorated scene #%d with thumbnail. Remaining Scenes for video with id "%s": %d',
                $message->getCurrentSceneId(),
                $video->getTitle(),
                count($sceneIds)
            );
            $this->dispatchNextMessageOfCurrentStep($logMessage);

            return;
        }

        // We have only one scene left => we dispatch the lastScene message, and its handler will do process it
        $successMsg = sprintf(
            'Decorated scene #%d with thumbnail. Nearly all scenes for video "%s" successfully thumbnail decorate. Next step will process the last scene',
            $message->getCurrentSceneId(),
            $video->getTitle()
        );

        // Special-Case: Video has only one single scene => we dispatch the lastScene message, but its handler will do nothing
        if ($this->nextSceneId === null) {
            $successMsg = sprintf(
                'All scenes for video "%s" successfully thumbnail decorate. Next step will immoderately finish',
                $video->getTitle()
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var ExtractLastSceneThumbnailStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->nextSceneId);
        $nextStepMessage->setTotalScenes($this->totalSceneNumber);
        $nextStepMessage->setProcessedScenes($this->currentSceneNumber);
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var ExtractSceneThumbnailStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->nextSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
        $nextStepMessage->setTotalScenes($this->totalSceneNumber);
        $nextStepMessage->setProcessedScenes($this->currentSceneNumber);
    }

    public function isIntermediateStep(): bool
    {
        return true;
    }
}
