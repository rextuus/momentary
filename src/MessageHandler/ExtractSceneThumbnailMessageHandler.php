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
        $scene = $this->videoSceneRepository->find($message->getSceneId());
        if (!$scene) {
            return;
        }

        $video = $scene->getVideo();
        if (!$video) {
            return;
        }

        // Extract thumbnail from the middle of the scene
        $time = ($scene->getStartSeconds() + $scene->getEndSeconds()) / 2;
        $thumbnailPath = $this->videoAnalyzer->extractThumbnail($video, $time, sprintf('scene_%d.jpg', $scene->getId()));
        
        if ($thumbnailPath) {
            $scene->setThumbnailUrl($thumbnailPath);
            $this->entityManager->flush();
        }

        // Überprüfen, ob alle Szenen Thumbnails haben
        $allDone = true;
        foreach ($video->getScenes() as $s) {
            if (!$s->getThumbnailUrl()) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            // Übergang zu SPLITTING
            $this->videoAnalyzer->updateStatus($video->getId(), \App\Enum\VideoStatus::SPLITTING);
            $this->processingService->finishStep($video, \App\Enum\VideoStatus::EXTRACTING_THUMBNAILS);
            $this->processingService->startStep($video, \App\Enum\VideoStatus::SPLITTING);
            
            // Dispatch Splitting Message
            $localVideoPath = $video->getLocalPath() ?? $video->getConvertedVideoPath();
            if ($localVideoPath) {
                $this->bus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), $localVideoPath));
            }
        }
    }
}
