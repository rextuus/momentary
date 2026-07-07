<?php

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\LiveArg;
use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ComponentToolsTrait;

#[AsLiveComponent]
class TinderResolveComponent
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public ?int $currentPersonId = null;

    #[LiveProp(writable: true)]
    public array $statuses = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager
    ) {
        $this->statuses = [PersonStatus::NEW->value, PersonStatus::WASTED->value];
    }

    public function mount(?array $statuses = []): void
    {
        if (!empty($statuses)) {
            $this->statuses = $statuses;
        }
        $this->loadNext();
    }

    public function getPerson(): ?Person
    {
        if (!$this->currentPersonId) {
            return null;
        }

        return $this->personRepository->find($this->currentPersonId);
    }

    #[LiveAction]
    public function markAsUnknown(): void
    {
        if ($this->currentPersonId) {
            $person = $this->personRepository->find($this->currentPersonId);
            if ($person) {
                $person->setStatus(PersonStatus::UNKNOWN);
                $this->entityManager->flush();
            }
        }

        $this->loadNext();
    }

    #[LiveAction]
    public function skip(): void
    {
        if ($this->currentPersonId) {
            $person = $this->personRepository->find($this->currentPersonId);
            if ($person) {
                $person->setShowCount($person->getShowCount() + 1);
                if ($person->getShowCount() >= 10) {
                    $person->setStatus(PersonStatus::UNKNOWN);
                }
                $this->entityManager->flush();
            }
        }

        $this->loadNext();
    }

    #[LiveAction]
    public function assignToDetectedPerson(#[LiveArg] int $personId, #[LiveArg] int $detectedPersonId): void
    {
        $person = $this->personRepository->find($personId);
        $detectedPerson = $this->personRepository->find($detectedPersonId);

        if ($person && $detectedPerson) {
            foreach ($person->getVideoFaces() as $face) {
                $face->setPerson($detectedPerson);
            }
            $person->setStatus(PersonStatus::MERGED);
            $person->setMergedInto($detectedPerson);
            $this->entityManager->persist($person);
            $this->entityManager->flush();
        }

        $this->loadNext();
    }

    private function loadNext(): void
    {
        if (empty($this->statuses)) {
            $this->currentPersonId = null;
            return;
        }

        $qb = $this->personRepository->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.showCount < 10')
            ->setParameter('statuses', array_values($this->statuses))
            ->orderBy('p.showCount', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();
        $this->currentPersonId = $result ? (int)$result['id'] : null;
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
