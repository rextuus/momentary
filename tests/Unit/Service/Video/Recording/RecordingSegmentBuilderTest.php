<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Recording;

use App\Entity\RecordingSegment;
use App\Service\Video\Recording\DvSubcodeReader;
use App\Service\Video\Recording\RecordingSegmentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Aus den Läufen von read_dv_subcodes.py werden Aufnahme-Segmente (documentation/dv_aufnahmedatum.md).
 */
final class RecordingSegmentBuilderTest extends TestCase
{
    public function testCassetteWithTwoRecordingsAndAnUndatedGapInBetween(): void
    {
        // PAL, 25 fps: 2 s Juli 2004, 1 s ohne gültiges Datum (ffmpeg schreibt 1970), 2 s Weihnachten 2005
        $segments = self::build([
            [0,  49,  '2004-07-12 14:33:05'],
            [50, 74,  '1970-01-01 00:00:00'],
            [75, 124, '2005-12-24 18:02:40'],
        ]);

        self::assertSame([[0.0, 2.0], [2.0, 3.0], [3.0, 5.0]], self::ranges($segments));
        // Kamerazeit ist Ortszeit (Sommerzeit: UTC+2, Winterzeit: UTC+1)
        $recordedAt = $segments[0]->getRecordedAt();
        self::assertNotNull($recordedAt);
        self::assertSame('2004-07-12 12:33:05', $recordedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $recordedAt->getTimezone()->getName());
        self::assertNull($segments[1]->getRecordedAt(), 'vor 1995: Uhr nicht gestellt');
        self::assertSame('2005-12-24 17:02:40', $segments[2]->getCameraRecordedAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($segments[0]->isSuspicious());
    }

    public function testFileWithoutSubcodesIsOneUndatedSegment(): void
    {
        $segments = self::build([[0, 249, null]]);

        self::assertSame([[0.0, 10.0]], self::ranges($segments));
        self::assertNull($segments[0]->getRecordedAt());
        self::assertSame(RecordingSegment::SOURCE_DV, $segments[0]->getSource());
    }

    public function testDatesInTheFutureOrBefore1995CountAsMissing(): void
    {
        $segments = self::build([
            [0,  49, '1994-12-31 23:00:00'],
            [50, 99, '2031-01-01 12:00:00'],
        ], '2026-11-19');

        self::assertSame([[0.0, 4.0]], self::ranges($segments), 'zwei Bereiche ohne Datum werden eins');
        self::assertNull($segments[0]->getRecordedAt());
    }

    public function testShortDropoutInsideARecordingDoesNotSplitIt(): void
    {
        // 0,2 s ohne Subcode (Bandfehler), danach läuft die Uhr nahtlos weiter
        $segments = self::build([
            [0,   99,  '2004-07-12 14:33:05'],
            [100, 104, null],
            [105, 199, '2004-07-12 14:33:09'],
        ]);

        self::assertSame([[0.0, 8.0]], self::ranges($segments));
        self::assertSame('2004-07-12 12:33:05', $segments[0]->getRecordedAt()?->format('Y-m-d H:i:s'));
    }

    public function testShortNoiseWithADateInsideAnUndatedPartIsIgnored(): void
    {
        $segments = self::build([
            [0,   99,  null],
            [100, 102, '2003-05-01 10:00:00'],
            [103, 199, null],
        ]);

        self::assertSame([[0.0, 8.0]], self::ranges($segments));
        self::assertNull($segments[0]->getRecordedAt());
    }

    public function testRecordingStartSplitsEvenIfTheClockContinues(): void
    {
        $segments = self::build([[0, 49, '2004-07-12 14:33:05'], [50, 99, '2004-07-12 14:33:07', true]]);

        self::assertCount(2, $segments);
    }

    public function testFactoryDateAfterEmptyBackupBatteryIsSuspicious(): void
    {
        $segments = self::build([
            [0,  49, '2000-01-01 00:12:40'],
            [50, 99, '2000-01-01 14:00:00'],
        ]);

        self::assertTrue($segments[0]->isSuspicious(), '1. Januar, erste Stunde');
        self::assertFalse($segments[1]->isSuspicious());
        self::assertFalse($segments[0]->hasReliableDate());
        self::assertNotNull($segments[0]->getRecordedAt(), 'Datum bleibt gespeichert');
    }

    public function testShortFirstRunGoesIntoTheNextSegment(): void
    {
        $segments = self::build([
            [0, 4,  null],
            [5, 99, '2004-07-12 14:33:05'],
        ]);

        self::assertSame([[0.0, 4.0]], self::ranges($segments));
        self::assertNotNull($segments[0]->getRecordedAt());
    }

    public function testNtscTimesUseTheExactFrameRate(): void
    {
        $segments = RecordingSegmentBuilder::build(DvSubcodeReader::parse((string) json_encode([
            'system' => 'NTSC',
            'fps' => 30000 / 1001,
            'frames' => 3,
            'runs' => [['start' => 0, 'end' => 2, 'recorded_at' => '2002-12-29 15:15:05', 'rec_start' => false]],
        ])));

        self::assertSame([[0.0, 0.1]], self::ranges($segments), 'auch eine sehr kurze Datei ergibt ein Segment');
        self::assertSame('2002-12-29 14:15:05', $segments[0]->getRecordedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<array{0: int, 1: int, 2: ?string, 3?: bool}> $runs [Startframe, Endframe, Kamerazeit, Aufnahmebeginn]
     *
     * @return list<RecordingSegment>
     */
    private static function build(array $runs, string $now = '2026-11-19'): array
    {
        $json = json_encode([
            'system' => 'PAL',
            'fps' => 25.0,
            'frames' => $runs === [] ? 0 : $runs[array_key_last($runs)][1] + 1,
            'runs' => array_map(static fn(array $run): array => [
                'start' => $run[0],
                'end' => $run[1],
                'recorded_at' => $run[2],
                'rec_start' => $run[3] ?? false,
            ], $runs),
        ]);

        return RecordingSegmentBuilder::build(DvSubcodeReader::parse((string) $json), new \DateTimeImmutable($now));
    }

    /**
     * @param list<RecordingSegment> $segments
     *
     * @return list<array{float, float}>
     */
    private static function ranges(array $segments): array
    {
        return array_map(static fn(RecordingSegment $s): array => [
            round($s->getStartSeconds(), 1),
            round($s->getEndSeconds(), 1),
        ], $segments);
    }
}
