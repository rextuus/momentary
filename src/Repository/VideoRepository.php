<?php

namespace App\Repository;

use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    //    /**
    //     * @return Video[] Returns an array of Video objects
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

    //    public function findOneBySomeField($value): ?Video
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function getAverageDurationRatio(\App\Enum\VideoStatus $status): float
    {
        $durationField = match($status) {
            \App\Enum\VideoStatus::CONVERTING => 'conversionDuration',
            \App\Enum\VideoStatus::SCENE_DETECTION => 'sceneDetectionDuration',
            \App\Enum\VideoStatus::SPLITTING => 'frameExtractionDuration',
            \App\Enum\VideoStatus::ANALYZING_FACES => 'faceAnalysisDuration',
            default => null,
        };

        if (!$durationField) return 0.0;

        $qb = $this->createQueryBuilder('v')
            ->select("AVG(v.$durationField / v.duration)")
            ->where('v.status = :status')
            ->andWhere("v.$durationField IS NOT NULL")
            ->andWhere('v.duration > 0')
            ->setParameter('status', \App\Enum\VideoStatus::COMPLETED);

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    public function findFullVideo(int $id): ?Video
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.scenes', 's')
            ->addSelect('s')
            ->leftJoin('v.videoFaces', 'vf')
            ->addSelect('vf')
            ->leftJoin('vf.person', 'p')
            ->addSelect('p')
            ->where('v.id = :id')
            ->setParameter('id', $id)
            ->orderBy('s.startSeconds', 'ASC')
            ->addOrderBy('vf.timestamp', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
