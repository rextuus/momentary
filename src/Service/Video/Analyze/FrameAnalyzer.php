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
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\File;
use App\Enum\FilePurpose;
use Ramsey\Uuid\Uuid;

class FrameAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videoRepository,
        private readonly AmazonRekognitionService $rekognitionService,
        private readonly ImageFileService $imageFileService,
        private readonly VideoFileService $videoFileService,
        private readonly StoragePathProvider $pathProvider,
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

        $storagePath = $this->pathProvider->createFacePath((string)$videoId, $uuid . '.jpg');

        $this->imageFileService->getFilesystem()->write($storagePath, $imageContent);

        $file = $this->entityManager->getRepository(File::class)->findOneBy(['relativePath' => $storagePath]);
        if (!$file) {
            $file = new File();
            $file->setRelativePath($storagePath);
            $file->setMimeType('image/jpeg');
            $file->setFileSize(strlen($imageContent));
            $file->setPurpose(FilePurpose::IMAGE_FACE);
            $this->entityManager->persist($file);
        }

        foreach ($allFacesData as $faceData) {
            $this->saveFaceData($video, $faceData, $timestamp, $file, $currentScene);
        }

        return true;
    }

    private function saveFaceData($video, $faceData, $timestamp, $file, $currentScene): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($video, $faceData, $timestamp, $file, $currentScene) {
                $person = null;
                $matchedFace = null;

                if (!empty($faceData['matchedFaceId'])) {
                    $matchedFace = $this->entityManager->getRepository(VideoFace::class)
                        ->findOneBy(['faceLabel' => $faceData['matchedFaceId']]);
                    $person = $matchedFace?->getPerson();
                }

                if ($person === null) {
                    $person = new Person();
                    $faceId = $faceData['faceId'] ?? 'unknown';
                    if ($faceId === null) {
                        $faceId = 'unknown';
                    }
                    $person->setName('unknown_' . substr((string) $faceId, 0, 8));
                    $person->setIdentified(false);
                    $this->entityManager->persist($person);
                }

                $videoFace = new VideoFace();
                $videoFace->setVideo($video);
                $videoFace->setPerson($person);
                $videoFace->setTimestamp($timestamp);
                $videoFace->setFaceImage($file);
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

                    if ($faceData['similarity'] >= 80.0 && $matchedFace->getPerson() && $matchedFace->getPerson()->isIdentified()) {
                        $videoFace->setDetection($matchedFace->getPerson());
                    }
                }

                $this->entityManager->persist($videoFace);
            }
        );
    }
}