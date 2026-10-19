<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Person;
use App\Entity\User;
use App\Entity\VideoFace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    //    /**
    //     * @return Person[] Returns an array of Person objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    /**
     * @return list<Person>
     */
    public function findActiveKnown(?User $visibleFor = null, bool $isAdmin = true): array
    {
        $qb = $this
            ->createQueryBuilder('p')
            ->where('p.wasted = :wasted')
            ->andWhere('p.name NOT LIKE :unknownPrefix')
            ->setParameter('wasted', false)
            ->setParameter('unknownPrefix', 'unknown_%')
            ->orderBy('p.name', \SortDirection::Ascending);

        $condition = MediumRepository::visibleCondition('known_m', $visibleFor, $isAdmin);
        if ($condition !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s known_f JOIN known_f.medium known_m WHERE known_f.person = p AND %s)',
                VideoFace::class,
                $condition,
            ));
            if ($visibleFor !== null) {
                $qb->setParameter('visibleFor', $visibleFor);
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Person[]
     */
    public function findIdentifiedWithUnverifiedFaces(): array
    {
        return $this
            ->createQueryBuilder('p')
            ->innerJoin('p.videoFaces', 'vf')
            ->andWhere('p.status = :status')
            ->andWhere('vf.isVerified = :isVerified')
            ->setParameter('status', \App\Enum\PersonStatus::IDENTIFIED)
            ->setParameter('isVerified', false)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}
