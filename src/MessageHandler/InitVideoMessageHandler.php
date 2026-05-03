<?php

namespace App\MessageHandler;

use App\Message\InitVideoMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class InitVideoMessageHandler
{
    public function __construct(private VideoAnalyzer $videoAnalyzer, private VideoRepository $videoRepository)
    {
    }

    public function __invoke(InitVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());

        $this->videoAnalyzer->downloadVideoAndSplitInFrames($video->getId(), $video->getYoutubeUrl());
    }
}
