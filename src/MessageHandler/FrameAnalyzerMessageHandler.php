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
        $framePath = $this->videoAnalyzer->resolvePath($message->getFramePath());

        // Wir übergeben jetzt auch das vierte Argument ($message->isLast()),
        // damit der Service weiß, wann er das Video auf "COMPLETED" setzen kann.
        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $framePath,
            $message->getTimestamp(),
            $message->isLast(),
            $message->isRefinement()
        );
    }
}