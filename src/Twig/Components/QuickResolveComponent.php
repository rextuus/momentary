<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class QuickResolveComponent
{
    use DefaultActionTrait;

    private const BATCH_SIZE = 10;
    private const MAX_SHOWS = 10;

    #[LiveProp]
    public array $currentPersonIds = [];

    #[LiveProp(writable: true)]
    public array $selectedPersonIds = [];

    #[LiveProp(writable: true, onUpdated: 'onStatusesUpdated')]
    public array $statuses = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager
    ) {
        $this->statuses = [PersonStatus::NEW->value, PersonStatus::WASTED->value];
    }

    public function mount(array $statuses = []): void
    {
        if (!empty($statuses)) {
            $this->statuses = $statuses;
        }
        $this->loadBatch();
    }

    /**
     * Filter geändert -> neuen Batch laden (der alte Hook "updatedStatuses" existiert in Live Components nicht).
     */
    public function onStatusesUpdated(): void
    {
        $this->loadBatch();
    }

    public function getPersons(): array
    {
        if (empty($this->currentPersonIds)) {
            return [];
        }

        $persons = $this->personRepository->createQueryBuilder('p')
            ->select('p, f, d') // Gesichter + Detections vorladen
            ->leftJoin('p.videoFaces', 'f')
            ->leftJoin('f.detection', 'd')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $this->currentPersonIds)
            ->getQuery()
            ->getResult();

        // Reihenfolge des Batches beibehalten
        $order = array_flip(array_values($this->currentPersonIds));
        usort($persons, fn (Person $a, Person $b) => ($order[$a->getId()] ?? 0) <=> ($order[$b->getId()] ?? 0));

        return $persons;
    }

    #[LiveAction]
    public function selectAll(): void
    {
        $this->selectedPersonIds = array_map('strval', $this->currentPersonIds);
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedPersonIds = [];
    }

    #[LiveAction]
    public function markAllUnknown(): void
    {
        $this->setStatusFor($this->currentPersonIds, PersonStatus::UNKNOWN);
        $this->loadBatch();
    }

    /**
     * Nur die ausgewählten als unbekannt markieren; der Rest des Batches bleibt stehen
     * und wird mit neuen Personen aufgefüllt.
     */
    #[LiveAction]
    public function markSelectedUnknown(): void
    {
        $ids = array_values(array_intersect($this->selectedIds(), $this->currentPersonIds));
        if (empty($ids)) {
            return;
        }

        $this->setStatusFor($ids, PersonStatus::UNKNOWN);
        $this->removeFromBatch($ids);
    }

    #[LiveAction]
    public function nextBatch(): void
    {
        if (!empty($this->currentPersonIds)) {
            foreach ($this->personRepository->findBy(['id' => $this->currentPersonIds]) as $person) {
                $person->setShowCount($person->getShowCount() + 1);

                // Nach 10 Anzeigen ohne Zuordnung -> unbekannt
                if ($person->getShowCount() >= self::MAX_SHOWS) {
                    $person->setStatus(PersonStatus::UNKNOWN);
                }
            }
            $this->entityManager->flush();
        }

        $this->loadBatch();
    }

    #[LiveAction]
    public function assignToDetectedPerson(#[LiveArg] int $personId, #[LiveArg] int $detectedPersonId): void
    {
        $person = $this->personRepository->find($personId);
        $detectedPerson = $this->personRepository->find($detectedPersonId);

        if ($person && $detectedPerson && $person !== $detectedPerson) {
            foreach ($person->getVideoFaces() as $face) {
                $face->setPerson($detectedPerson);
            }
            $person->setStatus(PersonStatus::MERGED);
            $person->setMergedInto($detectedPerson);
            $person->setIdentified(true);
            $this->entityManager->flush();
        }

        $this->removeFromBatch([$personId]);
    }

    public function getRemainingCount(): int
    {
        $values = $this->statusValues();
        if (empty($values)) {
            return 0;
        }

        return (int) $this->createCandidateQueryBuilder($values)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ───────── intern ─────────

    private function loadBatch(): void
    {
        $this->selectedPersonIds = [];
        $this->currentPersonIds = $this->fetchCandidateIds(self::BATCH_SIZE);
    }

    /**
     * Personen aus dem Batch entfernen und mit neuen auffüllen.
     *
     * @param int[] $ids
     */
    private function removeFromBatch(array $ids): void
    {
        $ids = array_map('intval', $ids);

        $this->currentPersonIds = array_values(array_diff($this->currentPersonIds, $ids));
        $this->selectedPersonIds = array_map(
            'strval',
            array_values(array_diff($this->selectedIds(), $ids))
        );

        $missing = self::BATCH_SIZE - count($this->currentPersonIds);
        if ($missing > 0) {
            $this->currentPersonIds = array_merge(
                $this->currentPersonIds,
                $this->fetchCandidateIds($missing, $this->currentPersonIds)
            );
        }
    }

    /**
     * @param int[] $exclude
     * @return int[]
     */
    private function fetchCandidateIds(int $limit, array $exclude = []): array
    {
        $values = $this->statusValues();
        if (empty($values)) {
            return [];
        }

        $qb = $this->createCandidateQueryBuilder($values)
            ->select('p.id AS id')
            ->addSelect('p.showCount AS HIDDEN show_order')
            ->addSelect('COUNT(f.id) AS HIDDEN face_count')
            ->groupBy('p.id')
            ->orderBy('show_order', 'ASC')          // selten gezeigte zuerst
            ->addOrderBy('face_count', 'DESC')      // dann relevantere
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);

        if (!empty($exclude)) {
            $qb->andWhere('p.id NOT IN (:exclude)')->setParameter('exclude', array_values($exclude));
        }

        return array_map(
            static fn (array $row) => (int) $row['id'],
            $qb->getQuery()->getArrayResult()
        );
    }

    /**
     * Nur Personen MIT Gesichtern (inner join) und noch nicht zu oft gezeigt.
     *
     * @param string[] $statusValues
     */
    private function createCandidateQueryBuilder(array $statusValues): QueryBuilder
    {
        return $this->personRepository->createQueryBuilder('p')
            ->join('p.videoFaces', 'f')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.showCount < :maxShows')
            ->setParameter('statuses', $statusValues)
            ->setParameter('maxShows', self::MAX_SHOWS);
    }

    /**
     * @return string[]
     */
    private function statusValues(): array
    {
        $values = [];
        foreach ($this->statuses as $status) {
            $enum = PersonStatus::tryFrom((string) $status);
            if ($enum !== null) {
                $values[] = $enum->value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return int[]
     */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selectedPersonIds)));
    }

    /**
     * @param int[] $ids
     */
    private function setStatusFor(array $ids, PersonStatus $status): void
    {
        if (empty($ids)) {
            return;
        }

        foreach ($this->personRepository->findBy(['id' => $ids]) as $person) {
            $person->setStatus($status);
        }
        $this->entityManager->flush();
    }
}