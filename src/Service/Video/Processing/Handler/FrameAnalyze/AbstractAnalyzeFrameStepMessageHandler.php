<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze;

use App\Dto\VideoFrame;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Exception;

abstract class AbstractAnalyzeFrameStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?VideoFrame $frame = null;
    /** @var array<VideoFrame> */
    protected array $remainingFrames = [];
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected BetterVideoAnalyzer $videoAnalyzer,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function analyzeFrame(AbstractAnalyzeFrameStepMessage $message): void
    {
        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $this->frame->getPath(),
            $this->frame->getTimestamp()
        );
    }
}
