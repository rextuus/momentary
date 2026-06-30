<?php

namespace App\MessageHandler;

use App\Message\DetectVideoScenesMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Service\VideoAnalyzer;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class DetectVideoScenesMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private MessageBusInterface $bus,
        private WorkflowMachine $workflowMachine
    ) {}

    public function __invoke(DetectVideoScenesMessage $message): void
    {
        fwrite(STDOUT, "Asynchrone Szenenerkennung für Video {$message->getVideoId()}..." . PHP_EOL);

        $video = $this->videoAnalyzer->getVideoRepository()->find($message->getVideoId());
        if (!$video) {
            return;
        }

        // Status via WorkflowMachine setzen
        if ($this->workflowMachine->can($video, 'start_scene_detection')) {
            $this->workflowMachine->apply($video, 'start_scene_detection');
        }

        // Reset error message when starting detection
        $video->setErrorMessage(null);

        $videoPath = $this->videoAnalyzer->resolvePath($message->getVideoPath());

        // Hier lag der Hund begraben: Wir müssen videoPath UND videoId übergeben
        $scenes = $this->videoAnalyzer->detectScenes(
            $videoPath,
            $message->getVideoId()
        );

        // Szenen speichern
        $this->videoAnalyzer->storeScenes($message->getVideoId(), $scenes);

        // Wir laden das Video neu, falls sich der localPath während der Szenenerkennung geändert hat (Konvertierung)
        $video = $this->videoAnalyzer->getVideoRepository()->find($message->getVideoId());
        $currentVideoPath = $video?->getLocalPath() ?? $videoPath;

        fwrite(STDOUT, count($scenes) . " Szenen in DB verewigt." . PHP_EOL);

        // Weiter zum Splitting
        $this->bus->dispatch(new SplitVideoIntoFramesMessage(
            $message->getVideoId(),
            $currentVideoPath
        ));
    }
}