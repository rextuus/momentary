<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transcription;

use App\Entity\TranscriptSegment;
use App\Service\Transcription\SrtWriter;
use PHPUnit\Framework\TestCase;

final class SrtWriterTest extends TestCase
{
    public function testWritesNumberedCuesWithSrtTimestamps(): void
    {
        $srt = new SrtWriter()->write([
            new TranscriptSegment(0.1, 2.0, 'Kamera ist da!'),
            new TranscriptSegment(3661.5, 3663.25, 'Schmeckt schon.'),
        ]);

        self::assertSame(
            "1\n00:00:00,100 --> 00:00:02,000\nKamera ist da!\n\n"
            . "2\n01:01:01,500 --> 01:01:03,250\nSchmeckt schon.\n\n",
            $srt,
        );
    }

    public function testSplitsLongSegmentsAtSentencesAndWrapsLongLines(): void
    {
        $srt = new SrtWriter()->write([
            new TranscriptSegment(
                10.0,
                30.0,
                'Joscha, Butter ist ja nur Fett. Das ist wenigstens ein bisschen lecker. Guck dir das an!',
            ),
        ]);

        $cues = array_values(array_filter(explode("\n\n", trim($srt))));
        self::assertCount(3, $cues, '20 s Text werden auf mehrere Untertitel verteilt');
        self::assertStringStartsWith("1\n00:00:10,000 --> ", $cues[0]);
        self::assertStringEndsWith('Guck dir das an!', $cues[2]);
        self::assertStringContainsString('--> 00:00:30,000', $cues[2]);
        foreach (explode("\n", $srt) as $line) {
            self::assertLessThanOrEqual(42, mb_strlen($line));
        }
    }
}
