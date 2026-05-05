<?php

namespace App\MessageHandler;

use App\Message\SplitVideoIntoFramesMessage;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SplitVideoIntoFramesMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer
    ) {}

    public function __invoke(SplitVideoIntoFramesMessage $message): void
    {
        $this->videoAnalyzer->extractFrames($message->getVideoId(), $message->getLocalVideoPath());
    }
}
