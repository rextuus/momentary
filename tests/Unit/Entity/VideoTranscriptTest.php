<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Enum\TranscriptionStatus;
use PHPUnit\Framework\TestCase;

final class VideoTranscriptTest extends TestCase
{
    public function testProgressFollowsProcessedAudio(): void
    {
        $transcript = new VideoTranscript(new Video());
        $transcript->start('large-v3-turbo', 200.0);
        $transcript->reportProgress(50.0);

        self::assertSame(TranscriptionStatus::RUNNING, $transcript->getStatus());
        self::assertSame(25, $transcript->getPercent());
    }

    public function testCompletingReplacesOldSegments(): void
    {
        $video = new Video();
        $transcript = new VideoTranscript($video);
        $transcript->start('small', 60.0);
        $transcript->complete('de', [new TranscriptSegment(0.0, 2.0, 'alt')]);
        $transcript->queue();
        $transcript->start('large-v3-turbo', 60.0);
        $transcript->complete('de', [
            new TranscriptSegment(1.0, 3.0, 'neu'),
            new TranscriptSegment(10.0, 12.0, 'später'),
        ]);

        self::assertSame($transcript, $video->getTranscript());
        self::assertSame(100, $transcript->getPercent());
        self::assertSame('neu später', $transcript->getFullText());
        self::assertSame(
            ['neu'],
            array_map(static fn(TranscriptSegment $s): string => $s->getText(), $transcript->segmentsBetween(0.0, 5.0)),
        );
    }

    public function testFailureKeepsMessage(): void
    {
        $transcript = new VideoTranscript(new Video());
        $transcript->fail('Whisper ist fehlgeschlagen');

        self::assertSame(TranscriptionStatus::FAILED, $transcript->getStatus());
        self::assertFalse($transcript->getStatus()->isPending());
        self::assertSame('Whisper ist fehlgeschlagen', $transcript->getError());
    }
}
