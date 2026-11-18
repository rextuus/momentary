<?php

declare(strict_types=1);

namespace App\Service\Face\Embedding;

use App\Service\Face\FaceRecognitionSettingsProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sucht zu einem neuen Embedding die ähnlichsten schon gespeicherten Gesichter (Kandidaten für FaceMatcher und
 * VideoFaceConsolidator).
 *
 * Verglichen wird nicht mit allen Gesichtern, sondern mit
 * - je Person höchstens FACE_MATCH_REFERENCES_PER_PERSON Referenzen, nur aus ausreichend sicheren und großen
 *   Gesichtern (ReferenceSelector, Einstellungen insightFaceReference*) und
 * - allen Gesichtern des Mediums, das gerade analysiert wird (für die Gruppierung innerhalb eines Videos).
 *
 * Die Referenzen bleiben im Worker im Speicher (als Binärdaten, 2 KB je Gesicht). Sie hängen vom Aufnahmejahr des
 * Mediums ab (nahe Jahre bevorzugt) und werden neu ausgewählt, wenn sich das Jahr ändert, spätestens nach
 * RELOAD_AFTER_SECONDS. Die Gesichter des Mediums kommen beim Wechsel des Mediums, neue über remember() sofort dazu.
 */
class EmbeddingIndex
{
    private const int RELOAD_AFTER_SECONDS = 600;

    private ?string $model = null;
    /** Aufnahmejahr, für das die Referenzen ausgewählt sind (ReferenceSelector bevorzugt nahe Jahre). */
    private ?int $year = null;
    private ?int $mediumId = null;
    private float $loadedAt = 0.0;

    /** @var array<string, string> faceLabel => Embedding (float32) */
    private array $references = [];

    /** @var array<string, string> faceLabel => Embedding (float32), Gesichter des aktuellen Mediums */
    private array $mediumFaces = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        #[Autowire('%env(int:FACE_MATCH_REFERENCES_PER_PERSON)%')]
        private readonly int $referencesPerPerson = 20,
    ) {}

    /**
     * @param list<float> $embedding normalisiert
     * @param int|null    $year      Aufnahmejahr des Mediums; Referenzen aus nahen Jahren werden bevorzugt
     *
     * @return array<string, float> faceLabel => Kosinus-Ähnlichkeit, absteigend
     */
    public function candidates(
        array $embedding,
        int $mediumId,
        string $model,
        float $minSimilarity,
        int $limit,
        ?int $year = null,
    ): array {
        $this->load($mediumId, $model, $year);

        return EmbeddingMath::topK($embedding, $this->unpacked(), $minSimilarity, $limit);
    }

    /**
     * Ein gerade erkanntes Gesicht für die folgenden Frames desselben Mediums bekannt machen.
     *
     * @param list<float> $embedding
     */
    public function remember(string $faceLabel, array $embedding, int $mediumId, string $model): void
    {
        if ($this->model === $model && $this->mediumId === $mediumId) {
            $this->mediumFaces[$faceLabel] = EmbeddingMath::pack($embedding);
        }
    }

    /**
     * Gelöschte Gesichter nicht mehr als Kandidaten liefern.
     *
     * @param list<string> $faceLabels
     */
    public function forget(array $faceLabels): void
    {
        foreach ($faceLabels as $label) {
            unset($this->references[$label], $this->mediumFaces[$label]);
        }
    }

    /**
     * Beim nächsten Abgleich neu aus der Datenbank laden.
     */
    public function invalidate(): void
    {
        $this->model = null;
        $this->year = null;
        $this->mediumId = null;
        $this->references = [];
        $this->mediumFaces = [];
    }

    /**
     * Anzahl der Gesichter, gegen die verglichen wird (für Messungen).
     */
    public function size(): int
    {
        return \count($this->references + $this->mediumFaces);
    }

    private function load(int $mediumId, string $model, ?int $year): void
    {
        $stale = (microtime(true) - $this->loadedAt) > self::RELOAD_AFTER_SECONDS;
        if ($this->model !== $model || $this->year !== $year || $stale) {
            $this->references = $this->fetchReferences($model, $year);
            $this->model = $model;
            $this->year = $year;
            $this->loadedAt = microtime(true);
            $this->mediumId = null;
        }
        if ($this->mediumId !== $mediumId) {
            $this->mediumFaces = $this->fetchMediumFaces($mediumId, $model);
            $this->mediumId = $mediumId;
        }
    }

    /**
     * @return iterable<string, list<float>>
     */
    private function unpacked(): iterable
    {
        foreach ($this->references + $this->mediumFaces as $label => $binary) {
            yield (string) $label => EmbeddingMath::unpack($binary);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchReferences(string $model, ?int $year): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT f.id, f.person_id, f.is_verified, f.detection_score, f.face_size_px, m.taken_at
             FROM video_face f LEFT JOIN medium m ON m.id = f.medium_id
             WHERE f.embedding_model = ? AND f.embedding_data IS NOT NULL AND f.face_label IS NOT NULL
               AND f.person_id IS NOT NULL', [$model]);
        $settings = $this->settingsProvider->get();
        $ids = ReferenceSelector::select(
            array_map(
                static fn(array $row): ReferenceCandidate => new ReferenceCandidate(
                    faceId: (int) $row['id'],
                    personId: (int) $row['person_id'],
                    verified: (bool) $row['is_verified'],
                    detectionScore: is_numeric($row['detection_score']) ? (float) $row['detection_score'] : null,
                    sizePx: is_numeric($row['face_size_px']) ? (int) $row['face_size_px'] : null,
                    // taken_at "YYYY-MM-DD HH:MM:SS" (UTC); das Jahr genügt
                    year: \is_string($row['taken_at']) && $row['taken_at'] !== ''
                        ? (int) substr($row['taken_at'], 0, 4)
                        : null,
                ),
                $rows,
            ),
            $this->referencesPerPerson,
            $settings->getInsightFaceReferenceMinScore() * 100,
            $settings->getInsightFaceReferenceMinFacePx(),
            $year,
        );

        $references = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $references += self::byLabel($this->connection->fetchAllAssociative(
                'SELECT face_label, embedding_data FROM video_face WHERE id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER],
            ));
        }

        return $references;
    }

    /**
     * @return array<string, string>
     */
    private function fetchMediumFaces(int $mediumId, string $model): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT face_label, embedding_data FROM video_face
             WHERE medium_id = ? AND embedding_model = ? AND embedding_data IS NOT NULL AND face_label IS NOT NULL',
            [$mediumId, $model],
        );

        return self::byLabel($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private static function byLabel(array $rows): array
    {
        $byLabel = [];
        foreach ($rows as $row) {
            $data = $row['embedding_data'];
            if (\is_resource($data)) {
                $data = stream_get_contents($data);
            }
            if (\is_string($data) && $data !== '' && \is_string($row['face_label'])) {
                $byLabel[$row['face_label']] = $data;
            }
        }

        return $byLabel;
    }
}
