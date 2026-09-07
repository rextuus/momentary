<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
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
        private readonly FileManager $fileManager,
        private readonly FileStorageService $fileStorageService,
        private readonly StoragePathProvider $pathProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(ConvertStepMessage $message): void
    {
        $video = $this->initHandler($message);

        $sourceFileEntity = $video->getSourceFile();
        if ($sourceFileEntity === null) {
            $this->stopProcessing(sprintf('[⚠] Video "%s" has no source file assigned.', $video->getId()));
            return;
        }

        $sourceAbsolutePath = $this->pathProvider->getStorageRoot() . '/' . $sourceFileEntity->getRelativePath();

        if (!file_exists($sourceAbsolutePath)) {
            $this->stopProcessing(
                sprintf(
                    '[⚠] Video "%s" source file could not be found in storage: "%s".',
                    $video->getId(),
                    $sourceFileEntity->getRelativePath()
                )
            );
            return;
        }

        // Wenn es bereits eine MP4-Datei ist, überspringen wir die Konvertierung
        if (str_ends_with(strtolower($sourceAbsolutePath), '.mp4')) {
            $this->finishCurrentStep(sprintf('[i] Video "%s" is already MP4.', $video->getTitle()));
            return;
        }

        // Temporäre Datei für die Konvertierung im Storage ablegen (z.B. im Verzeichnis des Videos)
        $tempFilename = 'converted_' . uniqid() . '.mp4';
        $tempRelativePath = 'videos/' . $video->getId() . '/' . $tempFilename;
        $tempAbsolutePath = $this->pathProvider->getStorageRoot() . '/' . $tempRelativePath;

        // Sicherstellen, dass das Verzeichnis existiert
        $this->fileStorageService->createDirectoryStructure('videos/' . $video->getId());

        if ($this->videoAnalyzer->convertToMp4($sourceAbsolutePath, $tempAbsolutePath)) {
            // 1. Vorab prüfen, ob bereits ein File-Eintrag mit diesem Pfad existiert, um Duplicate Entry zu vermeiden
            $existingFile = $this->entityManager->getRepository(\App\Entity\File::class)->findOneBy(['relativePath' => $tempRelativePath]);
            if ($existingFile) {
                $this->fileManager->deleteFile($existingFile);
            }

            // 2. File-Entity für das konvertierte Video erstellen
            $convertedFile = $this->fileManager->createConvertedVideoFile($video, $tempFilename);
            $convertedFile->setFileSize($this->fileManager->getFileSize($convertedFile));
            $this->fileManager->saveFile($convertedFile);

            // 3. Am Video als convertedFile setzen
            $video->setConvertedFile($convertedFile);

            // Optional: Wenn das konvertierte Video ab sofort die neue Hauptquelle sein soll:
            // $video->setSourceFile($convertedFile);

            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $this->finishCurrentStep(sprintf('Conversion for video "%s" succeeded', $video->getTitle()));
            return;
        }

        $this->stopProcessing(sprintf('[⚠] Conversion for video "%s" failed', $video->getTitle()));
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}