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
            $message->isLast()
        );

        // Cleanup nach dem letzten Frame
        if ($message->isLast()) {
            $this->cleanup($message->getFramePath());
        }
    }

    private function cleanup(string $path): void
    {
        $frameDirectory = dirname($path);
        $filesystem = new Filesystem();

        if ($filesystem->exists($frameDirectory)) {
            // Kleiner Safety-Check: Nur löschen, wenn es wirklich im temp-Ordner liegt
            // oder sichergestellt ist, dass wir nicht versehentlich zu viel löschen.
            $filesystem->remove($frameDirectory);
            fwrite(STDOUT, "Cleanup: Temporäre Frames gelöscht." . PHP_EOL);
        }
    }
}