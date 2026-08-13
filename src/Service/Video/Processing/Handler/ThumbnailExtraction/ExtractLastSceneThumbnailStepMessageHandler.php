<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\ThumbnailExtraction;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Splitting\Video\SplitVideoInFramesStepMessage;
use App\Service\Video\Processing\Message\ThumbnailExtraction\ExtractLastSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 5)]
class ExtractLastSceneThumbnailStepMessageHandler extends AbstractExtractSceneThumbnailStepMessageHandler
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT_LAST_SCENE_THUMBNAIL';

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

    public function __invoke(ExtractLastSceneThumbnailStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        // Special-Case Video has only one scene: Finish step immediately
        if ($message->getCurrentSceneId() === null) {
            $video = $this->getVideo();

            $successMsg = sprintf(
                'Video "%s" seems to contain only 1 Scene!! All scenes successfully thumbnail decorated.',
                $video->getTitle()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $this->generateThumbnail($message);

        $successMsg = sprintf(
            'Decorated scene #%d with thumbnail. Video "%s" was completely decorated with scene thumbnail: %d scenes decorated!',
            $message->getCurrentSceneId(),
            $video->getTitle(),
            $message->getCurrentSceneId()
        );
        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
//        /** @var SplitVideoInFramesStepMessage $nextStepMessage */
//        $nextStepMessage->videoPath = "";
    }

    public function isIntermediateStep(): bool
    {
        return true;
    }
}
