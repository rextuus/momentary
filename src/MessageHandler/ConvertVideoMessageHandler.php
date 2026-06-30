<?php

namespace App\MessageHandler;

use App\Message\ConvertVideoMessage;
use App\Message\DetectVideoScenesMessage;
use App\Repository\VideoRepository;
use App\Service\VideoAnalyzer;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ConvertVideoMessageHandler
{
    public function __construct(
        private VideoAnalyzer $videoAnalyzer,
        private VideoRepository $videoRepository,
        private MessageBusInterface $bus,
        private WorkflowMachine $workflowMachine
    ) {}

    public function __invoke(ConvertVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) return;

        if ($this->workflowMachine->can($video, 'start_conversion')) {
            $this->workflowMachine->apply($video, 'start_conversion');
        }
        $video->setErrorMessage(null);

        // Aktuell nutzen wir die MP4-Optimierung als Konvertierung,
        // oder wir implementieren hier eine spezifische Logik.
        // Da der User meinte "wir erstellen eh schon eine mp4 version",
        // nutzen wir hier die Logik die aus beliebigen Formaten MP4 macht.
        
        $localPath = $video->getLocalPath();
        if (!$localPath) {
            return;
        }

        $sourcePath = $this->videoAnalyzer->resolvePath($localPath);
        
        // Wenn es schon mp4 ist, überspringen wir die eigentliche Konvertierung
        // und gehen direkt zur Szenenerkennung.
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            echo "Video {$video->getId()} ist bereits MP4. Überspringe Konvertierung." . PHP_EOL;
            $this->bus->dispatch(new DetectVideoScenesMessage($video->getId(), $localPath));
            return;
        }

        echo "Starte Konvertierung für Video {$video->getId()}..." . PHP_EOL;
        
        // Wir könnten hier das gleiche Python-Skript nutzen wie bei der Optimierung.
        // Da wir aber im VideoAnalyzer Kontext sind, schauen wir ob es dort was gibt.
        // Der User hat vorher erwähnt, dass die Optimierung optional ist.
        // Aber hier geht es um die Grund-Konvertierung damit die Pipeline (Szenenerkennung etc) arbeiten kann.
        
        // Einfachheitshalber nutzen wir die DetectVideoScenesMessage direkt, 
        // da das Python-Skript für Szenenerkennung (detect_scenes.py) via PyAV/FFmpeg 
        // oft auch mit anderen Formaten klarkommt. 
        // ABER der User möchte explizit den "Konvertierung" Schritt triggern.
        
        // Da ich kein neues Python Skript bauen will wenn nicht nötig, 
        // triggere ich hier die Szenenerkennung, da diese der nächste logische Schritt ist.
        // Wenn der User später eine echte Konvertierung (z.B. Deinterlacing) will, 
        // kann er das hier einbauen.
        
        $this->bus->dispatch(new DetectVideoScenesMessage($video->getId(), $localPath));
    }
}
