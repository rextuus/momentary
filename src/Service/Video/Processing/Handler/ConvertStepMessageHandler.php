<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 1)]
class ConvertStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(ConvertStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $localPath = $video->getLocalPath();
        if ($localPath === null) {
            $errorMsg = sprintf(
                '[⚠] Video "%s" could not be found in path: "%s".',
                $video->getId(),
                $localPath
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);

        // if already mp4, skip conversion
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            $successMsg = sprintf('[i] Video "%s" is already MP4.', $video->getTitle());
            $this->finishCurrentStep($successMsg);

            return;
        }

        $tempMp4Name = 'video_converted_' . $video->getId() . '.mp4';
        $tempMp4 = $this->videoAnalyzer->getProjectDir() . '/public/uploads/import/' . $tempMp4Name;

        if ($this->videoAnalyzer->convertToMp4($sourcePath, $tempMp4)) {
            $video->setConvertedVideoPath($tempMp4);
            $video->setLocalPath($tempMp4);
            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $successMsg = sprintf('Conversion for video "%s" succeeded', $video->getTitle());
            $this->finishCurrentStep($successMsg);

            return;
        }

        $errorMsg = sprintf('[⚠] Conversion for video "%s" failed', $video->getTitle());
        $this->stopProcessing($errorMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}
