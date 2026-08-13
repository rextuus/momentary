<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;

abstract class AbstractAnalyzeFrameStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?string $framePath = null;
    protected array $remainingFrames = [];
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected VideoAnalyzer $videoAnalyzer,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function analyzeFrame(AbstractAnalyzeFrameStepMessage $message): void
    {
        $remainingFrames = $this->remainingFrames;
        $this->framePath = array_shift($remainingFrames);
        $this->remainingFrames = $remainingFrames;

        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $message->getFramePath(),
            $message->getTimestamp()
        );

        $logMessage = sprintf(
            'Analyzed frame at timestamp "%s". Remaining frames for video with id "%s": %d',
            $message->getTimestamp(),
            $this->getVideo()->getId(),
            count($this->remainingFrames)
        );
        $this->dispatchNextMessageOfCurrentStep($logMessage);
    }
}
