<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractExtractSceneThumbnailStepMessageHandler;
use App\Service\Video\Processing\Message\ExtractLastSceneThumbnailStepMessage;
use App\Service\Video\Processing\Message\SplitInFramesStepMessage;
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

        // Special-Case Video has only one scene: Finish step immediately
        if ($message->getSceneId() === null) {
            $video = $this->getVideo();

            $successMsg = sprintf(
                'Video with id "%s" seems to contain only 1 Scene!! All scenes successfully thumbnail decorated.',
                $video->getId()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $this->generateThumbnail($message);

        $video = $this->getVideo();
        $successMsg = sprintf(
            'Video with id "%s" was completely decorated with scene thumbnail: %d scenes decorated!',
            $video->getId(),
            $message->getSceneId()
        );
        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var SplitInFramesStepMessage $nextStepMessage */
        $nextStepMessage->videoPath = "";
    }
}
