<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Medium;
use App\Entity\Video;
use App\Service\Geo\PlaceTagger;
use App\Service\Media\CameraClock;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Recording\RecordingSegments;

/**
 * Liest beim Konvertieren (Pipeline-Schritt 1) die Eigenschaften der Quelldatei ans Video: Laufzeit, Kamera,
 * Aufnahmezeit, Ort, Halbbild-Reihenfolge und native Bildhöhe. Was schon gesetzt ist (von Hand korrigiert oder aus
 * dem Original), bleibt; nur die Laufzeit wird jedes Mal neu gemessen.
 *
 * Aufnahmezeit: DV-Subcodes (RecordingSegments) → Dateiname → creation_time. Das DV-Datum ersetzt eine Zeit aus
 * Dateiname oder Metadaten, nie eine von Hand korrigierte.
 */
class VideoSourceMetadata
{
    public function __construct(
        private readonly BetterVideoAnalyzer $videoAnalyzer,
        private readonly PlaceTagger $placeTagger,
        private readonly CameraClock $cameraClock,
        private readonly RecordingSegments $recordingSegments,
    ) {}

    /**
     * @return string Halbbild-Reihenfolge der aktuellen Datei (FieldOrder); kann vom Wert am Video abweichen
     */
    public function read(Video $video, string $path): string
    {
        // Laufzeit merken: Grundlage für Fortschritts- und Restzeitschätzung aller weiteren Schritte
        $duration = $this->videoAnalyzer->probeDuration($path);
        if ($duration !== null) {
            $video->setDuration($duration);
        }
        // Aufnahmedatum: damit Videos zusammen mit Fotos sortiert und Ereignissen zugeordnet werden können
        if ($video->getCamera() === null) {
            $video->setCamera($this->videoAnalyzer->probeCamera($path));
        }
        $this->recordingSegments->read($video, $path);
        $dvDate = RecordingSegments::firstReliableDate($video);
        // Ein DV-Datum, das sich als unzuverlässig herausstellt (z. B. wiederkehrendes Werksdatum), fällt zurück
        $staleDv = $video->getDateSource() === Medium::DATE_DV && $dvDate === null;
        if ($dvDate !== null && !$video->isDateCorrected()) {
            $video->setRecordedAt($dvDate, Medium::DATE_DV);
            $this->cameraClock->adjust($video);
        } elseif ($video->getTakenAt() === null || $staleDv) {
            $recordedAt = $this->videoAnalyzer->probeRecordedAt($path);
            $video->setRecordedAt($recordedAt?->date, $recordedAt?->source);
            // Gemerkte Abweichung der Kamera-Uhr (z. B. restliche Videos einer schon korrigierten Reise)
            $this->cameraClock->adjust($video);
        }
        if (!$video->hasLocation()) {
            $location = $this->videoAnalyzer->probeLocation($path);
            if ($location !== null) {
                $video->setLocation($location[0], $location[1]);
            }
        }
        if ($video->hasLocation() && $video->getPlaceName() === null) {
            $this->placeTagger->tag($video);
        }
        // Halbbilder der aktuellen Quelldatei entscheiden über die Konvertierung. Am Video bleibt der Wert des Originals
        // (erstes Mal): Ist das Original gelöscht, ist die Quelldatei schon die deinterlacte MP4.
        $fieldOrder = $this->videoAnalyzer->detectFieldOrder($path);
        if ($video->getFieldOrder() === null) {
            $video->setFieldOrder($fieldOrder);
        }
        if ($video->getSourceHeight() === null) {
            $video->setSourceHeight($this->videoAnalyzer->probeHeight($path));
        }

        return $fieldOrder;
    }
}
