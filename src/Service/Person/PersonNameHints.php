<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\Entity\Person;
use App\Entity\VideoFace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Namensvorschläge aus dem Gesprochenen: Namen, die in Szenen fallen, in denen die Person zu sehen ist
 * (Tags „Erwähnte Personen“ aus der Transkription).
 */
class PersonNameHints
{
    public const string CATEGORY = 'Erwähnte Personen';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<array{name: string, scenes: int}> häufigste zuerst
     */
    public function forPerson(Person $person, int $limit = 3): array
    {
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('t.name AS name', 'COUNT(DISTINCT s.id) AS scenes')
            ->from(VideoFace::class, 'f')
            ->join('f.videoScene', 's')
            ->join('s.sceneTags', 'st')
            ->join('st.tag', 't')
            ->join('t.category', 'c')
            ->where('f.person = :person')
            ->andWhere('c.name = :category')
            ->groupBy('t.name')
            ->orderBy('scenes', \SortDirection::Descending)
            ->addOrderBy('t.name', \SortDirection::Ascending)
            ->setParameter('person', $person)
            ->setParameter('category', self::CATEGORY)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn(array $row): array => [
            'name' => (string) $row['name'],
            'scenes' => (int) $row['scenes'],
        ], $rows));
    }
}
