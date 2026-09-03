<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\VideoScene;
use App\Service\Gemini\GeminiService;
use App\Service\Video\Analyze\Result\TaggingResult;
use App\Service\VideoFileService;

class TaggingService
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly BetterVideoAnalyzer $videoAnalyzer,
        private readonly VideoFileService $videoFileService,
        private readonly TagService $tagService,
    ) {
    }

    public function tagScene(VideoScene $scene): TaggingResult
    {
        try {
            $video = $scene->getVideo();
            $start = (float) $scene->getStartSeconds();
            $end = (float) $scene->getEndSeconds();
            $duration = $end - $start;

            $timestamps = [];
            if ($duration <= 10) {
                $timestamps[] = $start + ($duration / 2);
            } elseif ($duration <= 60) {
                $timestamps[] = $start;
                $timestamps[] = $start + ($duration / 2);
                $timestamps[] = $end;
            } else {
                $count = (int) ceil($duration / 30);
                for ($i = 0; $i <= $count; $i++) {
                    $timestamps[] = $start + ($duration * ($i / $count));
                }
            }

            echo "[TaggingService] Starte Analyse für Szene ID: {$scene->getId()} (Duration: {$duration}s, Timestamps: " . count($timestamps) . ")" . PHP_EOL;

            $allTags = [];

            foreach ($timestamps as $time) {
                $thumbnailFile = $this->videoAnalyzer->extractThumbnail(
                    $video,
                    $time,
                    sprintf('scene_analysis_%d_%d.jpg', $scene->getId(), (int)$time)
                );

                echo "[TaggingService] Thumbnail RelPath: " . var_export($thumbnailFile?->getRelativePath(), true) . PHP_EOL;

                if (!$thumbnailFile) {
                    echo "[TaggingService] Kein Thumbnail-Pfad zurückgegeben für Timestamp {$time}" . PHP_EOL;
                    continue;
                }

                $thumbnailRelPath = $thumbnailFile->getRelativePath();
                $cleanPath = explode('?', $thumbnailRelPath)[0];

                $resolvedPath = str_starts_with($cleanPath, '/')
                    ? $cleanPath
                    : $this->videoFileService->getAbsolutePath($cleanPath);

                echo "[TaggingService] Geprüfter ResolvedPath: {$resolvedPath} | Exists: " . (file_exists($resolvedPath) ? 'JA' : 'NEIN') . PHP_EOL;

                if (file_exists($resolvedPath)) {
                    $tagsData = $this->geminiService->analyzeImage($resolvedPath);
                    echo "[TaggingService] Gemini Raw Data: " . json_encode($tagsData) . PHP_EOL;

                    if ($scene->getTitle() === null && isset($tagsData['Titel'])) {
                        $scene->setTitle($tagsData['Titel']);
                    }

                    $tagsOnly = $tagsData['Tags'] ?? $tagsData;

                    foreach ($tagsOnly as $category => $tags) {
                        if (!is_array($tags)) {
                            continue;
                        }
                        if (!isset($allTags[$category])) {
                            $allTags[$category] = [];
                        }
                        $allTags[$category] = array_unique(array_merge($allTags[$category], $tags));
                    }
                }
            }

            echo "[TaggingService] Gesammelte Tags vor Übergabe an TagService: " . json_encode($allTags) . PHP_EOL;

            // Delegation an den neuen TagService
            $this->tagService->assignAiTagsToScene($scene, $allTags);

            echo "[TaggingService] Szene {$scene->getId()} erfolgreich getaggt und gespeichert." . PHP_EOL;

            return new TaggingResult(
                true,
                true,
                [],
                sprintf('Successfully analyzed and tagged scene %d.', $scene->getId())
            );

        } catch (\Throwable $e) {
            echo "[TaggingService FEHLER]: " . $e->getMessage() . PHP_EOL;
            return new TaggingResult(
                false,
                false,
                [],
                $e->getMessage()
            );
        }
    }
}