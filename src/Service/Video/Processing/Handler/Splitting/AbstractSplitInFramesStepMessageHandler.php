<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting;

use App\Entity\Video;
use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;

abstract class AbstractSplitInFramesStepMessageHandler extends AbstractVideoMessageHandler
{
    protected array $framePathCollection = [];
    protected ?int $currentSceneId = null;
    protected array $remainingSceneIds = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected readonly BetterVideoAnalyzer $videoAnalyzer,
        protected readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function prepareFirstFramesForAnalyzing(
        FrameSplittingResult $frameSplitResult,
        float $startTime
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
        $this->framePathCollection = $preparedFrames;
    }

    protected function addFramesToAnalyzingStep(
        FrameSplittingResult $frameSplitResult,
        float $startTime
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

        $this->framePathCollection = array_merge($this->framePathCollection, $preparedFrames);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        // TODO: Implement decorateNextStepMessage() method.
    }

    protected function resolveVideoPath(Video $video): string
    {
        $sourceFile = $video->getConvertedFilename() ?? $video->getSourceFile();

        if ($sourceFile === null) {
            $this->stopProcessing(sprintf('Video file key for video-entity with id "%s" is missing.', $video->getId()));
            return '';
        }

        $localVideoPath = $this->videoAnalyzer->getAbsolutePath($sourceFile);

        if (!file_exists($localVideoPath)) {
            $errorMsg = sprintf(
                'Video file for video-entity with id "%s" not found at "%s"',
                $video->getId(),
                $localVideoPath
            );
            $this->stopProcessing($errorMsg);
        }

        return $localVideoPath;
    }

    protected function splitSceneIntoFrames(
        VideoScene $scene,
        Video $video,
        string $localVideoPath
    ): FrameSplittingResult {
        $startTime = $scene->getStartSeconds();
        $endTime = $scene->getEndSeconds();

        $result =  $this->videoAnalyzer->extractFrames(
            $video->getId(),
            $localVideoPath,
            $video->getAnalysisFps(),
            $startTime,
            $endTime - $startTime
        );

        return $result;
    }
}