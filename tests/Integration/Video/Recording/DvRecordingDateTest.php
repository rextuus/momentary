<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Recording;

use App\Entity\Medium;
use App\Entity\RecordingSegment;
use App\Entity\Video;
use App\Service\Media\CameraClock;
use App\Service\Video\Recording\DvSubcodeReader;
use App\Service\Video\Recording\RecurringRecordingDates;
use App\Service\Video\VideoSourceMetadata;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Aufnahmedatum aus DV-Subcodes mit echtem ffmpeg und read_dv_subcodes.py (documentation/dv_aufnahmedatum.md).
 *
 * Alle Kassetten sind künstlich: ffmpeg schreibt creation_time als Aufnahmedatum in jeden Frame (ohne Angabe
 * 1970-01-01). Fehlende Subcodes (analog überspielt) entstehen, indem die Subcode- und VAUX-Blöcke wie leere Packs
 * auf Band mit 0xFF überschrieben werden.
 */
final class DvRecordingDateTest extends KernelTestCase
{
    private const int BLOCK = 80;
    private const int SEQUENCE = 150 * self::BLOCK;
    /** PAL: 12 DIF-Sequenzen je Frame */
    private const int PAL_FRAME = 12 * self::SEQUENCE;

    private string $dir;

    protected function setUp(): void
    {
        if (new ExecutableFinder()->find('ffmpeg') === null) {
            self::markTestSkipped('ffmpeg ist nicht installiert.');
        }
        $this->dir = sys_get_temp_dir() . '/dv-recording-' . uniqid();
        new Filesystem()->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testReaderUsesEitherSubcodeAreaAndFindsNothingWithoutSubcodes(): void
    {
        $dated = $this->cassette(['2002-12-29T15:15:05'], 'dv', 'dated.dv');
        // Erster Frame: Datum nur noch im VAUX-Bereich (Subcode-Blöcke leer), wie bei Bandfehlern
        $this->blank($dated, frames: [0], blocks: [1, 2]);
        $none = $this->cassette(['2002-12-29T15:15:05'], 'dv', 'analog.dv');
        $this->blank($none, frames: null, blocks: [1, 2, 3, 4, 5]);

        $subcodes = $this->reader()->read($dated);

        self::assertSame('PAL', $subcodes->system);
        self::assertSame(50, $subcodes->frames);
        self::assertCount(1, $subcodes->runs, 'erster Frame gehört dazu');
        self::assertSame('2002-12-29 15:15:05', $subcodes->runs[0]->cameraTime?->format('Y-m-d H:i:s'));
        self::assertFalse($this->reader()->read($none)->hasAnyDate());
    }

    public function testReaderSplitsACassetteAlsoInsideAnAvi(): void
    {
        $avi = $this->cassette(['2004-07-12T14:33:05', null, '2005-12-24T18:02:40'], 'avi');

        $subcodes = $this->reader()->read($avi);

        self::assertSame('PAL', $subcodes->system);
        self::assertSame(
            ['2004-07-12 14:33:05', '1970-01-01 00:00:00', '2005-12-24 18:02:40'],
            array_map(static fn($run) => $run->cameraTime?->format('Y-m-d H:i:s'), $subcodes->runs),
        );
        self::assertSame([0, 50, 100], array_map(static fn($run) => $run->startFrame, $subcodes->runs));
    }

    public function testReaderRejectsOtherCodecs(): void
    {
        $mp4 = $this->dir . '/clip.mp4';
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc=s=320x240:r=25:d=1', '-pix_fmt', 'yuv420p', $mp4]);

        $this->expectException(\RuntimeException::class);
        $this->reader()->read($mp4);
    }

    public function testDvDateBecomesTheRecordingTimeAndSegmentsAreStored(): void
    {
        // Dateiname mit Digitalisierungsdatum: das DV-Datum hat Vorrang
        $path = $this->cassette(['2004-07-12T14:33:05', null, '2005-12-24T18:02:40'], 'avi', '20240301_120000.avi');
        $video = VideoFactory::createOne();

        $this->sourceMetadata()->read($video, $path);

        self::assertSame(Medium::DATE_DV, $video->getDateSource());
        self::assertSame('2004-07-12 12:33:05', $video->getTakenAt()?->format('Y-m-d H:i:s'));
        $segments = $video->getRecordingSegments()->toArray();
        self::assertCount(3, $segments);
        self::assertNull($segments[1]->getRecordedAt());
        self::assertSame([2.0, 4.0], $video->getRecordingBoundaries());
    }

    public function testDvWithoutSubcodesFallsBackAndShowsAHint(): void
    {
        $path = $this->cassette(['2002-12-29T15:15:05'], 'dv', '20240301_120000.dv');
        $this->blank($path, frames: null, blocks: [1, 2, 3, 4, 5]);
        $video = VideoFactory::createOne();

        $this->sourceMetadata()->read($video, $path);

        self::assertSame(Medium::DATE_FILENAME, $video->getDateSource());
        self::assertCount(1, $video->getRecordingSegments());
        $segment = $video->getRecordingSegments()->first();
        self::assertInstanceOf(RecordingSegment::class, $segment);
        self::assertNull($segment->getRecordedAt());
        self::assertStringContainsString('kein Aufnahmedatum', implode(
            ' ',
            self::getContainer()->get(CameraClock::class)->warnings($video),
        ));
    }

    public function testManuallyCorrectedDateIsKept(): void
    {
        $path = $this->cassette(['2004-07-12T14:33:05'], 'dv');
        $video = VideoFactory::createOne();
        $video->setRecordedAt(new \DateTimeImmutable('2004-08-01 10:00:00'), Medium::DATE_MANUAL);

        $this->sourceMetadata()->read($video, $path);

        self::assertSame(Medium::DATE_MANUAL, $video->getDateSource());
        self::assertSame('2004-08-01', $video->getTakenAt()?->format('Y-m-d'));
        self::assertCount(1, $video->getRecordingSegments(), 'Segmente werden trotzdem gelesen');
    }

    public function testSameNightOnTwoCassettesIsOnlyAHint(): void
    {
        // Typisch für eine auf den 05.03.2001 zurückgesprungene Uhr, aber auch für eine lange Feier
        $first = $this->videoWithSegment('2001-03-05 00:20:00');
        $second = $this->videoWithSegment('2001-03-05 01:05:00');
        $daytime = $this->videoWithSegment('2001-03-06 14:00:00');

        $this->recurring()->mark($second);

        self::assertTrue($this->segmentOf($first)->isRecurringDate());
        self::assertTrue($this->segmentOf($second)->isRecurringDate());
        self::assertFalse($this->segmentOf($daytime)->isRecurringDate());
        self::assertTrue($this->segmentOf($first)->hasReliableDate(), 'das Datum gilt weiter');
    }

    public function testSameDayOnThreeCassettesIsOnlyAHint(): void
    {
        $videos = [
            $this->videoWithSegment('2003-06-14 10:00:00'),
            $this->videoWithSegment('2003-06-14 15:00:00'),
        ];
        $this->recurring()->mark($videos[1]);
        self::assertFalse($this->segmentOf($videos[0])->isRecurringDate(), 'zwei Kassetten tagsüber: kein Hinweis');

        $videos[] = $this->videoWithSegment('2003-06-14 18:00:00');
        $this->recurring()->mark($videos[2]);

        foreach ($videos as $video) {
            self::assertTrue($this->segmentOf($video)->isRecurringDate());
            self::assertFalse($this->segmentOf($video)->isSuspicious());
        }
    }

    public function testRecurringDateStillBecomesTheRecordingTime(): void
    {
        // Hochzeit über drei Kassetten: zwei schon im Archiv, die dritte wird gerade verarbeitet
        $this->videoWithSegment('2003-06-14 10:00:00');
        $this->videoWithSegment('2003-06-14 12:00:00');
        $path = $this->cassette(['2003-06-14T15:00:00'], 'dv');
        $video = VideoFactory::createOne();

        $this->sourceMetadata()->read($video, $path);

        self::assertTrue($this->segmentOf($video)->isRecurringDate());
        self::assertSame(Medium::DATE_DV, $video->getDateSource());
        self::assertSame('2003-06-14 13:00:00', $video->getTakenAt()?->format('Y-m-d H:i:s'));
        self::assertStringContainsString('Bitte prüfen', implode(
            ' ',
            self::getContainer()->get(CameraClock::class)->warnings($video),
        ));
    }

    public function testFactoryDateIsNotUsedAsRecordingTime(): void
    {
        $path = $this->cassette(['2000-01-01T00:04:00'], 'dv', '20240301_120000.dv');
        $video = VideoFactory::createOne();

        $this->sourceMetadata()->read($video, $path);

        self::assertTrue($this->segmentOf($video)->isSuspicious());
        self::assertSame(Medium::DATE_FILENAME, $video->getDateSource());
    }

    /**
     * Kassette aus mehreren Aufnahmen zu je 2 s (PAL); null = ohne Datum (ffmpeg schreibt dann 1970).
     *
     * @param list<?string> $recordings
     */
    private function cassette(array $recordings, string $container, ?string $name = null): string
    {
        $raw = $this->dir . '/cassette.dv';
        $handle = fopen($raw, 'wb');
        self::assertNotFalse($handle);
        foreach ($recordings as $i => $recordedAt) {
            $part = $this->dir . "/part{$i}.dv";
            $this->ffmpeg([
                '-f',
                'lavfi',
                '-i',
                'testsrc=s=720x576:r=25:d=2',
                '-f',
                'lavfi',
                '-i',
                'sine=d=2:r=48000',
                '-ac',
                '2',
                '-target',
                'pal-dv',
                ...($recordedAt === null ? [] : ['-metadata', 'creation_time=' . $recordedAt]),
                $part,
            ]);
            fwrite($handle, (string) file_get_contents($part));
        }
        fclose($handle);

        $target = $this->dir . '/' . ($name ?? 'cassette.' . $container);
        if ($container === 'dv') {
            // Nicht durch den dv-Muxer schicken: der schreibt eigene Subcodes in die Frames
            rename($raw, $target);
        } else {
            $this->ffmpeg(['-i', $raw, '-c', 'copy', $target]);
        }

        return $target;
    }

    /**
     * Subcode- (1, 2) und VAUX-Blöcke (3–5) leeren: Packs 0xFF, Block-ID bleibt.
     *
     * @param list<int>|null $frames null = alle
     * @param list<int>      $blocks Blocknummern innerhalb jeder DIF-Sequenz
     */
    private function blank(string $path, ?array $frames, array $blocks): void
    {
        $data = (string) file_get_contents($path);
        $frameCount = intdiv(\strlen($data), self::PAL_FRAME);
        foreach ($frames ?? range(0, $frameCount - 1) as $frame) {
            for ($sequence = 0; $sequence < 12; $sequence++) {
                foreach ($blocks as $block) {
                    $offset = ($frame * self::PAL_FRAME) + ($sequence * self::SEQUENCE) + ($block * self::BLOCK) + 3;
                    $data = substr_replace($data, str_repeat("\xFF", self::BLOCK - 3), $offset, self::BLOCK - 3);
                }
            }
        }
        file_put_contents($path, $data);
    }

    /**
     * @param list<string> $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $process = new Process(['ffmpeg', '-v', 'error', '-y', ...$arguments]);
        $process->mustRun();
    }

    private function videoWithSegment(string $cameraTimeBerlin): Video
    {
        $video = VideoFactory::createOne();
        $time = new \DateTimeImmutable($cameraTimeBerlin, new \DateTimeZone('Europe/Berlin'));
        $video->replaceRecordingSegments([new RecordingSegment(
            0.0,
            60.0,
            $time->setTimezone(new \DateTimeZone('UTC')),
        )]);
        $this->entityManager()->flush();

        return $video;
    }

    private function segmentOf(Video $video): RecordingSegment
    {
        $segment = $video->getRecordingSegments()->first();
        self::assertInstanceOf(RecordingSegment::class, $segment);
        $this->entityManager()->refresh($segment);

        return $segment;
    }

    private function reader(): DvSubcodeReader
    {
        return self::getContainer()->get(DvSubcodeReader::class);
    }

    private function sourceMetadata(): VideoSourceMetadata
    {
        return self::getContainer()->get(VideoSourceMetadata::class);
    }

    private function recurring(): RecurringRecordingDates
    {
        return self::getContainer()->get(RecurringRecordingDates::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
