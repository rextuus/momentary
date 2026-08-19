<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\VideoFileService;
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
        private readonly BetterVideoAnalyzer $videoAnalyzer,
        private readonly VideoFileService $videoFileService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(ConvertStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $sourceFile = $video->getSourceFile();
        if ($sourceFile === null || !$this->videoFileService->getFilesystem()->fileExists($sourceFile)) {
            $errorMsg = sprintf(
                '[⚠] Video "%s" source file could not be found in storage: "%s".',
                $video->getId(),
                $sourceFile ?? 'null'
            );
            $this->stopProcessing($errorMsg);

            return;
        }

        // Absoluten Pfad für FFMpeg etc. über den VideoFileService holen
        $sourcePath = $this->videoFileService->getAbsolutePath($sourceFile);

        // if already mp4, skip conversion
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            $successMsg = sprintf('[i] Video "%s" is already MP4.', $video->getTitle());
            $this->finishCurrentStep($successMsg);

            return;
        }

        $tempMp4Name = 'video_converted_' . $video->getId() . '.mp4';
        // Für temporäre Konvertierungen nutzen wir ebenfalls den VideoFileService oder den Projektpfad
        $tempMp4 = $this->videoFileService->getAbsolutePath($tempMp4Name);

        if ($this->videoAnalyzer->convertToMp4($sourcePath, $tempMp4)) {
            // Speichere den neuen Dateinamen als konvertierte Datei (nur Key, kein absoluter Pfad)
            $video->setConvertedFilename($tempMp4Name);
            // Wenn das konvertierte Video ab jetzt die Quelle ist, können wir sourceFile anpassen
            // oder den Pfad im FileService verwalten. Hier setzen wir den neuen Dateinamen als sourceFile:
            $video->setSourceFile($tempMp4Name);

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