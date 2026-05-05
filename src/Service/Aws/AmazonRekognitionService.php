<?php

namespace App\Service\Aws;

use Aws\Rekognition\RekognitionClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AmazonRekognitionService
{
    private RekognitionClient $client;
    private string $collectionId = 'family-archive-collection';

    public function __construct(
        #[Autowire(env: 'AWS_ACCESS_KEY')] string $awsKey,
        #[Autowire(env: 'AWS_SECRET_KEY')] string $awsSecret,
        #[Autowire(env: 'AWS_REGION')] string $region
    ) {
        $this->client = new RekognitionClient([
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    }

    /**
     * Analysiert ein Bild und verarbeitet ALLE darin gefundenen Gesichter einzeln.
     * Ideal für Gruppenfotos wie in grafik.jpg.
     */
    public function processAllFacesInImage(string $imagePath): array
    {
        $imageContent = file_get_contents($imagePath);
        $results = [];

        // 1. Alle Gesichter im Bild indizieren
        // Wir setzen MaxFaces auf 15, um sicherzustellen, dass niemand übersehen wird.
        $indexResponse = $this->client->indexFaces([
            'CollectionId' => $this->collectionId,
            'Image' => ['Bytes' => $imageContent],
            'DetectionAttributes' => ['ALL'], // Extrahiert Alter, Emotionen, Gender
            'MaxFaces' => 15,
        ]);

        if (empty($indexResponse['FaceRecords'])) {
            return [];
        }

        // 2. Jedes erkannte Gesicht einzeln gegen die Collection prüfen
        foreach ($indexResponse['FaceRecords'] as $faceRecord) {
            $faceId = $faceRecord['Face']['FaceId'];
            $details = $faceRecord['FaceDetail'];

            // Suche nach Übereinstimmungen für diese spezifische FaceId
            $searchResponse = $this->client->searchFaces([
                'CollectionId' => $this->collectionId,
                'FaceId' => $faceId,
                'FaceMatchThreshold' => 90.0,
                'MaxFaces' => 1,
            ]);

            $matchedFaceId = null;
            $similarity = null;

            if (!empty($searchResponse['FaceMatches'])) {
                $match = $searchResponse['FaceMatches'][0];
                $matchedFaceId = $match['Face']['FaceId'];
                $similarity = $match['Similarity'];
            }

            // 3. Ergebnisse für dieses Gesicht sammeln
            $results[] = [
                'faceId' => $faceId,
                'matchedFaceId' => $matchedFaceId,
                'similarity' => $similarity,
                'age' => $this->calculateAverageAge($details['AgeRange']),
                'gender' => $details['Gender']['Value'] ?? 'Unknown',
                'emotion' => $details['Emotions'][0]['Type'] ?? 'CALM',
                'confidence' => $details['Confidence'],
                // BoundingBox ist essentiell für den blauen Rahmen im Modal
                'boundingBox' => $faceRecord['Face']['BoundingBox']
            ];
        }

        return $results;
    }

    private function calculateAverageAge(array $ageRange): int
    {
        return (int) (($ageRange['Low'] + $ageRange['High']) / 2);
    }

    public function getClient(): RekognitionClient
    {
        return $this->client;
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }
}