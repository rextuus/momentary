<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video;

use App\Entity\Video;
use App\Enum\FilePurpose;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\VideoFiles;
use App\Service\Video\WebVersion\WebVersionEncoder;
use App\Service\Video\WebVersion\WebVersionFormat;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Mit echtem ffmpeg an kurzen, künstlich erzeugten Videos.
 */
final class WebVersionEncoderTest extends KernelTestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directories);
        parent::tearDown();
    }

    private function video(int $width, int $height, string $bitrate): Video
    {
        $relative = sprintf('videos/web-test-%dx%d/converted.mp4', $width, $height);
        $path = self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($relative);
        new Filesystem()->mkdir(\dirname($path));
        $this->directories[] = \dirname($path);
        $process = new Process([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            sprintf('testsrc2=size=%dx%d:rate=25:duration=1', $width, $height),
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=1',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-b:v',
            $bitrate,
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-shortest',
            $path,
        ]);
        $process->mustRun();

        $video = VideoFactory::new()->completed()->create();
        $video->setConvertedFile(FileFactory::createOne([
            'relativePath' => $relative,
            'purpose' => FilePurpose::VIDEO_CONVERTED,
            'mimeType' => 'video/mp4',
        ]));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $video;
    }

    /**
     * @return list<int>
     */
    private static function dimensions(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height',
            '-of',
            'csv=p=0',
            $path,
        ]);
        $process->mustRun();

        return array_map(intval(...), explode(',', trim($process->getOutput())));
    }

    public function testLargeVideosGetASmallerWebVersionInPortraitAndLandscape(): void
    {
        foreach ([[2560, 1440, [1920, 1080]], [1440, 2560, [1080, 1920]]] as [$width, $height, $expected]) {
            $video = $this->video($width, $height, '20M');
            $encoder = self::getContainer()->get(WebVersionEncoder::class);

            self::assertStringContainsString('Web-Fassung erstellt', $encoder->encode($video));
            $web = self::getContainer()->get(VideoFiles::class)->existing($video->getWebFile());
            self::assertNotNull($web);
            self::assertSame(FilePurpose::VIDEO_WEB, $video->getWebFile()?->getPurpose());
            self::assertSame($expected, self::dimensions($web), 'kürzere Seite 1080, Seitenverhältnis bleibt');
            self::assertStringEndsWith('/web.mp4', $web);
            self::assertStringContainsString('schon da', $encoder->encode($video));

            $stream = self::getContainer()->get(VideoFiles::class)->streamFile($video);
            self::assertSame(['path' => $web, 'quality' => VideoFiles::QUALITY_WEB], $stream);
            self::assertSame(
                VideoFiles::QUALITY_ORIGINAL,
                self::getContainer()
                    ->get(VideoFiles::class)
                    ->streamFile($video, VideoFiles::QUALITY_ORIGINAL)['quality'] ?? null,
            );
        }
    }

    public function testSmallVideosNeedNoWebVersion(): void
    {
        $video = $this->video(640, 360, '500k');

        self::assertStringContainsString(
            'klein genug',
            self::getContainer()->get(WebVersionEncoder::class)->encode($video),
        );
        self::assertNull($video->getWebFile());
        self::assertSame(
            VideoFiles::QUALITY_ORIGINAL,
            self::getContainer()->get(VideoFiles::class)->streamFile($video)['quality'] ?? null,
        );
    }

    public function testSmallEnoughRule(): void
    {
        self::assertTrue(WebVersionFormat::smallEnough(['shortSide' => 1080, 'bitrate' => 5_000_000]));
        self::assertFalse(WebVersionFormat::smallEnough(['shortSide' => 2160, 'bitrate' => 5_000_000]), '4K');
        self::assertFalse(
            WebVersionFormat::smallEnough(['shortSide' => 1080, 'bitrate' => 25_000_000]),
            'zu hohe Bitrate',
        );
    }
}
