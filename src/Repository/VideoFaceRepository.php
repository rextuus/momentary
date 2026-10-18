<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoFace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoFace>
 */
class VideoFaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoFace::class);
    }

    /**
     * @param list<string> $faceLabels Rekognition-FaceIds
     *
     * @return array<string, VideoFace> FaceId => Gesicht
     */
    public function findByFaceLabels(array $faceLabels): array
    {
        if ($faceLabels === []) {
            return [];
        }

        $faces = $this
            ->createQueryBuilder('vf')
            ->addSelect('p')
            ->join('vf.person', 'p')
            ->where('vf.faceLabel IN (:labels)')
            ->setParameter('labels', $faceLabels)
            ->getQuery()
            ->getResult();

        $byLabel = [];
        foreach ($faces as $face) {
            $label = $face->getFaceLabel();
            if ($label !== null) {
                $byLabel[$label] = $face;
            }
        }

        return $byLabel;
    }

    /**
     * Personen, die auch in anderen Videos vorkommen (also schon videoübergreifend erkannt wurden).
     *
     * @param list<int> $personIds
     *
     * @return list<int>
     */
    public function findPersonIdsSeenOutsideVideo(array $personIds, Video $video): array
    {
        if ($personIds === []) {
            return [];
        }

        $rows = $this
            ->createQueryBuilder('vf')
            ->select('DISTINCT IDENTITY(vf.person) AS personId')
            ->where('vf.person IN (:persons)')
            ->andWhere('vf.video <> :video')
            ->setParameter('persons', $personIds)
            ->setParameter('video', $video)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), $rows));
    }

    /**
     * @return list<VideoFace>
     */
    public function findByVideoForConsolidation(Video $video): array
    {
        return $this
            ->createQueryBuilder('vf')
            ->addSelect('p', 's', 'f')
            ->join('vf.person', 'p')
            ->leftJoin('vf.videoScene', 's')
            ->leftJoin('vf.faceImage', 'f')
            ->where('vf.video = :video')
            ->setParameter('video', $video)
            ->orderBy('vf.timestamp', \SortDirection::Ascending)
            ->addOrderBy('vf.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return VideoFace[] Returns an array of VideoFace objects
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
    //    public function findOneBySomeField($value): ?VideoFace
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
