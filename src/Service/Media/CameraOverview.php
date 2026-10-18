<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Medium;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Übersicht für die Seite „Kamera-Uhren“: Kameras mit ihrem Zeitraum und unplausible Aufnahmezeiten.
 */
class CameraOverview
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Medien mit unplausibler Kamerazeit (siehe CameraClock::warnings()).
     *
     * @return list<Medium>
     */
    public function implausible(int $limit = 100): array
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(Medium::class, 'm')
            ->where('m.cameraTakenAt < :earliest OR m.cameraTakenAt > :future')
            ->andWhere('m.dateSource <> :manual')
            ->setParameter('earliest', new \DateTimeImmutable(CameraClock::EARLIEST_PLAUSIBLE))
            ->setParameter('future', new \DateTimeImmutable('+1 day'))
            ->setParameter('manual', Medium::DATE_MANUAL)
            ->orderBy('m.cameraTakenAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Kameras mit Zeitraum laut Kamera-Uhr: Eine Kamera, deren Aufnahmen nicht zu den anderen passen, fällt hier auf.
     *
     * @return list<array{camera: ?string, count: int, first: ?\DateTimeImmutable, last: ?\DateTimeImmutable, corrected: int}>
     */
    public function cameras(): array
    {
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select(
                'm.camera AS camera',
                'COUNT(m.id) AS media',
                'MIN(m.cameraTakenAt) AS first',
                'MAX(m.cameraTakenAt) AS last',
            )
            ->addSelect('SUM(CASE WHEN m.dateSource IN (:corrected) THEN 1 ELSE 0 END) AS corrected')
            ->from(Medium::class, 'm')
            ->groupBy('m.camera')
            ->orderBy('media', \SortDirection::Descending)
            ->setParameter('corrected', [Medium::DATE_MANUAL, Medium::DATE_CAMERA_OFFSET])
            ->getQuery()
            ->getArrayResult();

        $utc = new \DateTimeZone('UTC');
        $date = static fn(mixed $value): ?\DateTimeImmutable => $value === null
            ? null
            : new \DateTimeImmutable((string) $value, $utc);

        return array_values(array_map(static fn(array $row): array => [
            'camera' => $row['camera'] === null ? null : (string) $row['camera'],
            'count' => (int) $row['media'],
            'first' => $date($row['first']),
            'last' => $date($row['last']),
            'corrected' => (int) $row['corrected'],
        ], $rows));
    }
}
