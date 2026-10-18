<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\File;
use App\Entity\Video;
use App\Service\Video\Analyze\Result\ChapterGenerationResult;
use App\Service\Video\Analyze\Result\EmptyScenesMergerResult;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Analyze\Result\JellyfinExportResult;
use App\Service\Video\Analyze\Result\RefinementAnalyzeResult;
use App\Service\VideoFileService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class BetterVideoAnalyzer
{
    public function __construct(
        private FrameAnalyzer $frameAnalyzer,
        private Mp4Converter $mp4Converter,
        private SceneDetector $sceneDetector,
        private SceneThumbnailExtractor $sceneThumbnailExtractor,
        private FrameExtractor $frameExtractor,
        private EmptyScenesMerger $emptyScenesMerger,
        private ChapterGenerator $chapterGenerator,
        private JellyfinUploader $jellyfinUploader,
        private VideoFileService $videoFileService,
        #[Autowire('%env(default:app.min_scene_length_for_refinement:MIN_SCENE_LENGTH_FOR_REFINEMENT)%')]
        private float $minSceneLengthForRefinement = 2.0,
    ) {}

    public function getAbsolutePath(string $path): string
    {
        return $this->videoFileService->getAbsolutePath($path);
    }

    public function analyzeFrame(int $videoId, string $framePath, float|int $timestamp): void
    {
        $this->frameAnalyzer->analyzeFrame($videoId, $framePath, (int) $timestamp);
    }

    /**
     * Laufzeit in Sekunden laut ffprobe, null wenn nicht ermittelbar.
     */
    public function probeDuration(string $path): ?float
    {
        $duration = $this->mp4Converter->probe($path)['duration'];

        return $duration > 0 ? $duration : null;
    }

    /**
     * Aufnahmezeitpunkt (UTC), null wenn unbekannt.
     *
     * Ein Zeitstempel im Dateinamen (Action-Cams, Android: "20250319_121537", "VID_20250319_121537") ist die Ortszeit
     * der Kamera und hat Vorrang: manche Kameras schreiben creation_time mit falscher Zeitzone. Sonst gilt
     * creation_time aus den Metadaten (z. B. iPhone, dort korrekt in UTC).
     */
    public function probeRecordedAt(string $path): ?\DateTimeImmutable
    {
        if (
            preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})[_\-T ]?(\d{2})(\d{2})(\d{2})(?!\d)/', basename($path), $m) === 1
        ) {
            $local = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]),
                new \DateTimeZone('Europe/Berlin'),
            );
            if ($local !== false && $local->format('YmdHis') === $m[1] . $m[2] . $m[3] . $m[4] . $m[5] . $m[6]) {
                return $local->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        $createdAt = $this->mp4Converter->probe($path)['createdAt'] ?? null;
        if ($createdAt === null || $createdAt === '' || str_starts_with($createdAt, '1970')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($createdAt)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function convertToMp4(string $sourcePath, string $targetPath): bool
    {
        return $this->mp4Converter->convertToMp4($sourcePath, $targetPath);
    }

    /**
     * @return list<array<string, mixed>> Szenen wie von PySceneDetect geliefert
     */
    public function detectScenes(
        string $videoPath,
        int $videoId,
        float $threshold = 27.0,
        string $detector = 'content',
    ): array {
        return $this->sceneDetector->detectScenes($videoPath, $videoId, $threshold, $detector);
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): ?File
    {
        return $this->sceneThumbnailExtractor->extractThumbnail($video, $timeInSeconds, $customFilename);
    }

    /**
     * @param float|list<float>|null $startTime
     * @param float|list<float>|null $duration
     */
    public function extractFrames(
        int $videoId,
        string $videoPath,
        ?float $fps = null,
        array|float|null $startTime = null,
        array|float|null $duration = null,
        bool $isRefinement = false,
        ?string $subDirectory = null,
    ): FrameSplittingResult {
        return $this->frameExtractor->extractFrames(
            $videoId,
            $videoPath,
            $fps,
            $startTime,
            $duration,
            $isRefinement,
            $subDirectory,
        );
    }

    public function refineSceneAnalysis(Video $video): RefinementAnalyzeResult
    {
        $minSceneLength = $video->getMinSceneLengthForRefinement() ?? $this->minSceneLengthForRefinement;

        $scenesToRefine = [];
        foreach ($video->getScenes() as $scene) {
            $duration = $scene->getEndSeconds() - $scene->getStartSeconds();

            $sceneId = $scene->getId();
            if ($sceneId !== null && $scene->getVideoFaces()->isEmpty() && $duration >= $minSceneLength) {
                $scenesToRefine[] = $sceneId;
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
