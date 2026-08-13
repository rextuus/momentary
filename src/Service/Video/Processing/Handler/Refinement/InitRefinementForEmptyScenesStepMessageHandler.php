<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Refinement;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Refinement\InitRefinementForEmptyScenesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 10)]
class InitRefinementForEmptyScenesStepMessageHandler extends AbstractVideoMessageHandler
{
    private array $scenes = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoAnalyzer $videoAnalyzer
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(InitRefinementForEmptyScenesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();
        $this->processingService->startStep($video, $processStepStatus);

        $result = $this->videoAnalyzer->refineSceneAnalysis($video);

        $this->scenes = $result->getSceneIds();

        $successMsg = sprintf(
            'Refinement für video mit id %s initiiert. Es gibt keine Szenen die erneut analysiert werden müssen',
            $video->getId()
        );
        if ($result->getSceneCount() > 0){
            $successMsg = sprintf(
                'Refinement für video mit id %s initiiert. Es müssen %d Szenen erneut analysiert werden.',
                $video->getId(),
                $result->getSceneCount()
            );
        }

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->firstFramePath);
        $nextStepMessage->setRemainingFrames($this->remainingFramePaths);
    }
}
