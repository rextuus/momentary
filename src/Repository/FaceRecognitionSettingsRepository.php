<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FaceRecognitionSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FaceRecognitionSettings>
 */
class FaceRecognitionSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaceRecognitionSettings::class);
    }
}
