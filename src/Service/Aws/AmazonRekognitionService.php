<?php

namespace App\Service\Aws;

use App\Entity\VideoFace;
use App\Repository\VideoFaceRepository;
use Aws\Rekognition\RekognitionClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AmazonRekognitionService
{
    private RekognitionClient $client;
    private string $collectionId = 'family-archive-collection';

    private float $faceMatchThreshold;
    private int $maxFaces;
    private string $qualityFilter;
    private VideoFaceRepository $videoFaceRepository;

    public function __construct(
        #[Autowire(env: 'AWS_ACCESS_KEY')] string $awsKey,
        #[Autowire(env: 'AWS_SECRET_KEY')] string $awsSecret,
        #[Autowire(env: 'AWS_REGION')] string $region,
        VideoFaceRepository $videoFaceRepository,
        #[Autowire(env: 'AWS_FACE_MATCH_THRESHOLD')] float $faceMatchThreshold = 97.0,
        #[Autowire(env: 'AWS_MAX_FACES')] int $maxFaces = 15,
        #[Autowire(env: 'AWS_QUALITY_FILTER')] string $qualityFilter = 'AUTO'
    ) {
        $this->videoFaceRepository = $videoFaceRepository;
        $this->faceMatchThreshold = $faceMatchThreshold;
        $this->maxFaces = $maxFaces;
        $this->qualityFilter = $qualityFilter;
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
     * Analysiert ein Bild, sucht nach Übereinstimmungen und indiziert unbekannte Gesichter.
     */
    public function processAllFacesInImage(string $imagePath): array
    {
        $imageContent = file_get_contents($imagePath);

        // 1. Gesichter erkennen (Attribute + BoundingBoxes)
        $detectResponse = $this->client->detectFaces([
            'Image' => ['Bytes' => $imageContent],
            'Attributes' => ['ALL'],
        ]);

        if (empty($detectResponse['FaceDetails'])) {
            return [];
        }

        // 2. Gesichter suchen (Matches)
        $searchResponse = $this->client->searchFacesByImage([
            'CollectionId' => $this->collectionId,
            'Image' => ['Bytes' => $imageContent],
            'FaceMatchThreshold' => $this->faceMatchThreshold,
            'MaxFaces' => $this->maxFaces,
        ]);

        $availableMatches = $searchResponse['FaceMatches'] ?? [];
        $faceIds = array_map(fn($m) => $m['Face']['FaceId'], $availableMatches);
        $knownFaces = $this->videoFaceRepository->findBy(['faceLabel' => $faceIds]);
        $knownFacesByFaceId = [];
        foreach ($knownFaces as $face) {
            $knownFacesByFaceId[$face->getFaceLabel()] = $face;
        }

        $results = [];
        $indexedInThisImage = false;
        foreach ($detectResponse['FaceDetails'] as $detail) {
            $matchedFaceId = null;
            $similarity = null;
            
            $detectedGender = $detail['Gender']['Value'] ?? 'Unknown';

            // Match anhand der BoundingBox finden
            $bestMatchResult = $this->findBestMatch($detail, $availableMatches, $detectedGender, $knownFacesByFaceId);
            if ($bestMatchResult) {
                $bestMatch = $bestMatchResult['match'];
                $matchedFaceId = $bestMatch['Face']['FaceId'];
                $similarity = $bestMatch['Similarity'];
                // Entferne diesen Match aus den verfügbaren Matches, damit er nicht erneut gematcht wird
                unset($availableMatches[$bestMatchResult['key']]);
            }

            // Wenn kein Match, Gesicht indizieren
            $faceId = null;
            if (!$matchedFaceId) {
                // Nur indizieren, wenn die Confidence sehr hoch ist
                if ($detail['Confidence'] > 99.0 && !$indexedInThisImage) {
                    $indexResponse = $this->client->indexFaces([
                        'CollectionId' => $this->collectionId,
                        'Image' => ['Bytes' => $imageContent],
                        'MaxFaces' => $this->maxFaces,
                    ]);
                    $indexedInThisImage = true;
                    // Da wir das ganze Bild indiziert haben, könnten wir die neuen Gesichter jetzt eigentlich mappen, 
                    // aber das ist komplex. Für den Moment ist das Indizieren ausreichend, damit sie in der Collection sind.
                    // Beim nächsten Frame sollte sie dann gefunden werden.
                }
            } else {
                $faceId = $matchedFaceId;
            }

            $results[] = [
                'faceId' => $faceId,
                'matchedFaceId' => $matchedFaceId,
                'similarity' => $similarity,
                'age' => $this->calculateAverageAge($detail['AgeRange']),
                'gender' => $detail['Gender']['Value'] ?? 'Unknown',
                'emotion' => $detail['Emotions'][0]['Type'] ?? 'CALM',
                'confidence' => $detail['Confidence'],
                'boundingBox' => $detail['BoundingBox']
            ];
        }

        return $results;
    }

    private function calculateAverageAge(array $ageRange): int
    {
        return (int) (($ageRange['Low'] + $ageRange['High']) / 2);
    }

    /**
     * Findet das beste FaceMatch-Objekt für ein gegebenes FaceDetail basierend auf der BoundingBox.
     */
    private function findBestMatch(array $detail, array $matches, string $detectedGender, array $knownFacesByFaceId): ?array
    {
        $bestMatch = null;
        $bestMatchKey = null;
        $minDistance = 0.2; // 20% Toleranz

        foreach ($matches as $key => $match) {
            $faceId = $match['Face']['FaceId'];
            
            // Geschlechtscheck
            if (isset($knownFacesByFaceId[$faceId])) {
                $knownGender = $knownFacesByFaceId[$faceId]->getGender();
                if ($knownGender && $detectedGender !== 'Unknown' && $knownGender !== 'Unknown' && $knownGender !== $detectedGender) {
                    continue; // Geschlecht passt nicht
                }
            }

            $box1 = $detail['BoundingBox'];
            $box2 = $match['Face']['BoundingBox'];

            // Distanzberechnung zwischen den BoundingBoxes
            $distance = sqrt(
                pow($box1['Left'] - $box2['Left'], 2) +
                pow($box1['Top'] - $box2['Top'], 2) +
                pow($box1['Width'] - $box2['Width'], 2) +
                pow($box1['Height'] - $box2['Height'], 2)
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $bestMatch = $match;
                $bestMatchKey = $key;
            }
        }
        return $bestMatch ? ['match' => $bestMatch, 'key' => $bestMatchKey] : null;
    }

    public function getClient(): RekognitionClient
    {
        return $this->client;
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }

    public function resetCollection(): void
    {
        try {
            $this->client->deleteCollection(['CollectionId' => $this->collectionId]);
        } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
            // Ignore if it doesn't exist
        }

        $this->client->createCollection(['CollectionId' => $this->collectionId]);
    }

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