<?php

declare(strict_types=1);

namespace App\Service\Face\Benchmark;

use App\Entity\Video;
use App\Repository\VideoSceneRepository;
use App\Service\Face\FaceAssigner;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\FaceRecorder;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\Provider\FaceRecognitionProviders;
use App\Service\Face\Provider\InsightFaceProvider;
use App\Service\Face\VideoFaceConsolidator;
use App\Service\Face\VideoFaceRemover;

/**
 * Lässt ein einzelnes Video durch die Gesichtserkennung mit InsightFace laufen und misst (app:face:analyze).
 *
 * Standard ist nur messen: Frames in einen eigenen Ordner, je Frame face-service und Abgleich gegen die vorhandenen
 * Personen, nichts wird gespeichert. Mit $save werden die bisherigen Gesichter des Videos ersetzt und danach wie in
 * der Pipeline konsolidiert.
 *
 * Verwendet immer InsightFace, auch wenn FACE_RECOGNITION_PROVIDER etwas anderes sagt: Messen soll nie AWS kosten.
 */
class FaceBenchmark
{
    public const string MODE_FPS = BenchmarkFrames::MODE_FPS;
    public const string MODE_SCENES = BenchmarkFrames::MODE_SCENES;

    public function __construct(
        private readonly BenchmarkFrames $frames,
        private readonly FaceRecognitionProviders $providers,
        private readonly FaceServiceClient $client,
        private readonly FaceAssigner $assigner,
        private readonly FaceRecorder $recorder,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        private readonly VideoSceneRepository $sceneRepository,
        private readonly VideoFaceConsolidator $consolidator,
        private readonly VideoFaceRemover $faceRemover,
    ) {}

    /**
     * @param float|null    $fps              null = Bildrate des Videos bzw. FRAME_ANALYSIS_FPS
     * @param int|null      $prefilterDetSize zusätzlich den Vorfilter messen (z. B. 320) und ein-/zweistufig vergleichen
     * @param callable|null $onFrame          fn(int $done, int $total) für einen Fortschrittsbalken
     */
    public function run(
        Video $video,
        ?float $fps = null,
        string $mode = self::MODE_FPS,
        ?int $limit = null,
        ?int $prefilterDetSize = null,
        bool $save = false,
        ?callable $onFrame = null,
    ): FaceBenchmarkReport {
        $provider = $this->providers->get(InsightFaceProvider::NAME);
        \assert($provider instanceof InsightFaceProvider, 'InsightFace ist immer registriert.');

        $frames = $this->frames->extract($video, $fps, $mode);
        if ($limit !== null) {
            $frames = \array_slice($frames, 0, $limit);
        }
        $report = new FaceBenchmarkReport($prefilterDetSize);
        if ($save) {
            $this->faceRemover->removeAll($video);
        }

        $settings = $this->settingsProvider->get();
        $thresholds = $this->settingsProvider->thresholds();
        try {
            foreach ($frames as $i => [$path, $timestamp]) {
                $prefilter = null;
                $prefilterMs = null;
                if ($prefilterDetSize !== null) {
                    $started = microtime(true);
                    $prefilter = $this->client->detect(
                        $path,
                        $prefilterDetSize,
                        minFacePx: $settings->getInsightFaceMinFacePx(),
                        maxFaces: $settings->getInsightFaceMaxFaces(),
                    );
                    $prefilterMs = (microtime(true) - $started) * 1000;
                }

                $started = microtime(true);
                $result = $provider->detect($path, $video, 0);
                $wallMs = (microtime(true) - $started) * 1000;
                $analysis = $result['analysis'];
                if ($analysis !== null) {
                    $report->noteModel($analysis->model);
                }

                // Was bei den aktuellen Schwellen geschähe; Gesichter desselben Bildes sind nie dieselbe Person
                $personIdsInFrame = [];
                foreach ($result['faces'] as $face) {
                    $assignment = $this->assigner->assign($face, $personIdsInFrame, $thresholds);
                    $report->addFace($assignment->best !== null ? $assignment->best->similarity / 100 : null, match (
                        true
                    ) {
                        $assignment->person !== null => FaceBenchmarkReport::ASSIGNED,
                        $assignment->suggestion !== null => FaceBenchmarkReport::SUGGESTED,
                        default => FaceBenchmarkReport::UNKNOWN,
                    });
                    if ($assignment->person?->getId() !== null) {
                        $personIdsInFrame[] = (int) $assignment->person->getId();
                    }
                }

                if ($save && $result['faces'] !== []) {
                    $this->recorder->record(
                        $video,
                        InsightFaceProvider::NAME,
                        $result['faces'],
                        $path,
                        $timestamp,
                        $this->sceneRepository->findAt($video, $timestamp),
                    );
                }

                $timings = $analysis !== null ? $analysis->timingsMs : [];
                $report->addFrame([
                    'timestamp' => $timestamp,
                    'wallMs' => $wallMs,
                    'detectMs' => $timings['detect'] ?? 0.0,
                    'embedMs' => $timings['embed'] ?? 0.0,
                    'waitMs' => $timings['wait'] ?? 0.0,
                    'matchMs' => $result['matchMs'],
                    'faces' => \count($result['faces']),
                    'tooSmall' => $analysis !== null ? $analysis->filteredTooSmall : 0,
                    'overLimit' => $analysis !== null ? $analysis->filteredOverLimit : 0,
                    'prefilterMs' => $prefilterMs,
                    'prefilterFaces' => $prefilter !== null ? \count($prefilter->faces) : null,
                ]);
                if ($onFrame !== null) {
                    $onFrame($i + 1, \count($frames));
                }
            }
        } finally {
            $this->frames->remove($video);
        }

        if ($save) {
            $this->consolidator->consolidate($video);
        }

        return $report;
    }
}
