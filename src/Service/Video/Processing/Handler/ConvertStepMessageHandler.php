<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
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
        // Wirft VideoGoneException, falls das Video gelöscht wurde -> Message wird verworfen
        $video = $this->initHandler($message);

        $sourceFileEntity = $video->getSourceFile();
        if ($sourceFileEntity === null) {
            $this->stopProcessing(sprintf('[⚠] Video "%s" has no source file assigned.', $video->getId()));
            return;
        }

        $sourceAbsolutePath = $this->pathProvider->getAbsolutePath($sourceFileEntity);

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
            $this->finishCurrentStep(sprintf('Video "%s" is already MP4.', $video->getTitle()));
            return;
        }

        // Bereits konvertierte Datei prüfen. Die Referenz kann auf eine gelöschte File-Zeile zeigen.
        $existingConverted = $video->getConvertedFile();
        if ($existingConverted !== null) {
            $stillValid = false;
            try {
                $stillValid = file_exists($this->pathProvider->getAbsolutePath($existingConverted));
            } catch (EntityNotFoundException) {
                $stillValid = false;
            }

            if ($stillValid) {
                $this->finishCurrentStep(sprintf('Video "%s" already converted.', $video->getTitle()));
                return;
            }

            $video->setConvertedFile(null);
            $this->entityManager->flush();

            try {
                $this->entityManager->remove($existingConverted);
                $this->entityManager->flush();
            } catch (EntityNotFoundException) {
                // Zeile existiert bereits nicht mehr, nichts zu tun
            }
        }

        // File-Entity zuerst erzeugen, damit Datei und DB-Eintrag denselben Pfad nutzen
        $tempFilename = 'converted_' . uniqid() . '.mp4';
        $convertedFile = $this->fileManager->createConvertedVideoFile($video, $tempFilename);
        $this->fileStorageService->ensureDirectoryExists($convertedFile);
        $targetAbsolutePath = $this->pathProvider->getAbsolutePath($convertedFile);

        if (!$this->videoAnalyzer->convertToMp4($sourceAbsolutePath, $targetAbsolutePath)) {
            @unlink($targetAbsolutePath);
            $this->stopProcessing(sprintf('[⚠] Conversion for video "%s" failed', $video->getTitle()));
            return;
        }

        try {
            $convertedFile->setFileSize($this->fileManager->getFileSize($convertedFile));
            $this->fileManager->saveFile($convertedFile, false);

            $video->setConvertedFile($convertedFile);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            @unlink($targetAbsolutePath);
            $this->stopProcessing(sprintf('[⚠] Conversion failed: %s', $e->getMessage()));
            return;
        }

        $this->finishCurrentStep(sprintf('Conversion for video "%s" succeeded', $video->getTitle()));
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
    }
}