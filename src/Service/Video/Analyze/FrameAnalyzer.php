<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\File;
use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\PersonStatus;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Aws\DetectedFace;
use App\Service\Face\FaceMatcher;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\ImageFileService;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class FrameAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videoRepository,
        private readonly AmazonRekognitionService $rekognitionService,
        private readonly ImageFileService $imageFileService,
        private readonly StoragePathProvider $pathProvider,
        private readonly FaceMatcher $faceMatcher,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
    ) {}

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp): bool
    {
        if (!file_exists($framePath)) {
            throw new \RuntimeException("Frame file does not exist: {$framePath}");
        }

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return false;
        }

        // Frames aus einem älteren Durchlauf (anderer Ordner) ignorieren; erlaubt sind Analyse- und Refinement-Frames
        $frameDirPath = $video->getCurrentFrameDirectory();
        if ($frameDirPath && !str_starts_with($framePath, \dirname($frameDirPath) . '/')) {
            return false;
        }

        $currentScene = $this->entityManager
            ->getRepository(VideoScene::class)
            ->createQueryBuilder('s')
            ->where('s.video = :video')
            ->andWhere(':ts >= s.startSeconds')
            ->andWhere(':ts < s.endSeconds')
            ->setParameter('video', $video)
            ->setParameter('ts', (float) $timestamp)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->analyzeImage($video, $framePath, $timestamp, $currentScene);
    }

    /**
     * Gesichter in einem Bild erkennen und dem Medium zuordnen (Video-Frame oder Foto).
     *
     * @param File|null $image vorhandene Bilddatei wiederverwenden (Fotos); sonst wird das Bild als Gesichtsbild abgelegt
     */
    public function analyzeImage(
        Medium $medium,
        string $imagePath,
        int $timestamp = 0,
        ?VideoScene $scene = null,
        ?File $image = null,
    ): bool {
        $allFacesData = $this->rekognitionService->processAllFacesInImage($imagePath);
        if ($allFacesData === []) {
            return false;
        }

        $file = $image ?? $this->storeFaceImage($medium, $imagePath);

        // Personen, die in diesem Bild schon vergeben sind: zwei Gesichter im selben Bild sind nie dieselbe Person
        $personIdsInFrame = [];
        foreach ($allFacesData as $detectedFace) {
            $person = $this->saveFace($medium, $detectedFace, $timestamp, $file, $scene, $personIdsInFrame);
            if ($person->getId() !== null) {
                $personIdsInFrame[] = $person->getId();
            }
        }

        $this->entityManager->flush();

        return true;
    }

    private function storeFaceImage(Medium $medium, string $framePath): File
    {
        // Während des Rekognition-Requests kann die Datei verschwinden; ohne clearstatcache lieferte PHP das alte Ergebnis
        clearstatcache(true, $framePath);
        if (!file_exists($framePath)) {
            throw new \RuntimeException("Frame file disappeared before processing: {$framePath}");
        }

        $imageContent = file_get_contents($framePath);
        if ($imageContent === false) {
            throw new \RuntimeException("Frame file could not be read: {$framePath}");
        }
        $uuid = Uuid::uuid4()->toString();

        $storagePath = $this->pathProvider->createFacePath((string) $medium->getId(), $uuid . '.jpg');

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

        return $file;
    }

    /**
     * @param list<int> $personIdsInFrame
     */
    private function saveFace(
        Medium $medium,
        DetectedFace $detectedFace,
        int $timestamp,
        File $file,
        ?VideoScene $currentScene,
        array $personIdsInFrame,
    ): Person {
        $settings = $this->settingsProvider->get();
        $ranked = $this->faceMatcher->rankPersons($detectedFace, $personIdsInFrame);
        $best = $ranked[0] ?? null;

        $person = $best !== null && $best->similarity >= $settings->getMatchThreshold() ? $best->person : null;
        if ($person === null) {
            $person = new Person();
            $person->setName('unknown_' . substr($detectedFace->faceId, 0, 8));
            $person->setIdentified(false);
            $this->entityManager->persist($person);
            // ID sofort vergeben, damit weitere Gesichter dieses Frames die Person ausschließen können
            $this->entityManager->flush();
        }

        $videoFace = new VideoFace();
        $videoFace->setMedium($medium);
        $videoFace->setPerson($person);
        $videoFace->setTimestamp($timestamp);
        $videoFace->setFaceImage($file);
        $videoFace->setFaceLabel($detectedFace->faceId);
        $videoFace->setMatchCandidates($detectedFace->candidates);
        $videoFace->setAge($detectedFace->age);
        $videoFace->setGender($detectedFace->gender);
        $videoFace->setEmotion($detectedFace->emotion);
        $videoFace->setBoundingBox($detectedFace->boundingBox);

        if ($currentScene) {
            $videoFace->setVideoScene($currentScene);
            $currentScene->addVideoFace($videoFace);
        }

        if ($best !== null) {
            $videoFace->setMatchedBy($best->face);
            $videoFace->setMatchSimilarity($best->similarity);
        }

        // Vorschlag: beste bereits identifizierte Person, auch wenn sie nicht automatisch zugeordnet wurde
        foreach ($ranked as $match) {
            if ($match->similarity < $settings->getSuggestionThreshold()) {
                break;
            }
            if ($match->person->getStatus() === PersonStatus::IDENTIFIED) {
                $videoFace->setDetection($match->person);
                break;
            }
        }

        $this->entityManager->persist($videoFace);

        return $person;
    }
}
