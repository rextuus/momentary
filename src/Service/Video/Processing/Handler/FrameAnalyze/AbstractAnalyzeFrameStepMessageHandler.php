<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\FrameAnalyze;

use App\Dto\VideoFrame;
use App\Repository\VideoRepository;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;

abstract class AbstractAnalyzeFrameStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?VideoFrame $frame = null;
    /** @var list<VideoFrame> */
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

    protected function resetState(): void
    {
        $this->frame = null;
        $this->remainingFrames = [];
    }

    /**
     * Analysiert den aktuellen Frame der Message und legt den nächsten Frame samt Rest für die Folge-Message ab.
     *
     * Der Zustand wird bei jeder Message vollständig neu gesetzt: Handler leben im Worker über viele
     * Messages hinweg, ein Rest aus der vorherigen Message würde sonst falsche Frames weiterreichen.
     *
     * @return VideoFrame|null der analysierte Frame, null wenn die Message keinen mehr enthielt
     */
    protected function analyzeCurrentAndPrepareNext(AbstractAnalyzeFrameStepMessage $message): ?VideoFrame
    {
        $current = $message->getCurrentFrame();
        $remaining = $message->getRemainingFrames();
        $this->frame = array_shift($remaining);
        $this->remainingFrames = $remaining;

        if ($current !== null) {
            $path = $current->getPath();
            $this->videoAnalyzer->analyzeFrame(
                $message->getVideoId(),
                str_starts_with($path, '/') ? $path : $this->videoAnalyzer->getAbsolutePath($path),
                $current->getTimestamp(),
            );
            $this->recordItemDone($this->countFramesLeft());
        }

        return $current;
    }

    protected function countFramesLeft(): int
    {
        return \count($this->remainingFrames) + ($this->frame === null ? 0 : 1);
    }
}
