<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class QuickResolveComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public array $currentPersonIds = [];

    #[LiveProp(writable: true)]
    public array $selectedPersonIds = [];

    #[LiveProp(writable: true, updateFromParent: true)]
    public array $statuses = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager
    ) {
        $this->statuses = [PersonStatus::WASTED->value];
    }

    public function updatedStatuses(): void
    {
        $this->loadBatch();
    }

    public function mount(array $statuses = []): void
    {
        if (!empty($statuses)) {
            $this->statuses = $statuses;
        }
        $this->loadBatch();
    }

    public function getPersons(): array
    {
        if (empty($this->currentPersonIds)) {
            return [];
        }

        // Wir laden die Personen in der Reihenfolge der IDs
        $qb = $this->personRepository->createQueryBuilder('p')
            ->select('p, f, d') // Eager loading faces and detections
            ->leftJoin('p.videoFaces', 'f')
            ->leftJoin('f.detection', 'd')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $this->currentPersonIds);
            
        $persons = $qb->getQuery()->getResult();
        
        // Sortieren, damit die Reihenfolge stabil bleibt
        $idMap = array_flip(array_values($this->currentPersonIds));
        usort($persons, function($a, $b) use ($idMap) {
            $idA = $a->getId();
            $idB = $b->getId();
            return ($idMap[$idA] ?? 0) <=> ($idMap[$idB] ?? 0);
        });

        return $persons;
    }

    #[LiveAction]
    public function markAllUnknown(): void
    {
        if (!empty($this->currentPersonIds)) {
            $persons = $this->personRepository->findBy(['id' => $this->currentPersonIds]);
            foreach ($persons as $person) {
                $person->setStatus(PersonStatus::UNKNOWN);
            }
            $this->entityManager->flush();
        }

        $this->loadBatch();
    }

    #[LiveAction]
    public function nextBatch(): void
    {
        // 1. showCount für aktuelle Personen erhöhen
        if (!empty($this->currentPersonIds)) {
            $persons = $this->personRepository->findBy(['id' => $this->currentPersonIds]);
            foreach ($persons as $person) {
                $newCount = $person->getShowCount() + 1;
                $person->setShowCount($newCount);
                
                // 2. Wenn 10 mal angezeigt -> UNKNOWN
                if ($newCount >= 10) {
                    $person->setStatus(PersonStatus::UNKNOWN);
                }
            }
            $this->entityManager->flush();
        }

        // 3. Nächsten Batch laden
        $this->loadBatch();
    }

    #[LiveAction]
    public function assignToDetectedPerson(#[LiveArg] int $personId, #[LiveArg] int $detectedPersonId): void
    {
        $person = $this->personRepository->find($personId);
        $detectedPerson = $this->personRepository->find($detectedPersonId);

        if ($person && $detectedPerson) {
            // Alle VideoFaces von $person zu $detectedPerson verschieben
            foreach ($person->getVideoFaces() as $face) {
                $face->setPerson($detectedPerson);
            }
            // Die alte (unidentifizierte) Person als gemergt markieren
            $person->setStatus(PersonStatus::MERGED);
            $person->setMergedInto($detectedPerson);
            $this->entityManager->persist($person);
            $this->entityManager->flush();
        }

        $this->loadBatch();
    }

    private function loadBatch(): void
    {
        if (empty($this->statuses)) {
            $this->currentPersonIds = [];
            return;
        }

        // Wir laden IDs von Personen, die den Status haben
        // Wir sortieren nach showCount (weniger oft angezeigte zuerst)
        // und dann nach faceCount (relevantere zuerst)
        $qb = $this->personRepository->createQueryBuilder('p')
            ->select('p.id as id, p.showCount, COUNT(f.id) as HIDDEN faceCount')
            ->leftJoin('p.videoFaces', 'f')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.showCount < 10')
            ->setParameter('statuses', array_values($this->statuses))
            ->groupBy('p.id')
            ->orderBy('p.showCount', 'ASC')
            ->addOrderBy('faceCount', 'DESC')
            ->setMaxResults(10);
        
        $results = $qb->getQuery()->getArrayResult();
        $this->currentPersonIds = array_map(fn($r) => (int)$r['id'], $results);
    }
    
    public function getRemainingCount(): int
    {
        if (empty($this->statuses)) {
            return 0;
        }

        $count = $this->personRepository->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', array_values($this->statuses))
            ->getQuery()
            ->getSingleScalarResult();
            
        return (int) $count;
    }
}
