<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;

abstract class AbstractSplitInFramesStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?string $firstFramePath = null;
    protected array $remainingFramePaths = [];

    protected ?int $currentScene = null;
    protected array $remainingScene = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected readonly VideoAnalyzer $videoAnalyzer,
        protected readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function prepareFramesForAnalyzing(FrameSplittingResult $frameSplitResult, float $startTime, string $successMsg){

        $preparedFrames = [];
        foreach ($frameSplitResult->getFrameList() as $index => $frame) {
            $timestamp = (int) $frame['timestamp'];
            $timestamp += $startTime;

            $preparedFrames[] = [
                'path' => $frame['path'],
                'timestamp' => $timestamp,
                'isLast' => false,
            ];
        }
        $this->firstFramePath = array_shift($preparedFrames);
        $this->remainingFramePaths = $preparedFrames;

        $this->finishCurrentStep($successMsg);
    }

    protected function addFramesToAnalyzingStep(
        FrameSplittingResult $frameSplitResult,
        float $startTime,
        string $successMsg
    ): void {
        $preparedFrames = [];
        foreach ($frameSplitResult->getFrameList() as $frame) {
            $timestamp = (int) $frame['timestamp'];
            $timestamp += $startTime;

            $preparedFrames[] = [
                'path' => $frame['path'],
                'timestamp' => $timestamp,
                'isLast' => false,
            ];
        }

        if ($this->firstFramePath === null){
            $this->firstFramePath = array_shift($preparedFrames);
        }
        $this->remainingFramePaths = array_merge($this->remainingFramePaths, $preparedFrames);

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        // TODO: Implement decorateNextStepMessage() method.
    }


}
