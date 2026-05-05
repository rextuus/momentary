<?php

namespace App\MessageHandler;

use App\Message\DownloadVideoMessage;
use App\Message\DetectVideoScenesMessage; // Neue Message importieren
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class DownloadVideoMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private VideoRepository $videoRepository,
        private MessageBusInterface $bus
    ) {}

    public function __invoke(DownloadVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) return;

        $videoPath = $this->videoAnalyzer->downloadVideo($video->getId(), $video->getYoutubeUrl());

        if ($videoPath && file_exists($videoPath)) {
            // Wir schicken es jetzt erst zur Szenenerkennung
            $this->bus->dispatch(new DetectVideoScenesMessage(
                $video->getId(),
                $videoPath
            ));

            fwrite(STDOUT, "Download fertig. DetectVideoScenesMessage versendet." . PHP_EOL);
        } else {
            fwrite(STDERR, "Fehler: Download fehlgeschlagen." . PHP_EOL);
        }
    }
}