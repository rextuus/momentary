<?php

declare(strict_types=1);

namespace App\Service\Face\Provider;

use App\Entity\Medium;
use App\Service\Face\DetectedFace;
use App\Service\Face\Embedding\EmbeddingIndex;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\InsightFace\FaceServiceResult;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Selbst gehostete Gesichtserkennung über den face-service (InsightFace, face-service/README.md).
 *
 * Der Dienst liefert Boxen und Embeddings; die Kandidaten (ähnliche gespeicherte Gesichter) sucht EmbeddingIndex in
 * PHP. Sie werden als Kosinus × 100 gespeichert, damit FaceMatcher und VideoFaceConsolidator unverändert arbeiten.
 *
 * Optionaler Vorfilter (FACE_PREFILTER_DET_SIZE > 0): erst billig mit kleiner Detektionsgröße prüfen, ob überhaupt ein
 * Gesicht im Bild ist. Spart bei Frames ohne Gesicht Zeit, detektiert bei Frames mit Gesicht aber doppelt und
 * übersieht kleine Gesichter; deshalb standardmäßig aus.
 */
final class InsightFaceProvider implements FaceRecognitionProviderInterface
{
    public const string NAME = 'insightface';

    public function __construct(
        private readonly FaceServiceClient $client,
        private readonly EmbeddingIndex $index,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        #[Autowire('%env(int:FACE_PREFILTER_DET_SIZE)%')]
        private readonly int $prefilterDetSize = 0,
    ) {}

    public static function getName(): string
    {
        return self::NAME;
    }

    public function detectFaces(string $imagePath, Medium $medium): array
    {
        return $this->detect($imagePath, $medium, $this->prefilterDetSize)['faces'];
    }

    /**
     * Wie detectFaces(), mit Messwerten (für app:face:analyze).
     *
     * @param int $prefilterDetSize 0 = ohne Vorfilter
     *
     * @return array{faces: list<DetectedFace>, prefilter: ?FaceServiceResult, analysis: ?FaceServiceResult, matchMs: float}
     */
    public function detect(string $imagePath, Medium $medium, int $prefilterDetSize): array
    {
        $settings = $this->settingsProvider->get();
        $minFacePx = $settings->getInsightFaceMinFacePx();
        $maxFaces = $settings->getInsightFaceMaxFaces();

        $prefilter = null;
        if ($prefilterDetSize > 0) {
            $prefilter = $this->client->detect(
                $imagePath,
                $prefilterDetSize,
                minFacePx: $minFacePx,
                maxFaces: $maxFaces,
            );
            if (!$prefilter->hasFaces()) {
                return ['faces' => [], 'prefilter' => $prefilter, 'analysis' => null, 'matchMs' => 0.0];
            }
        }

        $analysis = $this->client->analyze($imagePath, minFacePx: $minFacePx, maxFaces: $maxFaces);
        $mediumId = (int) $medium->getId();
        $takenAt = $medium->getTakenAt();
        $year = $takenAt !== null ? (int) $takenAt->format('Y') : null;
        $candidateThreshold = $settings->getInsightFaceCandidateThreshold();

        $started = microtime(true);
        $faces = [];
        foreach ($analysis->faces as $face) {
            if ($face->embedding === null) {
                continue;
            }
            $label = Uuid::uuid4()->toString();
            $candidates = $this->index->candidates(
                $face->embedding,
                $mediumId,
                $analysis->model,
                $candidateThreshold,
                $settings->getMaxCandidates(),
                $year,
            );

            $faces[] = new DetectedFace(
                faceId: $label,
                boundingBox: $face->relativeBox($analysis->width, $analysis->height),
                confidence: round($face->score * 100, 2),
                age: $face->age ?? 0,
                gender: $face->genderLabel(),
                emotion: null,
                candidates: array_map(static fn(float $cosine): float => round($cosine * 100, 2), $candidates),
                embedding: $face->embedding,
                embeddingModel: $analysis->model,
                sizePx: (int) round($face->shortSide()),
            );
        }
        // Erst nach dem Abgleich merken: Gesichter desselben Bildes sind nie dieselbe Person
        foreach ($faces as $detected) {
            if ($detected->embedding !== null) {
                $this->index->remember($detected->faceId, $detected->embedding, $mediumId, $analysis->model);
            }
        }

        return [
            'faces' => $faces,
            'prefilter' => $prefilter,
            'analysis' => $analysis,
            'matchMs' => (microtime(true) - $started) * 1000,
        ];
    }

    /**
     * Embeddings liegen nur in der eigenen Datenbank; es genügt, sie aus dem Speicher des Workers zu nehmen.
     */
    public function forgetFaces(array $faceLabels): void
    {
        $this->index->forget($faceLabels);
    }
}
