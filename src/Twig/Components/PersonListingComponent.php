<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PersonListingComponent
{
    use DefaultActionTrait;

    private const int PAGE_SIZE = 48;

    public const array SORTS = [
        'faces' => 'Meiste Sichtungen',
        'videos' => 'In den meisten Videos',
        'name' => 'Name',
        'newest' => 'Zuletzt erkannt',
    ];

    /**
     * Ausgewählte PersonStatus-Werte. Bewusst Strings: Live Components leiten aus diesem Typ ab,
     * wie die Werte zwischen Browser und Server übertragen werden.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true)]
    public array $statuses = [];

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'faces';

    #[LiveProp]
    public int $limit = self::PAGE_SIZE;

    /** @var list<PersonListItem>|null */
    private ?array $items = null;

    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->statuses = array_map(
            static fn(PersonStatus $status): string => $status->value,
            PersonStatus::listedByDefault(),
        );
    }

    #[LiveAction]
    public function toggleStatus(#[LiveArg] string $status): void
    {
        if (PersonStatus::tryFrom($status) === null) {
            return;
        }

        $this->statuses = \in_array($status, $this->statuses, true)
            ? array_values(array_diff($this->statuses, [$status]))
            : [...$this->statuses, $status];
        $this->limit = self::PAGE_SIZE;
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * Alle Status mit Anzahl passender Personen (berücksichtigt die Suche).
     *
     * @return list<array{status: PersonStatus, count: int, active: bool}>
     */
    public function getStatusOptions(): array
    {
        $qb = $this->personRepository->createQueryBuilder('p')->select('p.status AS status', 'COUNT(p.id) AS total');
        $this->applySearch($qb);
        $counts = [];
        foreach ($qb->groupBy('p.status')->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof PersonStatus
                ? $row['status']
                : PersonStatus::from((string) $row['status']);
            $counts[$status->value] = (int) $row['total'];
        }

        $options = [];
        foreach (PersonStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            $active = \in_array($status->value, $this->statuses, true);
            // Leere, nicht gewählte Status (z. B. Archiviert) nur anbieten, wenn sie etwas enthalten
            if ($count > 0 || $active) {
                $options[] = ['status' => $status, 'count' => $count, 'active' => $active];
            }
        }

        return $options;
    }

    public function getTotal(): int
    {
        $values = $this->selectedStatusValues();
        if ($values === []) {
            return 0;
        }

        $qb = $this->personRepository
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', $values);
        $this->applySearch($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, string>
     */
    public function getSortOptions(): array
    {
        return self::SORTS;
    }

    /**
     * @return list<PersonListItem>
     */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $values = $this->selectedStatusValues();
        if ($values === []) {
            return $this->items = [];
        }

        $qb = $this->personRepository
            ->createQueryBuilder('p')
            ->select(
                'p',
                'pf',
                'pfImage',
                'COUNT(vf.id) AS faces',
                'COUNT(DISTINCT IDENTITY(vf.medium)) AS videos',
                'MAX(vf.id) AS HIDDEN newest',
            )
            ->leftJoin('p.videoFaces', 'vf')
            ->leftJoin('p.profileFace', 'pf')
            ->leftJoin('pf.faceImage', 'pfImage')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', $values)
            ->groupBy('p.id, pf.id, pfImage.id')
            ->setMaxResults($this->limit);
        $this->applySearch($qb);

        match ($this->sort) {
            'name' => $qb->orderBy('p.name', \SortDirection::Ascending),
            'videos' => $qb->orderBy('videos', \SortDirection::Descending)->addOrderBy(
                'faces',
                \SortDirection::Descending,
            ),
            'newest' => $qb->orderBy('newest', \SortDirection::Descending),
            default => $qb->orderBy('faces', \SortDirection::Descending)->addOrderBy(
                'p.name',
                \SortDirection::Ascending,
            ),
        };

        /** @var list<array{0: Person, faces: int|string, videos: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();
        $previews = $this->previewFaces(array_map(static fn(array $row): Person => $row[0], $rows));

        return $this->items = array_map(
            static fn(array $row): PersonListItem => new PersonListItem(
                $row[0],
                (int) $row['faces'],
                (int) $row['videos'],
                $row[0]->getProfileFace() ?? $previews[(int) $row[0]->getId()] ?? null,
            ),
            $rows,
        );
    }

    /**
     * Pro Person das größte Gesicht als Vorschau (meist das schärfste), ohne alle Gesichter als Entities zu laden.
     *
     * @param list<Person> $persons
     *
     * @return array<int, VideoFace>
     */
    private function previewFaces(array $persons): array
    {
        $ids = array_values(array_filter(array_map(static fn(Person $person): ?int => $person->getProfileFace() === null
            ? $person->getId()
            : null, $persons)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->entityManager
            ->getConnection()
            ->executeQuery(
                'SELECT id, person_id, bounding_box FROM video_face WHERE person_id IN (?) AND face_image_id IS NOT NULL',
                [$ids],
                [ArrayParameterType::INTEGER],
            )
            ->fetchAllAssociative();

        $best = [];
        foreach ($rows as $row) {
            $box = json_decode((string) $row['bounding_box'], true);
            $area = \is_array($box) ? (float) ($box['Width'] ?? 0) * (float) ($box['Height'] ?? 0) : 0.0;
            $personId = (int) $row['person_id'];
            if (!isset($best[$personId]) || $area > $best[$personId]['area']) {
                $best[$personId] = ['id' => (int) $row['id'], 'area' => $area];
            }
        }
        if ($best === []) {
            return [];
        }

        /** @var list<VideoFace> $faces */
        $faces = $this->entityManager
            ->createQueryBuilder()
            ->select('f', 'img')
            ->from(VideoFace::class, 'f')
            ->leftJoin('f.faceImage', 'img')
            ->where('f.id IN (:ids)')
            ->setParameter('ids', array_column($best, 'id'))
            ->getQuery()
            ->getResult();

        $byPerson = [];
        foreach ($faces as $face) {
            $byPerson[(int) $face->getPerson()?->getId()] = $face;
        }

        return $byPerson;
    }

    /**
     * @return list<string>
     */
    private function selectedStatusValues(): array
    {
        return array_values(array_filter(
            $this->statuses,
            static fn(string $status): bool => PersonStatus::tryFrom($status) !== null,
        ));
    }

    private function applySearch(QueryBuilder $qb): void
    {
        $search = trim($this->search);
        if ($search === '') {
            return;
        }

        $qb->andWhere(
            '(p.name LIKE :search OR p.fullName LIKE :search OR p.relation LIKE :search OR p.description LIKE :search)',
        )->setParameter('search', '%' . addcslashes($search, '%_') . '%');
    }
}
