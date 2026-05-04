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
        private VideoAnalyzer $videoAnalyzer, // Wir nutzen den Service!
    ) {}

    public function __invoke(FrameAnalyzerMessage $message): void
    {
        // Wir delegieren die komplexe Logik (Transaktionen, Personen-Erstellung)
        // an den Service, den wir gerade stabil gebaut haben.
        $this->videoAnalyzer->analyzeFrame(
            $message->getVideoId(),
            $message->getFramePath(),
            $message->getTimestamp()
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
            $filesystem->remove($frameDirectory);
        }
    }
}