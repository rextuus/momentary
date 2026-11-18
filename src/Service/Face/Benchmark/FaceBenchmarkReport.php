<?php

declare(strict_types=1);

namespace App\Service\Face\Benchmark;

/**
 * Ergebnis von app:face:analyze: Laufzeiten je Frame, Verteilung der besten Ähnlichkeiten und was bei den aktuellen
 * Schwellen mit den Gesichtern geschähe. Grundlage für die Kalibrierung der Schwellen an echten Videos.
 */
final class FaceBenchmarkReport
{
    public const string ASSIGNED = 'zugeordnet';
    public const string SUGGESTED = 'vorgeschlagen';
    public const string UNKNOWN = 'unbekannt';

    /** Breite der Histogramm-Klassen (Kosinus). */
    public const float BIN_WIDTH = 0.05;

    /**
     * @var list<array{timestamp: int, wallMs: float, detectMs: float, embedMs: float, waitMs: float, matchMs: float, faces: int, tooSmall: int, overLimit: int, prefilterMs: ?float, prefilterFaces: ?int}>
     */
    private array $frames = [];

    /** @var list<float|null> beste Ähnlichkeit (Kosinus) je Gesicht zu einer vorhandenen Person; null = keine */
    private array $bestSimilarities = [];

    /** @var array<string, int> */
    private array $outcomes = [self::ASSIGNED => 0, self::SUGGESTED => 0, self::UNKNOWN => 0];

    /** Erkennungsmodell laut face-service; steht nach der ersten Antwort fest. */
    private ?string $model = null;

    public function __construct(
        public readonly ?int $prefilterDetSize,
    ) {}

    public function noteModel(string $model): void
    {
        $this->model ??= $model;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * @param array{timestamp: int, wallMs: float, detectMs: float, embedMs: float, waitMs: float, matchMs: float, faces: int, tooSmall: int, overLimit: int, prefilterMs: ?float, prefilterFaces: ?int} $frame
     */
    public function addFrame(array $frame): void
    {
        $this->frames[] = $frame;
    }

    public function addFace(?float $bestSimilarity, string $outcome): void
    {
        $this->bestSimilarities[] = $bestSimilarity;
        $this->outcomes[$outcome] = ($this->outcomes[$outcome] ?? 0) + 1;
    }

    /**
     * @return list<array{timestamp: int, wallMs: float, detectMs: float, embedMs: float, waitMs: float, matchMs: float, faces: int, tooSmall: int, overLimit: int, prefilterMs: ?float, prefilterFaces: ?int}>
     */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * @return array<string, int> zugeordnet / vorgeschlagen / unbekannt
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function faceCount(): int
    {
        return \count($this->bestSimilarities);
    }

    /**
     * Anzahl Gesichter je Klasse der besten Ähnlichkeit, aufsteigend; Schlüssel "0.45–0.50". Gesichter ohne jeden
     * Kandidaten (keine ähnliche Person über der Kandidatenschwelle) stehen unter "kein Kandidat".
     *
     * @return array<string, int>
     */
    public function histogram(): array
    {
        $bins = [];
        $none = 0;
        foreach ($this->bestSimilarities as $similarity) {
            if ($similarity === null) {
                $none++;
                continue;
            }
            $index = min((int) floor(max(0.0, $similarity) / self::BIN_WIDTH), (int) round(1 / self::BIN_WIDTH) - 1);
            $bins[$index] = ($bins[$index] ?? 0) + 1;
        }
        ksort($bins);

        $histogram = $none > 0 ? ['kein Kandidat' => $none] : [];
        foreach ($bins as $index => $count) {
            $histogram[sprintf('%.2f–%.2f', $index * self::BIN_WIDTH, ($index + 1) * self::BIN_WIDTH)] = $count;
        }

        return $histogram;
    }

    /**
     * Summen für die Zusammenfassung; mit Vorfilter auch der Vergleich ein-/zweistufig.
     *
     * @return array{frames: int, wallMs: float, analyzeMs: float, framesWithFaces: int, twoStageMs: ?float, skippedFrames: ?int, missedFrames: ?int, missedFaces: ?int}
     */
    public function totals(): array
    {
        $analyzeMs = 0.0;
        $wallMs = 0.0;
        $withFaces = 0;
        $twoStageMs = $this->prefilterDetSize !== null ? 0.0 : null;
        $skipped = $this->prefilterDetSize !== null ? 0 : null;
        $missedFrames = $skipped;
        $missedFaces = $skipped;
        foreach ($this->frames as $frame) {
            $frameAnalyzeMs = $frame['detectMs'] + $frame['embedMs'];
            $analyzeMs += $frameAnalyzeMs;
            $wallMs += $frame['wallMs'];
            $withFaces += $frame['faces'] > 0 ? 1 : 0;
            if ($twoStageMs === null) {
                continue;
            }
            // Zweistufig: Vorfilter immer, die eigentliche Analyse nur, wenn der Vorfilter ein Gesicht findet
            $twoStageMs += (float) $frame['prefilterMs'];
            if ((int) $frame['prefilterFaces'] > 0) {
                $twoStageMs += $frameAnalyzeMs;
            } else {
                $skipped++;
                if ($frame['faces'] > 0) {
                    $missedFrames++;
                    $missedFaces += $frame['faces'];
                }
            }
        }

        return [
            'frames' => \count($this->frames),
            'wallMs' => $wallMs,
            'analyzeMs' => $analyzeMs,
            'framesWithFaces' => $withFaces,
            'twoStageMs' => $twoStageMs,
            'skippedFrames' => $skipped,
            'missedFrames' => $missedFrames,
            'missedFaces' => $missedFaces,
        ];
    }
}
