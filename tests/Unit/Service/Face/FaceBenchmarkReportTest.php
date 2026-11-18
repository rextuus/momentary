<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Service\Face\Benchmark\FaceBenchmarkReport;
use PHPUnit\Framework\TestCase;

final class FaceBenchmarkReportTest extends TestCase
{
    public function testHistogramInStepsOfFiveHundredths(): void
    {
        $report = new FaceBenchmarkReport(null);
        foreach ([0.52, 0.51, 0.47, 0.12, 1.0, null] as $similarity) {
            $report->addFace($similarity, FaceBenchmarkReport::UNKNOWN);
        }

        self::assertSame(
            ['kein Kandidat' => 1, '0.10–0.15' => 1, '0.45–0.50' => 1, '0.50–0.55' => 2, '0.95–1.00' => 1],
            $report->histogram(),
        );
        self::assertSame(6, $report->faceCount());
    }

    public function testOutcomesAreCounted(): void
    {
        $report = new FaceBenchmarkReport(null);
        $report->addFace(0.61, FaceBenchmarkReport::ASSIGNED);
        $report->addFace(0.41, FaceBenchmarkReport::SUGGESTED);
        $report->addFace(0.2, FaceBenchmarkReport::UNKNOWN);
        $report->addFace(null, FaceBenchmarkReport::UNKNOWN);

        self::assertSame(['zugeordnet' => 1, 'vorgeschlagen' => 1, 'unbekannt' => 2], $report->outcomes());
    }

    public function testTwoStageComparison(): void
    {
        $report = new FaceBenchmarkReport(320);
        $report->noteModel('buffalo_l/w600k_r50');
        $report->noteModel('anderes');
        // Frame mit Gesicht, das der Vorfilter findet
        $report->addFrame(self::frame(faces: 2, detectMs: 200, embedMs: 400, prefilterMs: 50, prefilterFaces: 2));
        // Leerer Frame: zweistufig nur der Vorfilter
        $report->addFrame(self::frame(faces: 0, detectMs: 200, embedMs: 0, prefilterMs: 50, prefilterFaces: 0));
        // Kleines Gesicht, das der Vorfilter übersieht
        $report->addFrame(self::frame(faces: 1, detectMs: 200, embedMs: 200, prefilterMs: 50, prefilterFaces: 0));

        $totals = $report->totals();

        self::assertSame('buffalo_l/w600k_r50', $report->model(), 'das erste Modell zählt');
        self::assertSame(3, $totals['frames']);
        self::assertSame(2, $totals['framesWithFaces']);
        self::assertSame(1200.0, $totals['analyzeMs']);
        self::assertSame(750.0, $totals['twoStageMs'], '3 × 50 Vorfilter + 600 für den einen Frame mit Fund');
        self::assertSame(2, $totals['skippedFrames']);
        self::assertSame(1, $totals['missedFrames']);
        self::assertSame(1, $totals['missedFaces']);
    }

    public function testWithoutPrefilterThereIsNoComparison(): void
    {
        $report = new FaceBenchmarkReport(null);
        $report->addFrame(self::frame(faces: 1, detectMs: 200, embedMs: 200));

        self::assertNull($report->totals()['twoStageMs']);
        self::assertNull($report->totals()['missedFaces']);
    }

    /**
     * @return array{timestamp: int, wallMs: float, detectMs: float, embedMs: float, waitMs: float, matchMs: float, faces: int, tooSmall: int, overLimit: int, prefilterMs: ?float, prefilterFaces: ?int}
     */
    private static function frame(
        int $faces,
        float $detectMs,
        float $embedMs,
        ?float $prefilterMs = null,
        ?int $prefilterFaces = null,
    ): array {
        return [
            'timestamp' => 0,
            'wallMs' => $detectMs + $embedMs + 10,
            'detectMs' => $detectMs,
            'embedMs' => $embedMs,
            'waitMs' => 0.0,
            'matchMs' => 1.0,
            'faces' => $faces,
            'tooSmall' => 0,
            'overLimit' => 0,
            'prefilterMs' => $prefilterMs,
            'prefilterFaces' => $prefilterFaces,
        ];
    }
}
