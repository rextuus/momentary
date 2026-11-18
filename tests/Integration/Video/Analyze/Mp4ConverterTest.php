<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Analyze;

use App\Service\Video\Analyze\Mp4Converter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Prüft mit echtem ffmpeg anhand kleiner, generierter Testvideos.
 */
final class Mp4ConverterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (new ExecutableFinder()->find('ffmpeg') === null) {
            self::markTestSkipped('ffmpeg ist nicht installiert.');
        }
        $this->dir = sys_get_temp_dir() . '/mp4-converter-' . uniqid();
        new Filesystem()->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testCompatibleVideoIsRemuxedWithoutReencoding(): void
    {
        $source = $this->generate('source.mov', ['-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac']);
        $target = $this->dir . '/target.mp4';

        self::assertTrue(new Mp4Converter(new NullLogger())->convertToMp4($source, $target));

        // Beim Umpacken bleibt der Videostrom bitgenau erhalten
        self::assertSame($this->videoStreamHash($source), $this->videoStreamHash($target));
        self::assertSame(['h264', 'aac'], $this->codecs($target));
    }

    public function testIncompatibleVideoIsReencodedToH264(): void
    {
        $source = $this->generate('source.mov', ['-c:v', 'mjpeg', '-c:a', 'pcm_s16le']);
        $target = $this->dir . '/target.mp4';

        self::assertTrue(new Mp4Converter(new NullLogger())->convertToMp4($source, $target));

        self::assertSame(['h264', 'aac'], $this->codecs($target));
    }

    public function testInterlacedVideoIsDetectedAndConvertedTo50p(): void
    {
        // 50 Bewegungsphasen/s zu 25 Bildern mit je zwei Halbbildern, als interlaced gekennzeichnet (wie AVCHD 50i)
        $source = $this->interlaced('source.mp4', ['-c:v', 'libx264', '-flags', '+ilme+ildct', '-pix_fmt', 'yuv420p']);
        $target = $this->dir . '/target.mp4';
        $converter = new Mp4Converter(new NullLogger());

        $fieldOrder = $converter->detectFieldOrder($source);
        self::assertSame('tt', $fieldOrder);
        self::assertTrue($converter->convertToMp4($source, $target, $fieldOrder));

        // Trotz H.264 nicht nur umgepackt: neu kodiert, progressiv, doppelte Bildrate
        self::assertNotSame($this->videoStreamHash($source), $this->videoStreamHash($target));
        self::assertSame(['progressive', '50/1'], $this->fieldOrderAndRate($target));
    }

    public function testUnmarkedInterlacedVideoIsFoundByIdet(): void
    {
        // MJPEG in AVI gibt keine Halbbild-Reihenfolge an ("unknown")
        $source = $this->interlaced('source.avi', ['-c:v', 'mjpeg']);

        self::assertSame('tt', new Mp4Converter(new NullLogger())->detectFieldOrder($source));
    }

    public function testProgressiveVideoStaysProgressive(): void
    {
        $source = $this->generate('source.mov', ['-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac']);

        self::assertSame('progressive', new Mp4Converter(new NullLogger())->detectFieldOrder($source));
    }

    /**
     * @param list<string> $codecArgs
     */
    private function interlaced(string $name, array $codecArgs): string
    {
        $path = $this->dir . '/' . $name;
        new Process([
            'ffmpeg',
            '-v',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc2=duration=4:size=720x576:rate=50',
            '-vf',
            'tinterlace=mode=interleave_top,fieldorder=tff',
            ...$codecArgs,
            $path,
        ])->mustRun();

        return $path;
    }

    /**
     * @return array{string, string}
     */
    private function fieldOrderAndRate(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=field_order,r_frame_rate',
            '-of',
            'json',
            $path,
        ]);
        $process->mustRun();
        $stream = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR)['streams'][0] ?? [];

        return [(string) ($stream['field_order'] ?? ''), (string) ($stream['r_frame_rate'] ?? '')];
    }

    /**
     * @param list<string> $codecArgs
     */
    private function generate(string $name, array $codecArgs): string
    {
        $path = $this->dir . '/' . $name;
        $process = new Process([
            'ffmpeg',
            '-v',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'testsrc=duration=1:size=320x240:rate=10',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=1',
            ...$codecArgs,
            $path,
        ]);
        $process->mustRun();

        return $path;
    }

    /**
     * @return list<string>
     */
    private function codecs(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'stream=codec_name',
            '-of',
            'csv=p=0',
            $path,
        ]);
        $process->mustRun();

        return array_values(array_filter(array_map(trim(...), explode("\n", $process->getOutput()))));
    }

    private function videoStreamHash(string $path): string
    {
        $process = new Process([
            'ffmpeg',
            '-v',
            'error',
            '-i',
            $path,
            '-map',
            '0:v:0',
            '-c',
            'copy',
            '-f',
            'hash',
            '-',
        ]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
