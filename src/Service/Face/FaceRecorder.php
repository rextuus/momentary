<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\File;
use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Speichert die erkannten Gesichter eines Bildes: Gesichtsbild, Abgleich je Gesicht (FaceAssigner), neue Personen,
 * VideoFace mit Kandidaten und Embedding.
 *
 * Wird das Medium währenddessen gelöscht, bleibt nichts zurück (DeletedMediumGuard).
 */
class FaceRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FaceAssigner $assigner,
        private readonly FaceImageStore $imageStore,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        private readonly DeletedMediumGuard $deletedMediumGuard,
    ) {}

    /**
     * @param list<DetectedFace> $faces
     * @param File|null          $image vorhandene Bilddatei wiederverwenden (Fotos); sonst wird $imagePath abgelegt
     */
    public function record(
        Medium $medium,
        string $providerName,
        array $faces,
        string $imagePath,
        int $timestamp = 0,
        ?VideoScene $scene = null,
        ?File $image = null,
    ): void {
        $file = $image ?? $this->imageStore->store($medium, $imagePath);
        $thresholds = $this->settingsProvider->thresholds();

        // Personen, die in diesem Bild schon vergeben sind: zwei Gesichter im selben Bild sind nie dieselbe Person
        $personIdsInFrame = [];
        $createdPersonIds = [];
        try {
            foreach ($faces as $face) {
                $assignment = $this->assigner->assign($face, $personIdsInFrame, $thresholds);
                $person = $assignment->person ?? $this->newPerson($face);
                if ($assignment->person === null) {
                    $createdPersonIds[] = (int) $person->getId();
                }
                $personIdsInFrame[] = (int) $person->getId();

                $this->entityManager->persist($this->videoFace(
                    $medium,
                    $providerName,
                    $face,
                    $person,
                    $assignment,
                    $timestamp,
                    $file,
                    $scene,
                ));
            }

            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            // Medium zwischen Prüfung und Speichern gelöscht: Reste entfernen, sonst echter Fehler
            if ($this->deletedMediumGuard->exists((int) $medium->getId())) {
                throw $e;
            }
            $this->deletedMediumGuard->removeLeftovers($image === null ? $file : null, $createdPersonIds);

            throw $this->deletedMediumGuard->gone($medium, $e);
        }
    }

    private function newPerson(DetectedFace $face): Person
    {
        $person = new Person();
        $person->setName('unknown_' . substr($face->faceId, 0, 8));
        $person->setIdentified(false);
        $this->entityManager->persist($person);
        // ID sofort vergeben, damit weitere Gesichter dieses Bildes die Person ausschließen können
        $this->entityManager->flush();

        return $person;
    }

    private function videoFace(
        Medium $medium,
        string $providerName,
        DetectedFace $face,
        Person $person,
        FaceAssignment $assignment,
        int $timestamp,
        File $file,
        ?VideoScene $scene,
    ): VideoFace {
        $videoFace = new VideoFace();
        $videoFace->setMedium($medium);
        $videoFace->setPerson($person);
        // Gegenseite pflegen, sonst hält die Konsolidierung im selben EntityManager die Person für leer und löscht sie.
        // Bewusst ohne addVideoFace(): dessen contains() lüde jedes Mal alle Gesichter der Person.
        $person->getVideoFaces()->add($videoFace);
        $videoFace->setTimestamp($timestamp);
        $videoFace->setFaceImage($file);
        $videoFace->setProvider($providerName);
        $videoFace->setFaceLabel($face->faceId);
        $videoFace->setDetectionScore($face->confidence);
        $videoFace->setFaceSizePx($face->sizePx);
        $videoFace->setEmbedding($face->embedding, $face->embeddingModel);
        $videoFace->setMatchCandidates($face->candidates);
        $videoFace->setAge($face->age);
        $videoFace->setGender($face->gender);
        $videoFace->setEmotion($face->emotion);
        $videoFace->setBoundingBox($face->boundingBox);
        $videoFace->setDetection($assignment->suggestion);

        if ($scene !== null) {
            $videoFace->setVideoScene($scene);
            $scene->addVideoFace($videoFace);
        }
        if ($assignment->best !== null) {
            $videoFace->setMatchedBy($assignment->best->face);
            $videoFace->setMatchSimilarity($assignment->best->similarity);
        }

        return $videoFace;
    }
}
