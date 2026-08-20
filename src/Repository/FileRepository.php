<?php

namespace App\Repository;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<File>
 */
class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    public function save(File $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(File $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByVideoAndPurpose(Video $video, FilePurpose $purpose): ?File
    {
        // Da die File-Relation nun direkt auf der Video-Entity liegt (sourceFile, convertedFile, thumbnailFile),
        // prüfen wir über den Join oder direkt, falls File eine Rückwärtsreferenz hat.
        // Mit Ansatz A (Video hat die File-Spalten): Wir suchen das File über die Video-Objekt-Eigenschaften.
        return $this->createQueryBuilder('f')
            ->innerJoin(Video::class, 'v', 'WITH', 'v.sourceFile = f OR v.convertedFile = f OR v.thumbnailFile = f')
            ->andWhere('v = :video')
            ->andWhere('f.purpose = :purpose')
            ->setParameter('video', $video)
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return File[]
     */
    public function findByVideoAndPurposes(Video $video, array $purposes): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin(Video::class, 'v', 'WITH', 'v.sourceFile = f OR v.convertedFile = f OR v.thumbnailFile = f')
            ->andWhere('v = :video')
            ->andWhere('f.purpose IN (:purposes)')
            ->setParameter('video', $video)
            ->setParameter('purposes', $purposes)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return File[]
     */
    public function findByScene(VideoScene $scene): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin(VideoScene::class, 's', 'WITH', 's.thumbnailFile = f')
            ->andWhere('s = :scene')
            ->setParameter('scene', $scene)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return File[]
     */
    public function findByPerson(Person $person): array
    {
        // Personen verweisen über VideoFace auf das File (faceImage) oder über ProfileFace
        return $this->createQueryBuilder('f')
            ->innerJoin('p.videoFaces', 'vf')
            ->innerJoin('vf.faceImage', 'f')
            ->andWhere('vf.person = :person')
            ->setParameter('person', $person)
            ->getQuery()
            ->getResult();
    }

    public function deleteByRelativePath(string $path): int
    {
        return $this->createQueryBuilder('f')
            ->delete()
            ->andWhere('f.relativePath = :path')
            ->setParameter('path', $path)
            ->getQuery()
            ->execute();
    }
}