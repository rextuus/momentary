<?php

namespace App\MessageHandler;

use App\Message\FrameAnalyzerMessage;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Filesystem\Filesystem;

#[AsMessageHandler]
final class FrameAnalyzerMessageHandler
{


    public function __construct(private VideoAnalyzer $videoAnalyzer)
    {
    }

    public function __invoke(FrameAnalyzerMessage $message): void
    {
        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $message->getFramePath(),
            $message->getTimestamp(),
        );

        if ($message->isLast()) {
            $frameDirectory = dirname($message->getFramePath(), 2); // Assuming the path is "frames/..."
            $filesystem = new Filesystem();

            if ($filesystem->exists($frameDirectory)) {
                $filesystem->remove($frameDirectory); // Deletes the directory and all its contents
                // Log the removal or handle exceptions if necessary
            }
        }
    }
}
