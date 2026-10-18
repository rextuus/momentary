<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Service\Jellyfin\JellyfinUploadService;
use App\Service\Transcription\JellyfinSubtitleExporter;
use App\Service\Transcription\SrtWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class JellyfinSubtitleExporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jellyfin-subtitles-' . bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testWritesSubtitleNextToJellyfinVideoAndRefreshesItem(): void
    {
        touch($this->dir . '/Urlaub.mp4');
        // Rest eines früheren Laufs in anderer Sprache
        touch($this->dir . '/Urlaub.en.srt');
        $video = new Video()
            ->setTitle('Urlaub')
            ->setJellyfinPath($this->dir . '/Urlaub.mp4')
            ->setJellyfinItemId('abc123');
        $transcript = new VideoTranscript($video);
        $transcript->start('large-v3-turbo', 10.0);
        $transcript->complete('de', [new TranscriptSegment(1.0, 2.5, 'Hallo!')]);

        $jellyfin = $this->createMock(JellyfinUploadService::class);
        $jellyfin->expects(self::once())->method('refreshItem')->with('abc123');

        $path = new JellyfinSubtitleExporter(new SrtWriter(), $jellyfin)->export($transcript);

        self::assertSame($this->dir . '/Urlaub.de.srt', $path);
        self::assertStringContainsString("00:00:01,000 --> 00:00:02,500\nHallo!", (string) file_get_contents($path));
        self::assertFileDoesNotExist($this->dir . '/Urlaub.en.srt');
    }

    public function testDoesNothingForVideosNotInJellyfin(): void
    {
        $video = new Video()->setTitle('Urlaub');
        $transcript = new VideoTranscript($video);
        $transcript->start('large-v3-turbo', 10.0);
        $transcript->complete('de', [new TranscriptSegment(1.0, 2.5, 'Hallo!')]);

        $jellyfin = $this->createMock(JellyfinUploadService::class);
        $jellyfin->expects(self::never())->method('refreshItem');

        self::assertNull(new JellyfinSubtitleExporter(new SrtWriter(), $jellyfin)->export($transcript));
    }
}
