<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Service\Video\Analyze\Result\FrameSplittingResult;

readonly class BetterVideoAnalyzer
{
    public function __construct(
        private FrameAnalyzer $frameAnalyzer,
        private PathResolver $pathResolver,
        private Mp4Converter $mp4Converter,
        private SceneDetector $sceneDetector,
        private SceneThumbnailExtractor $sceneThumbnailExtractor,
        private FrameExtractor $frameExtractor,

    ) {
    }

    public function analyzeFrame(int $videoId, string $framePath, float|int $timestamp): void
    {
        $this->frameAnalyzer->analyzeFrame($videoId, $framePath, (int) $timestamp);
    }

    public function resolvePath(string $path): string
    {
        return $this->pathResolver->resolvePath($path);
    }

    public function getProjectDir(): string
    {
        return $this->pathResolver->getProjectDir();
    }

    public function convertToMp4(string $sourcePath, string $targetPath): bool
    {
        return $this->mp4Converter->convertToMp4($sourcePath, $targetPath);
    }

    public function detectScenes(
        string $videoPath,
        int $videoId,
        float $threshold = 27.0,
        string $detector = 'content'
    ): array {
        return $this->sceneDetector->detectScenes($videoPath, $videoId, $threshold, $detector);
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): ?string
    {
        return $this->sceneThumbnailExtractor->extractThumbnail($video, $timeInSeconds, $customFilename);
    }

    public function extractFrames(
        int $videoId,
        string $videoPath,
        ?float $fps = null,
        array|float|null $startTime = null,
        array|float|null $duration = null,
        bool $markLastAsFinal = true,
        bool $isRefinement = false
    ): FrameSplittingResult {
        return $this->frameExtractor->extractFrames(
            $videoId,
            $videoPath,
            $fps,
            $startTime,
            $duration,
            $markLastAsFinal,
            $isRefinement
        );
    }
}
