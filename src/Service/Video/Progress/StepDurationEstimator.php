<?php

declare(strict_types=1);

namespace App\Service\Video\Progress;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Schätzt, wie lange ein Verarbeitungsschritt für ein Video dauert.
 *
 * Grundlage ist die Dauer bisheriger Schritte pro Sekunde Videolänge. Solange es dafür noch keine Daten gibt,
 * gelten die Startwerte unten.
 */
class StepDurationEstimator
{
    /** Sekunden Verarbeitung pro Sekunde Video, falls es noch keine Erfahrungswerte gibt */
    private const array DEFAULT_RATES = [
        'converting' => 0.02,
        'scene_detection' => 0.35,
        'extracting_thumbnails' => 0.05,
        'video_splitting' => 0.25,
        'analyzing_faces_initial' => 0.6,
        'refining_extraction' => 0.01,
        'refining_splitting' => 0.25,
        'analyzing_faces_refinement' => 0.8,
        'consolidating_faces' => 0.01,
        'merging_scenes' => 0.01,
        'tagging_scenes' => 0.3,
        'chapter_generation' => 0.05,
        'naming' => 0.03,
        'exporting_jellyfin' => 0.15,
        // Whisper large-v3-turbo auf schwacher CPU etwa Echtzeit
        'transcribing' => 1.0,
    ];

    /** Kein Schritt dauert kürzer als das (Worker-Overhead, Netzwerk) */
    private const float MIN_SECONDS = 2.0;

    /** Annahme, solange die Videolänge noch unbekannt ist (vor der Konvertierung) */
    private const float FALLBACK_VIDEO_SECONDS = 60.0;

    /** @var array<string, float>|null */
    private ?array $learnedRates = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function expectedSeconds(Video $video, VideoStatus $step): float
    {
        $videoSeconds = $video->getDuration() ?? self::FALLBACK_VIDEO_SECONDS;
        $rate = $this->learnedRates()[$step->value] ?? self::DEFAULT_RATES[$step->value] ?? 0.05;

        return max(self::MIN_SECONDS, $rate * $videoSeconds);
    }

    /**
     * Durchschnittliche Sekunden pro Videosekunde je Schritt aus erfolgreich abgeschlossenen Schritten.
     *
     * @return array<string, float>
     */
    private function learnedRates(): array
    {
        if ($this->learnedRates !== null) {
            return $this->learnedRates;
        }

        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('s.step AS step', 'AVG(s.duration / v.duration) AS rate', 'COUNT(s.id) AS samples')
            ->from(VideoProcessingStep::class, 's')
            ->join('s.video', 'v')
            ->where('s.duration IS NOT NULL')
            ->andWhere('s.finishedAt IS NOT NULL')
            ->andWhere('s.errorMessage IS NULL')
            ->andWhere('v.duration > 0')
            ->groupBy('s.step')
            ->getQuery()
            ->getArrayResult();

        $this->learnedRates = [];
        foreach ($rows as $row) {
            $step = $row['step'] instanceof VideoStatus ? $row['step']->value : (string) $row['step'];
            $this->learnedRates[$step] = (float) $row['rate'];
        }

        return $this->learnedRates;
    }
}
