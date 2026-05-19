<?php

namespace App\MessageHandler;

use App\Message\FrameAnalyzerMessage;
use App\Service\VideoAnalyzer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FrameAnalyzerMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
    ) {}

    public function __invoke(FrameAnalyzerMessage $message): void
    {
        // Wir übergeben jetzt auch das vierte Argument ($message->isLast()),
        // damit der Service weiß, wann er das Video auf "COMPLETED" setzen kann.
        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $message->getFramePath(),
            $message->getTimestamp(),
            $message->isLast(),
            $message->isRefinement()
        );
    }
}