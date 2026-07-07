<?php

namespace App\MessageHandler;

use App\Message\ExtractThumbnailMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExtractThumbnailMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private VideoRepository $videoRepository
    ) {}

    public function __invoke(ExtractThumbnailMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }

        $this->videoAnalyzer->extractThumbnail($video, $message->getTimeInSeconds());
    }
}
