<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

#[AsLiveComponent]
final class PersonListingComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public array $statuses = ['identified'];

    #[LiveProp]
    public string $search = '';

    public function mount(array $statuses = null, string $search = null): void
    {
        if ($statuses !== null) {
            $this->statuses = $statuses;
        }
        if ($search !== null) {
            $this->search = $search;
        }
    }

    public function __construct(
        private readonly PersonRepository $personRepository
    ) {
    }

    public function getPersons(): array
    {
        $qb = $this->personRepository->createQueryBuilder('p');

        // Wenn gar keine Status ausgewählt sind, geben wir eine leere Liste zurück
        // (außer man möchte das Verhalten ändern)
        if (empty($this->statuses)) {
            return [];
        }

        $enums = [];
        foreach ($this->statuses as $status) {
            if ($status instanceof PersonStatus) {
                $enums[] = $status;
            } else {
                $enum = PersonStatus::tryFrom((string)$status);
                if ($enum) {
                    $enums[] = $enum;
                }
            }
        }

        if (!empty($enums)) {
            $qb->andWhere('p.status IN (:statuses)')
               ->setParameter('statuses', $enums);
        } else {
            // Falls Strings im Array waren, die keine gültigen Enums sind
            return [];
        }

        if (!empty($this->search)) {
            $qb->andWhere('(p.name LIKE :search OR p.fullName LIKE :search OR p.description LIKE :search)')
               ->setParameter('search', '%' . $this->search . '%');
        }

        $qb->leftJoin('p.videoFaces', 'vf')
           ->groupBy('p.id')
           ->orderBy('COUNT(vf.id)', 'DESC')
           ->addOrderBy('p.name', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
