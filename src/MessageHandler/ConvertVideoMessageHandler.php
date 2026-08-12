<?php

namespace App\MessageHandler;

use App\Enum\VideoStatus;
use App\Message\ConvertVideoMessage;
use App\Message\DetectVideoScenesMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ConvertVideoMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private VideoRepository $videoRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private WorkflowMachine $workflowMachine,
        private VideoProcessingService $processingService
    ) {}

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(ConvertVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) return;

        if ($this->workflowMachine->can($video, 'start_conversion')) {
            $this->workflowMachine->apply($video, 'start_conversion');
            $this->processingService->startStep($video, VideoStatus::CONVERTING);
        }
        $video->setErrorMessage(null);

        // Aktuell nutzen wir die MP4-Optimierung als Konvertierung,
        // oder wir implementieren hier eine spezifische Logik.
        // Da der User meinte "wir erstellen eh schon eine mp4 version",
        // nutzen wir hier die Logik die aus beliebigen Formaten MP4 macht.
        
        $localPath = $video->getLocalPath();
        if (!$localPath) {
            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);
        
        // Wenn es schon mp4 ist, überspringen wir die eigentliche Konvertierung
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            echo "Video {$video->getId()} ist bereits MP4." . PHP_EOL;
        } else {
            echo "Starte Konvertierung für Video {$video->getId()}..." . PHP_EOL;
            
            $tempMp4Name = 'video_converted_' . $video->getId() . '.mp4';
            $tempMp4 = $this->videoAnalyzer->getProjectDir() . '/public/uploads/import/' . $tempMp4Name;
            
            if ($this->videoAnalyzer->convertToMp4($sourcePath, $tempMp4)) {
                $video->setConvertedVideoPath($tempMp4);
                $video->setLocalPath($tempMp4);
                $this->entityManager->persist($video);
                $this->entityManager->flush();
                $this->processingService->finishStep($video, VideoStatus::CONVERTING);
                $localPath = $tempMp4;
                echo "Konvertierung abgeschlossen." . PHP_EOL;
            } else {
                echo "Konvertierung fehlgeschlagen." . PHP_EOL;
                $video->setErrorMessage("Konvertierung fehlgeschlagen.");
                $this->processingService->failStep($video, VideoStatus::CONVERTING, "Konvertierung fehlgeschlagen.");
                $this->entityManager->persist($video);
                $this->entityManager->flush();
                return;
            }
        }
        
        $this->bus->dispatch(new DetectVideoScenesMessage($video->getId(), $localPath));
    }
}
