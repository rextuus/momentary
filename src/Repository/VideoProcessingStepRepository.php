<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoProcessingStep>
 */
class VideoProcessingStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoProcessingStep::class);
    }

    public function getAverageDurationForStep(\App\Enum\VideoStatus $step): ?int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('AVG(s.duration)')
            ->where('s.step = :step')
            ->andWhere('s.duration IS NOT NULL')
            ->setParameter('step', $step)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
