<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoProcessingStep;
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
}
