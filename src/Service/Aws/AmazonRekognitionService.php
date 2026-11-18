<?php

declare(strict_types=1);

namespace App\Service\Aws;

use App\Service\Face\DetectedFace;
use App\Service\Face\FaceRecognitionSettingsProvider;
use Aws\Rekognition\RekognitionClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gesichtserkennung über AWS Rekognition.
 *
 * Ablauf pro Frame: Alle Gesichter werden mit indexFaces in die Collection aufgenommen und danach
 * einzeln per searchFaces abgeglichen. searchFacesByImage eignet sich dafür nicht, weil es nur mit
 * dem größten Gesicht im Bild sucht.
 */
class AmazonRekognitionService
{
    private RekognitionClient $client;
    private string $collectionId = 'family-archive-collection';

    public function __construct(
        #[Autowire(env: 'AWS_ACCESS_KEY')]
        string $awsKey,
        #[\SensitiveParameter]
        #[Autowire(env: 'AWS_SECRET_KEY')]
        string $awsSecret,
        #[Autowire(env: 'AWS_REGION')]
        string $region,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        ?RekognitionClient $client = null,
    ) {
        $this->client = $client ?? new RekognitionClient([
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    }

    /**
     * Indiziert alle ausreichend großen Gesichter eines Bildes und sucht für jedes ähnliche, bereits bekannte Gesichter.
     *
     * @return list<DetectedFace>
     */
    public function processAllFacesInImage(string $imagePath): array
    {
        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new \RuntimeException(sprintf('Image "%s" could not be read.', $imagePath));
        }

        $settings = $this->settingsProvider->get();
        $indexResponse = $this->client->indexFaces([
            'CollectionId' => $this->collectionId,
            'Image' => ['Bytes' => $imageContent],
            'DetectionAttributes' => ['ALL'],
            'MaxFaces' => $settings->getMaxFacesPerFrame(),
            'QualityFilter' => $settings->getQualityFilter(),
        ]);

        $faces = [];
        $tooSmall = [];
        foreach ($indexResponse['FaceRecords'] ?? [] as $record) {
            $faceId = (string) $record['Face']['FaceId'];
            $box = array_map(floatval(...), $record['Face']['BoundingBox'] ?? []);

            // Winzige Gesichter (Gäste im Hintergrund) taugen nicht zum Abgleich und würden später
            // als Kandidaten nur Fehltreffer erzeugen -> gar nicht erst in der Collection behalten
            if (($box['Width'] ?? 0.0) < $settings->getMinFaceWidth()) {
                $tooSmall[] = $faceId;
                continue;
            }

            $detail = $record['FaceDetail'] ?? [];
            $faces[] = new DetectedFace(
                faceId: $faceId,
                boundingBox: $box,
                confidence: (float) ($record['Face']['Confidence'] ?? 0.0),
                age: $this->averageAge($detail['AgeRange'] ?? null),
                gender: (string) ($detail['Gender']['Value'] ?? 'Unknown'),
                emotion: $this->strongestEmotion($detail['Emotions'] ?? []),
                candidates: $this->searchCandidates(
                    $faceId,
                    $settings->getCandidateThreshold(),
                    $settings->getMaxCandidates(),
                ),
            );
        }

        if ($tooSmall !== []) {
            $this->client->deleteFaces(['CollectionId' => $this->collectionId, 'FaceIds' => $tooSmall]);
        }

        return $faces;
    }

    /**
     * @return array<string, float> Rekognition-FaceId => Ähnlichkeit, absteigend sortiert
     */
    private function searchCandidates(string $faceId, float $threshold, int $maxCandidates): array
    {
        $response = $this->client->searchFaces([
            'CollectionId' => $this->collectionId,
            'FaceId' => $faceId,
            'FaceMatchThreshold' => $threshold,
            'MaxFaces' => $maxCandidates,
        ]);

        $candidates = [];
        foreach ($response['FaceMatches'] ?? [] as $match) {
            $candidates[(string) $match['Face']['FaceId']] = (float) $match['Similarity'];
        }
        arsort($candidates);

        return $candidates;
    }

    /**
     * @param array{Low?: int|float, High?: int|float}|null $ageRange
     */
    private function averageAge(?array $ageRange): int
    {
        if ($ageRange === null) {
            return 0;
        }

        return (int) round((($ageRange['Low'] ?? 0) + ($ageRange['High'] ?? 0)) / 2);
    }

    /**
     * @param list<array{Type?: string, Confidence?: float}> $emotions
     */
    private function strongestEmotion(array $emotions): string
    {
        $best = 'CALM';
        $bestConfidence = -1.0;
        foreach ($emotions as $emotion) {
            if (($emotion['Confidence'] ?? 0.0) > $bestConfidence) {
                $best = $emotion['Type'] ?? $best;
                $bestConfidence = $emotion['Confidence'] ?? 0.0;
            }
        }

        return $best;
    }

    public function getClient(): RekognitionClient
    {
        return $this->client;
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }

    /**
     * Anzahl der Gesichter in der Collection; null, wenn sie (noch) nicht existiert.
     */
    public function countIndexedFaces(): ?int
    {
        try {
            return (int) $this->client->describeCollection(['CollectionId' => $this->collectionId])['FaceCount'];
        } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Gesichter aus der Collection entfernen, damit sie bei künftigen Suchen nicht mehr als Treffer auftauchen.
     *
     * @param list<string> $faceIds
     */
    public function deleteFaces(array $faceIds): void
    {
        // AWS nimmt höchstens 4096 IDs pro Aufruf
        foreach (array_chunk(array_values(array_unique($faceIds)), 4096) as $chunk) {
            $this->client->deleteFaces(['CollectionId' => $this->collectionId, 'FaceIds' => $chunk]);
        }
    }

    public function resetCollection(): void
    {
        // @mago-expect lint:no-empty-catch-clause
        try {
            $this->client->deleteCollection(['CollectionId' => $this->collectionId]);
        } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
            // Ignore if it doesn't exist
        }

        $this->client->createCollection(['CollectionId' => $this->collectionId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFaces(): array
    {
        $faces = [];
        $nextToken = null;

        do {
            $options = ['CollectionId' => $this->collectionId];
            if ($nextToken) {
                $options['NextToken'] = $nextToken;
            }

            $response = $this->client->listFaces($options);
            foreach ($response['Faces'] as $face) {
                $faces[] = $face;
            }
            $nextToken = $response['NextToken'] ?? null;
        } while ($nextToken);

        return $faces;
    }
}
