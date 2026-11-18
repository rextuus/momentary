<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\VideoFace;
use App\Service\Face\FaceForgetter;
use App\Service\Storage\FileManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Löscht eine Person samt aller ihrer Sichtungen, z. B. für Fehlerkennungen wie Spiegelungen oder Plakate.
 *
 * Die Gesichter werden auch beim Anbieter entfernt (Rekognition: AWS-Collection), damit spätere Analysen nicht wieder
 * auf sie matchen. Fehler dabei blockieren das Löschen nicht.
 * Gesichtsbilder sind ganze Frames, die sich mehrere Gesichter teilen: eine Bilddatei wird nur gelöscht, wenn kein
 * anderes Gesicht mehr darauf verweist.
 */
class PersonDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FaceForgetter $faceForgetter,
        private readonly FileManager $fileManager,
    ) {}

    /**
     * @return int Anzahl gelöschter Sichtungen
     */
    public function delete(Person $person): int
    {
        $faces = array_values($person->getVideoFaces()->toArray());

        $this->faceForgetter->forget(
            FaceForgetter::labelsByProvider($faces),
            sprintf('Person %d', (int) $person->getId()),
        );

        // Gesichter anderer Personen, die diese Person nur als Referenz (detection) nennen, behalten
        foreach ($person->getDetectionFaces()->toArray() as $face) {
            if ($face->getPerson() !== $person) {
                $face->setDetection(null);
            }
        }
        $this->entityManager
            ->createQueryBuilder()
            ->update(Person::class, 'm')
            ->set('m.mergedInto', ':none')
            ->where('m.mergedInto = :person')
            ->setParameter('none', null)
            ->setParameter('person', $person)
            ->getQuery()
            ->execute();

        $person->setProfileFace(null);
        $images = [];
        foreach ($faces as $face) {
            $image = $face->getFaceImage();
            if ($image !== null) {
                $images[(int) $image->getId()] = $image;
            }
            $face->setFaceImage(null);
            $face->getMedium()?->removeFace($face);
            $face->getVideoScene()?->removeVideoFace($face);
            $person->removeVideoFace($face);
            $this->entityManager->remove($face);
        }
        $this->entityManager->remove($person);
        $this->entityManager->flush();

        foreach ($images as $image) {
            if (!$this->isStillUsed($image)) {
                $this->fileManager->deleteFile($image, false);
            }
        }
        $this->entityManager->flush();

        return \count($faces);
    }

    private function isStillUsed(File $image): bool
    {
        return (
            (int) $this->entityManager
                ->createQueryBuilder()
                ->select('COUNT(f.id)')
                ->from(VideoFace::class, 'f')
                ->where('f.faceImage = :image')
                ->setParameter('image', $image)
                ->getQuery()
                ->getSingleScalarResult() > 0
        );
    }
}
