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
     * Analysiert ein Bild, indiziert das Gesicht und sucht nach Übereinstimmungen.
     */
    public function processFaceImage(string $imagePath): array
    {
        $imageContent = file_get_contents($imagePath);

        // 1. Gesicht indizieren und Metadaten extrahieren
        $indexResponse = $this->client->indexFaces([
            'CollectionId' => $this->collectionId,
            'Image' => ['Bytes' => $imageContent],
            'DetectionAttributes' => ['ALL'], // Wichtig für Alter, Emotion, Gender
            'MaxFaces' => 1, // Wir gehen von einem Gesicht pro Screenshot aus
        ]);

        if (empty($indexResponse['FaceRecords'])) {
            return []; // Kein Gesicht gefunden
        }

        $faceRecord = $indexResponse['FaceRecords'][0];
        $faceId = $faceRecord['Face']['FaceId'];
        $details = $faceRecord['FaceDetail'];

        // 2. Sofort prüfen: Kennen wir dieses Gesicht schon aus anderen Videos?
        $searchResponse = $this->client->searchFaces([
            'CollectionId' => $this->collectionId,
            'FaceId' => $faceId,
            'FaceMatchThreshold' => 90.0, // 90% Ähnlichkeit
            'MaxFaces' => 1,
        ]);

        $matchedFaceId = null;
        $similarity = null;

        if (!empty($searchResponse['FaceMatches'])) {
            $match = $searchResponse['FaceMatches'][0];
            $matchedFaceId = $match['Face']['FaceId'];
            $similarity = $match['Similarity'];
        }

        // 3. Daten für die Entity aufbereiten
        return [
            'faceId' => $faceId,
            'matchedFaceId' => $matchedFaceId,
            'similarity' => $similarity,
            'age' => $this->calculateAverageAge($details['AgeRange']),
            'gender' => $details['Gender']['Value'] ?? 'Unknown',
            'emotion' => $details['Emotions'][0]['Type'] ?? 'CALM', // Die dominanteste Emotion
            'confidence' => $details['Confidence']
        ];
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
