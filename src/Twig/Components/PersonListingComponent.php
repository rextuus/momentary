<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PersonListingComponent
{
    use DefaultActionTrait;

    /**
     * Labels pro Enum-Case-Name (GROSSGESCHRIEBEN). Unbekannte Cases
     * bekommen automatisch ein Fallback-Label.
     */
    private const LABELS = [
        'IDENTIFIED' => 'Identifiziert',
        'NEW' => 'Neu',
        'WASTED' => 'Ausgeblendet',
        'UNKNOWN' => 'Unbekannt',
        'MERGED' => 'Gemergt',
    ];

    #[LiveProp(writable: true)]
    public array $statuses = [];

    #[LiveProp(writable: true)]
    public string $search = '';

    public function __construct(
        private readonly PersonRepository $personRepository,
    ) {}

    public function mount(?array $statuses = null, ?string $search = null): void
    {
        if ($statuses !== null) {
            $this->statuses = array_values($statuses);
        }
        if ($search !== null) {
            $this->search = $search;
        }
    }

    /**
     * Alle verfügbaren Status direkt aus dem Enum, fürs Template.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getStatusOptions(): array
    {
        return array_map(static fn(PersonStatus $status): array => [
            'value' => $status->value,
            'label' => self::LABELS[strtoupper($status->name)] ?? ucfirst(strtolower($status->name)),
        ], PersonStatus::cases());
    }

    public function getPersons(): array
    {
        $values = [];
        foreach ($this->statuses as $status) {
            if ($status instanceof PersonStatus) {
                $values[] = $status->value;
                continue;
            }

            $enum = PersonStatus::tryFrom((string) $status);
            if ($enum !== null) {
                $values[] = $enum->value;
            }
        }

        // Keine (gültigen) Status ausgewählt -> leere Liste
        if (empty($values)) {
            return [];
        }

        $qb = $this->personRepository
            ->createQueryBuilder('p')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('statuses', $values);

        if ($this->search !== '') {
            $qb->andWhere(
                '(p.name LIKE :search OR p.fullName LIKE :search OR p.description LIKE :search)',
            )->setParameter('search', '%' . $this->search . '%');
        }

        $qb
            ->leftJoin('p.videoFaces', 'vf')
            ->addSelect('COUNT(vf.id) AS HIDDEN faceCount')
            ->groupBy('p.id')
            ->orderBy('faceCount', \SortDirection::Descending)
            ->addOrderBy('p.name', \SortDirection::Ascending);

        return $qb->getQuery()->getResult();
    }
}
