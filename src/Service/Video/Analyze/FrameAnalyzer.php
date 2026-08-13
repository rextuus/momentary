<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\ImageFileService;
use App\Service\VideoFileService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class FrameAnalyzer
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videoRepository,
        private readonly AmazonRekognitionService $rekognitionService,
        private readonly ImageFileService $imageFileService,
        private readonly VideoFileService $videoFileService,
    ) {
    }

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp): bool
    {
        if (!file_exists($framePath)) {
            throw new \RuntimeException("Frame file does not exist: {$framePath}");
        }

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return false;
        }

        $frameDirPath = $video->getCurrentFrameDirectory();
        if ($frameDirPath && !str_starts_with($framePath, $frameDirPath)) {
            return false;
        }

        $currentScene = $this->entityManager->getRepository(VideoScene::class)
            ->createQueryBuilder('s')
            ->where('s.video = :video')
            ->andWhere(':ts >= s.startSeconds')
            ->andWhere(':ts < s.endSeconds')
            ->setParameter('video', $video)
            ->setParameter('ts', (float) $timestamp)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $allFacesData = $this->rekognitionService->processAllFacesInImage($framePath);
        if (empty($allFacesData)) {
            return false;
        }

        if (!file_exists($framePath)) {
            throw new \RuntimeException("Frame file disappeared before processing: {$framePath}");
        }

        $imageContent = file_get_contents($framePath);
        $uuid = Uuid::uuid4()->toString();
        $dir = $this->videoFileService->getVideoDirectory($video, 'faces');
        $storagePath = "{$dir}/{$uuid}.jpg";

        $this->imageFileService->getFilesystem()->write($storagePath, $imageContent);

        foreach ($allFacesData as $faceData) {
            $this->saveFaceData($video, $faceData, $timestamp, $storagePath, $currentScene);
        }

        return true;
    }

    private function saveFaceData($video, $faceData, $timestamp, $storagePath, $currentScene): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($video, $faceData, $timestamp, $storagePath, $currentScene) {
                $person = null;
                $matchedFace = null;

                if (!empty($faceData['matchedFaceId'])) {
                    $matchedFace = $this->entityManager->getRepository(VideoFace::class)
                        ->findOneBy(['faceLabel' => $faceData['matchedFaceId']]);
                    $person = $matchedFace?->getPerson();
                }

                // OPTIMIERUNG: Wenn wir eine hohe Ähnlichkeit haben, verknüpfen wir es direkt mit der Person
                // Auch wenn wir keine matchedFaceId haben, könnten wir über FaceLabels suchen,
                // aber Amazon gibt uns bei searchFaces bereits die beste Übereinstimmung.

                if ($person === null) {
                    // Falls wir die Person nicht über matchedFaceId finden, schauen wir, ob wir sie über den Namen finden (unknown_...)
                    // Das ist aber unzuverlässig. Besser: Neue Person anlegen.
                    $person = new Person();
                    $faceId = $faceData['faceId'] ?? 'unknown';
                    // Defensive: Ensure we have a string
                    if ($faceId === null) {
                        $faceId = 'unknown';
                    }
                    $person->setName('unknown_' . substr((string) $faceId, 0, 8));
                    $person->setIdentified(false);
                    $this->entityManager->persist($person);
                    // Flush ist hier wichtig, damit die Person eine ID bekommt, falls wir sie später im Loop brauchen
                    // Aber wir sind in einem Loop in analyzeFrame.
                }

                $videoFace = new VideoFace();
                $videoFace->setVideo($video);
                $videoFace->setPerson($person);
                $videoFace->setTimestamp($timestamp);
                $videoFace->setFaceImagePath($storagePath);
                $videoFace->setFaceLabel($faceData['faceId']);
                $videoFace->setAge((int) $faceData['age']);
                $videoFace->setGender((string) $faceData['gender']);
                $videoFace->setEmotion((string) $faceData['emotion']);
                $videoFace->setBoundingBox($faceData['boundingBox']);

                if ($currentScene) {
                    $videoFace->setVideoScene($currentScene);
                }
                if ($matchedFace) {
                    $videoFace->setMatchedBy($matchedFace);
                    $videoFace->setMatchSimilarity((float) $faceData['similarity']);

                    // NEU: Wenn die Ähnlichkeit hoch genug ist, markieren wir die Person als "wahrscheinlich"
                    if ($faceData['similarity'] >= 80.0 && $matchedFace->getPerson() && $matchedFace->getPerson(
                        )->isIdentified()) {
                        $videoFace->setDetection($matchedFace->getPerson());
                    }
                }

                $this->entityManager->persist($videoFace);
            }
        );
    }
}
