<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\SplitInFramesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 6)]
class SplitInFramesStepMessageHandler extends AbstractVideoMessageHandler
{
    private string $firstFramePath;
    private array $remainingFramePaths;

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(SplitInFramesStepMessage $message): void
    {
        $this->setCurrentMessage($message);

        $video = $this->getVideo();
        $processStepStatus = $message->getVideoStatusForCurrentProcessStepEntity();
        $this->processingService->startStep($video, $processStepStatus);

        $localVideoPath = $this->videoAnalyzer->resolvePath($video->getLocalPath());

        if (!file_exists($localVideoPath)) {
            $errorMsg = sprintf(
                'Video file for vide-entity with id "%s" not found at "%s"',
                $video->getId(),
                $localVideoPath
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // split the complete video
        $startTime = 0;
        $frameSplitResult = $this->videoAnalyzer->extractFrames(
            $message->getVideoId(),
            $localVideoPath,
            $video->getAnalysisFps(),
            $startTime,
            $video->getDuration()
        );

        if ($frameSplitResult->getFrameCount() === 0) {
            $errorMsg = sprintf(
                'Video file for vide-entity with id "%s" was split into 0 frames',
                $video->getId()
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // prepare framelist for analyzing message queue
        $video->setTotalFrames($frameSplitResult->getFrameCount());
        $video->setProcessedFrames(0);
        $video->setCurrentFrameDirectory($frameSplitResult->getFrameDirPath());

        $this->entityManager->flush();

        $successMsg = sprintf(
            'Split video with id "%s" into %d frames in path "%s"',
            $video->getId(),
            $video->getTotalFrames(),
            $frameSplitResult->getFrameDirPath()
        );

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

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var AnalyzeFirstFrameStepMessage $nextStepMessage */
        $nextStepMessage->setFramePath($this->firstFramePath);
        $nextStepMessage->setRemainingFrames($this->remainingFramePaths);
    }
}
