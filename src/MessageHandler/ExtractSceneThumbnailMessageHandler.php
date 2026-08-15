<?php

namespace App\MessageHandler;

use App\Message\ExtractSceneThumbnailMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Repository\VideoSceneRepository;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ExtractSceneThumbnailMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private VideoSceneRepository $videoSceneRepository,
        private EntityManagerInterface $entityManager,
        private VideoProcessingService $processingService,
        private MessageBusInterface $bus
    ) {}

    public function __invoke(ExtractSceneThumbnailMessage $message): void
    {
        fwrite(STDOUT, "[ExtractSceneThumbnail] Starte für Szene {$message->getSceneId()}." . PHP_EOL);
        $scene = $this->videoSceneRepository->find($message->getSceneId());
        if (!$scene) {
            fwrite(STDOUT, "[ExtractSceneThumbnail] Szene {$message->getSceneId()} nicht gefunden." . PHP_EOL);
            return;
        }

        $video = $scene->getVideo();
        if (!$video) {
            return;
        }
        $this->entityManager->refresh($video);

        // Extract thumbnail from the middle of the scene
        $time = ($scene->getStartSeconds() + $scene->getEndSeconds()) / 2;
        $thumbnailPath = $this->videoAnalyzer->extractThumbnail($video, $time, sprintf('scene_%d.jpg', $scene->getId()));
        
        if ($thumbnailPath) {
            fwrite(STDOUT, "[ExtractSceneThumbnail] Thumbnail gespeichert: {$thumbnailPath}" . PHP_EOL);
            $scene->setThumbnailUrl($thumbnailPath);
            if (!$video->getThumbnailPath()) {
                $video->setThumbnailPath($thumbnailPath);
            }
            $this->entityManager->flush();
        } else {
            fwrite(STDOUT, "[ExtractSceneThumbnail] Kein Thumbnail für Szene {$message->getSceneId()} erzeugt." . PHP_EOL);
        }

        // Chain: nächste Szene dispatchen oder, wenn alle fertig, SplitVideoIntoFramesMessage
        $remaining = $message->getRemainingSceneIds();
        if (!empty($remaining)) {
            $nextId = array_shift($remaining);
            fwrite(STDOUT, "[ExtractSceneThumbnail] Nächste Szene $nextId, " . count($remaining) . " weitere verbleiben." . PHP_EOL);
            $this->bus->dispatch(new ExtractSceneThumbnailMessage($nextId, $remaining));
        } else {
            fwrite(STDOUT, "[ExtractSceneThumbnail] Alle Thumbnails fertig für Video {$video->getId()} – dispatche SplitVideoIntoFramesMessage." . PHP_EOL);
            $this->videoAnalyzer->updateStatus($video->getId(), \App\Enum\VideoStatus::VIDEO_SPLITTING);
            $this->processingService->finishStep($video, \App\Enum\VideoStatus::EXTRACTING_THUMBNAILS);
            $this->processingService->startStep($video, \App\Enum\VideoStatus::VIDEO_SPLITTING);

            $localVideoPath = $video->getLocalPath() ?? $video->getConvertedVideoPath();
            if ($localVideoPath) {
                $this->bus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), $localVideoPath));
            } else {
                fwrite(STDOUT, "[ExtractSceneThumbnail] Kein localVideoPath für Video {$video->getId()} – SplitVideoIntoFramesMessage NICHT dispatched!" . PHP_EOL);
            }
        }
    }
}
