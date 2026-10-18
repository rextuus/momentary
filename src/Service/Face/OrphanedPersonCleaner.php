<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Entfernt automatisch angelegte Personen, die kein einziges Gesicht mehr haben.
 *
 * Das passiert, wenn Gesichter wegfallen: beim Neuverarbeiten eines Videos, beim Löschen eines Videos oder wenn
 * das letzte Gesicht einer Person entfernt wird. Bearbeitete Personen (benannt, bestätigt, als fremd markiert …)
 * bleiben erhalten, nur der Status NEW wird aufgeräumt.
 */
class OrphanedPersonCleaner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Personen, denen Gesichter dieses Mediums zugeordnet sind (vor dem Löschen der Gesichter merken).
     *
     * @return list<int>
     */
    public function personIdsOfMedium(int $mediumId): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT person_id FROM video_face WHERE medium_id = ? AND person_id IS NOT NULL
             UNION SELECT detection_id FROM video_face WHERE medium_id = ? AND detection_id IS NOT NULL',
            [$mediumId, $mediumId],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * @param list<int>|null $personIds nur diese Personen prüfen, null = alle
     *
     * @return int Anzahl entfernter Personen
     */
    public function removeOrphans(?array $personIds = null): int
    {
        if ($personIds === []) {
            return 0;
        }

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->where('p.status = :status')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s f WHERE f.person = p OR f.detection = p)',
                VideoFace::class,
            ))
            ->setParameter('status', PersonStatus::NEW);
        if ($personIds !== null) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', array_values(array_unique($personIds)));
        }

        /** @var list<Person> $orphans */
        $orphans = $qb->getQuery()->getResult();
        if ($orphans === []) {
            return 0;
        }

        // Verweise zusammengeführter Personen lösen, sonst blockiert der Fremdschlüssel das Löschen
        $this->entityManager
            ->createQueryBuilder()
            ->update(Person::class, 'm')
            ->set('m.mergedInto', ':none')
            ->where('m.mergedInto IN (:orphans)')
            ->setParameter('none', null)
            ->setParameter('orphans', $orphans)
            ->getQuery()
            ->execute();

        foreach ($orphans as $person) {
            $this->entityManager->remove($person);
        }
        $this->entityManager->flush();

        return \count($orphans);
    }
}
