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
            return;
        }

        $this->processingService->startStep($video, \App\Enum\VideoStatus::EXTRACTING_THUMBNAILS);

        foreach ($video->getScenes() as $scene) {
            $this->messageBus->dispatch(new ExtractSceneThumbnailMessage($scene->getId()));
        }
    }
}
