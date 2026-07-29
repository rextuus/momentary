<?php

namespace App\MessageHandler;

use App\Message\ExtractAllSceneThumbnailsMessage;
use App\Message\ExtractSceneThumbnailMessage;
use App\Repository\VideoRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ExtractAllSceneThumbnailsMessageHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private MessageBusInterface $messageBus,
        private \App\Service\VideoProcessingService $processingService
    ) {}

    public function __invoke(ExtractAllSceneThumbnailsMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            fwrite(STDOUT, "[ExtractAllSceneThumbnails] Video {$message->getVideoId()} nicht gefunden." . PHP_EOL);
            return;
        }

        $sceneCount = count($video->getScenes());
        fwrite(STDOUT, "[ExtractAllSceneThumbnails] Starte Thumbnail-Extraktion für Video {$message->getVideoId()} ({$sceneCount} Szenen)." . PHP_EOL);

        $this->processingService->startStep($video, \App\Enum\VideoStatus::EXTRACTING_THUMBNAILS);

        $sceneIds = array_values(array_map(fn($s) => $s->getId(), $video->getScenes()->toArray()));

        if (empty($sceneIds)) {
            fwrite(STDOUT, "[ExtractAllSceneThumbnails] Keine Szenen für Video {$message->getVideoId()} – überspringe Thumbnail-Extraktion." . PHP_EOL);
            return;
        }

        $firstId = array_shift($sceneIds);
        $this->messageBus->dispatch(new ExtractSceneThumbnailMessage($firstId, $sceneIds));

        fwrite(STDOUT, "[ExtractAllSceneThumbnails] Starte Chain mit Szene $firstId, " . count($sceneIds) . " weitere für Video {$message->getVideoId()}." . PHP_EOL);
    }
}
