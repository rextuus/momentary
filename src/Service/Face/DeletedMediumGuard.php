<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\File;
use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Service\ImageFileService;
use App\Service\Video\Processing\Exception\VideoGoneException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ein Medium kann gelöscht werden, während seine Gesichter erkannt werden (die Erkennung dauert Sekunden).
 * Prüft das direkt in der Datenbank und räumt nach einem abgebrochenen Speichern auf.
 *
 * Videos melden VideoGoneException (die Messenger-Middleware verwirft die Message still), Fotos MediumGoneException.
 */
class DeletedMediumGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageFileService $imageFileService,
    ) {}

    public function exists(int $mediumId): bool
    {
        // Direkt in der Datenbank: die Identity Map kennt ein inzwischen gelöschtes Medium noch
        $table = $this->entityManager->getClassMetadata(Medium::class)->getTableName();

        return $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE id = ?', $table),
            [$mediumId],
        ) !== false;
    }

    public function assertExists(Medium $medium): void
    {
        if (!$this->exists((int) $medium->getId())) {
            throw $this->gone($medium);
        }
    }

    public function gone(Medium $medium, ?\Throwable $previous = null): \RuntimeException
    {
        $id = (int) $medium->getId();
        if ($medium instanceof Video) {
            return new VideoGoneException(VideoGoneException::forId($id)->getMessage(), 0, $previous);
        }

        return new MediumGoneException(MediumGoneException::forId($id)->getMessage(), 0, $previous);
    }

    /**
     * Nach einem abgebrochenen Speichern: Gesichtsbild und neu angelegte, leer gebliebene Personen entfernen.
     * Der EntityManager ist nach dem Fehler geschlossen, deshalb direkt über die Verbindung.
     *
     * @param File|null $faceImage nur ein eigens abgelegtes Gesichtsbild, nie die Bilddatei eines Fotos
     * @param list<int> $createdPersonIds
     */
    public function removeLeftovers(?File $faceImage, array $createdPersonIds): void
    {
        $connection = $this->entityManager->getConnection();
        if ($faceImage !== null) {
            $filesystem = $this->imageFileService->getFilesystem();
            if ($filesystem->fileExists($faceImage->getRelativePath())) {
                $filesystem->delete($faceImage->getRelativePath());
            }
            if ($faceImage->getId() !== null) {
                $connection->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE id = ?',
                        $this->entityManager->getClassMetadata(File::class)->getTableName(),
                    ),
                    [$faceImage->getId()],
                );
            }
        }

        $personTable = $this->entityManager->getClassMetadata(Person::class)->getTableName();
        $faceTable = $this->entityManager->getClassMetadata(VideoFace::class)->getTableName();
        foreach ($createdPersonIds as $personId) {
            $connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE id = ? AND NOT EXISTS (SELECT 1 FROM %s WHERE person_id = ?)',
                    $personTable,
                    $faceTable,
                ),
                [$personId, $personId],
            );
        }
    }
}
