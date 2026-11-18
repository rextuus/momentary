<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoScene;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoScene>
 */
class VideoSceneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoScene::class);
    }

    /**
     * Die Szene, in die ein Zeitpunkt (Sekunden) fällt.
     */
    public function findAt(Video $video, int $timestamp): ?VideoScene
    {
        return $this
            ->createQueryBuilder('s')
            ->where('s.video = :video')
            ->andWhere(':ts >= s.startSeconds')
            ->andWhere(':ts < s.endSeconds')
            ->setParameter('video', $video)
            ->setParameter('ts', (float) $timestamp)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return VideoScene[] Returns an array of VideoScene objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('v.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }
    //    public function findOneBySomeField($value): ?VideoScene
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
