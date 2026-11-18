<?php

declare(strict_types=1);

namespace App\Service\Face\Benchmark;

use App\Entity\Video;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\FrameExtractor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Frames für app:face:analyze, mit derselben Aufbereitung wie die Pipeline (FrameExtractor), aber in einem eigenen
 * Ordner frames/<id>/analysis/face-benchmark, der danach wieder verschwindet.
 */
class BenchmarkFrames
{
    public const string MODE_FPS = 'fps';
    public const string MODE_SCENES = 'scenes';

    private const string FRAME_SUBDIRECTORY = 'face-benchmark';

    public function __construct(
        private readonly FrameExtractor $frameExtractor,
        private readonly StoragePathProvider $pathProvider,
    ) {}

    /**
     * @return list<array{string, int}> Pfad und Zeitpunkt (Sekunden) je Frame
     */
    public function extract(Video $video, ?float $fps, string $mode): array
    {
        $file = $video->getConvertedFile() ?? $video->getSourceFile();
        if ($file === null) {
            throw new \RuntimeException('Das Video hat keine Datei.');
        }
        $videoPath = $this->pathProvider->getAbsolutePath($file);

        if ($mode === self::MODE_SCENES) {
            // Ein Bild aus der Mitte jeder Szene
            $starts = [];
            $durations = [];
            foreach ($video->getScenes() as $scene) {
                $starts[] = max(0.0, (($scene->getStartSeconds() + $scene->getEndSeconds()) / 2) - 0.1);
                $durations[] = 0.2;
            }
            if ($starts === []) {
                throw new \RuntimeException('Das Video hat noch keine Szenen.');
            }
            $result = $this->frameExtractor->extractFrames(
                (int) $video->getId(),
                $videoPath,
                1.0,
                $starts,
                $durations,
                subDirectory: self::FRAME_SUBDIRECTORY,
            );
        } else {
            $result = $this->frameExtractor->extractFrames(
                (int) $video->getId(),
                $videoPath,
                $fps,
                subDirectory: self::FRAME_SUBDIRECTORY,
            );
        }

        return array_map(static fn($frame): array => [
            $frame->getPath(),
            $frame->getTimestamp(),
        ], $result->getFrameList());
    }

    public function remove(Video $video): void
    {
        new Filesystem()->remove($this->directory($video));
    }

    private function directory(Video $video): string
    {
        return $this->pathProvider->getAbsoluteFilePath(sprintf(
            'frames/%d/analysis/%s',
            (int) $video->getId(),
            self::FRAME_SUBDIRECTORY,
        ));
    }
}
