<?php

namespace App\Repository;

use App\Entity\Person;
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
     * @return Person[]
     */
    public function findActiveKnown(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.wasted = :wasted')
            ->andWhere('p.name NOT LIKE :unknownPrefix')
            ->setParameter('wasted', false)
            ->setParameter('unknownPrefix', 'unknown_%')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Person[]
     */
    public function findIdentifiedWithUnverifiedFaces(): array
    {
        return $this->createQueryBuilder('p')
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
