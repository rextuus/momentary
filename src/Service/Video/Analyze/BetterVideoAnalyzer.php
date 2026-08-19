<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Service\Video\Analyze\Result\ChapterGenerationResult;
use App\Service\Video\Analyze\Result\EmptyScenesMergerResult;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Analyze\Result\JellyfinExportResult;
use App\Service\Video\Analyze\Result\RefinementAnalyzeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class BetterVideoAnalyzer
{
    public function __construct(
        private FrameAnalyzer $frameAnalyzer,
        private PathResolver $pathResolver,
        private Mp4Converter $mp4Converter,
        private SceneDetector $sceneDetector,
        private SceneThumbnailExtractor $sceneThumbnailExtractor,
        private FrameExtractor $frameExtractor,
        private EmptyScenesMerger $emptyScenesMerger,
        private ChapterGenerator $chapterGenerator,
        private JellyfinUploader $jellyfinUploader,
        #[Autowire('%env(default:app.min_scene_length_for_refinement:MIN_SCENE_LENGTH_FOR_REFINEMENT)%')]
        private float $minSceneLengthForRefinement = 2.0,
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
        bool $isRefinement = false
    ): FrameSplittingResult {
        return $this->frameExtractor->extractFrames(
            $videoId,
            $videoPath,
            $fps,
            $startTime,
            $duration,
            $isRefinement
        );
    }


    public function refineSceneAnalysis(Video $video): RefinementAnalyzeResult
    {
        $minSceneLength = $video->getMinSceneLengthForRefinement() ?? $this->minSceneLengthForRefinement;

        $scenesToRefine = [];
        foreach ($video->getScenes() as $scene) {
            $duration = $scene->getEndSeconds() - $scene->getStartSeconds();

            // only refine scenes without faces and long enough
            if ($scene->getVideoFaces()->isEmpty() && $duration >= $minSceneLength) {
                $scenesToRefine[] = $scene;
            }
        }

        return RefinementAnalyzeResult::create($scenesToRefine);
    }

    public function mergeEmptyScenes(Video $video): EmptyScenesMergerResult
    {
        return $this->emptyScenesMerger->mergeEmptyScenes($video);
    }

    public function generateChapters(Video $video): ChapterGenerationResult
    {
        return $this->chapterGenerator->generateChapters($video);
    }

    public function exportVideo(Video $video): JellyfinExportResult
    {
        return $this->jellyfinUploader->exportVideo($video);
    }
}
