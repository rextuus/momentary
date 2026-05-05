<?php

namespace App\MessageHandler;

use App\Message\DetectVideoScenesMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Service\VideoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class DetectVideoScenesMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private MessageBusInterface $bus
    ) {}

    public function __invoke(DetectVideoScenesMessage $message): void
    {
        fwrite(STDOUT, "Asynchrone Szenenerkennung für Video {$message->getVideoId()}..." . PHP_EOL);

        // Hier lag der Hund begraben: Wir müssen videoPath UND videoId übergeben
        $scenes = $this->videoAnalyzer->detectScenes(
            $message->getVideoPath(),
            $message->getVideoId()
        );

        // Szenen speichern
        $this->videoAnalyzer->storeScenes($message->getVideoId(), $scenes);

        fwrite(STDOUT, count($scenes) . " Szenen in DB verewigt." . PHP_EOL);

        // Weiter zum Splitting
        $this->bus->dispatch(new SplitVideoIntoFramesMessage(
            $message->getVideoId(),
            $message->getVideoPath()
        ));
    }
}