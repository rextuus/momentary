<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Transcription;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Service\VideoIndexer;
use PHPUnit\Framework\TestCase;

final class VideoIndexerTranscriptTest extends TestCase
{
    public function testFinishedTranscriptIsSearchableWithTimestamps(): void
    {
        $video = new Video()->setTitle('Geburtstag');
        $transcript = new VideoTranscript($video);
        $transcript->start('large-v3-turbo', 60.0);
        $transcript->complete('de', [new TranscriptSegment(12.4, 15.2, 'Wo ist die Hochzeitstorte?')]);

        $document = new VideoIndexer()->transform($video);

        self::assertSame(
            [['start' => 12, 'end' => 16, 'text' => 'Wo ist die Hochzeitstorte?']],
            $document['transcript'],
        );
    }

    public function testRunningTranscriptIsNotIndexedYet(): void
    {
        $video = new Video()->setTitle('Geburtstag');
        new VideoTranscript($video)->start('large-v3-turbo', 60.0);

        self::assertSame([], new VideoIndexer()->transform($video)['transcript']);
    }
}
