<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Tagging;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Tagging\TagLastSceneStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 20)]
class TagLastSceneStepMessageHandler extends AbstractTagSceneStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        TaggingService $taggingService,
        VideoSceneRepository $videoSceneRepository,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService, $taggingService, $videoSceneRepository);
    }

    public function __invoke(TagLastSceneStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $successMsg = sprintf(
            'All scenes for video %d tagged successfully.',
            $video->getId()
        );

        if ($message->getCurrentSceneId() !== null) {
            $this->currentSceneId = $message->getCurrentSceneId();
            $this->processSceneTagging($this->currentSceneId);

            $successMsg = sprintf(
                'Last scene (ID %d) for video "%s" tagged successfully.',
                $this->currentSceneId,
                $video->getTitle()
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}