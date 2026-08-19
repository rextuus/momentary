<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Analyze\EmptyScenesMerger;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\MergeScenesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 17)]
class MergeScenesStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly BetterVideoAnalyzer $betterVideoAnalyzer
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(MergeScenesStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $result = $this->betterVideoAnalyzer->mergeEmptyScenes($video);

        if (!$result->isProcessed()) {
            $errorMsg = sprintf(
                'Merging empty scenes for video "%s" skipped: %s',
                $video->getTitle(),
                $result->getSkippedReason() ?? 'Unknown reason'
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        $successMsg = sprintf(
            'Merged empty scenes for video "%s" successfully! Scenes reduced from %d to %d (%d removed).',
            $video->getTitle(),
            $result->getOriginalSceneCount(),
            $result->getMergedSceneCount(),
            $result->getRemovedSceneCount()
        );

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}
