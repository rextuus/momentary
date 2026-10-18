<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serienfotos: mehrere fast gleiche Aufnahmen kurz hintereinander (z. B. drei Mal „Entspannung in der Sonne“ in
 * 5 Sekunden). Zeitachse, Ereignisse und Fotoliste zeigen nur das beste Foto einer Serie, der Rest ist aufklappbar.
 *
 * Wann Fotos eine Serie bilden und welches das beste ist, regelt BurstGrouper; hier werden die Fotos geladen und die
 * Wünsche des Benutzers (bestes Foto, aus der Serie lösen) umgesetzt.
 */
class PhotoBursts
{
    /** Beim Neuordnen um ein Foto herum betrachteter Zeitraum */
    private const string WINDOW = '1 hour';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Serien rund um ein (neues oder geändertes) Foto neu bilden.
     */
    public function regroupAround(Photo $photo): void
    {
        $time = $photo->getTakenAt();
        if ($time !== null) {
            $this->regroupWindow($photo->getOwner(), $time);
        }
    }

    public function regroupWindow(?User $owner, \DateTimeImmutable $time): void
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Photo::class, 'p')
            ->where('p.takenAt BETWEEN :from AND :to')
            ->setParameter('from', $time->modify('-' . self::WINDOW))
            ->setParameter('to', $time->modify('+' . self::WINDOW))
            ->orderBy('p.takenAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending);
        $owner === null
            ? $qb->andWhere('p.owner IS NULL')
            : $qb->andWhere('p.owner = :owner')->setParameter('owner', $owner);

        BurstGrouper::apply($qb->getQuery()->getResult());
        $this->entityManager->flush();
    }

    /**
     * Alle Serien neu bilden (nach dem Nachberechnen der Fingerabdrücke).
     *
     * @return int Anzahl Serien
     */
    public function regroupAll(): int
    {
        $photos = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Photo::class, 'p')
            ->leftJoin('p.owner', 'o')
            ->where('p.takenAt IS NOT NULL')
            ->orderBy('o.id', \SortDirection::Ascending)
            ->addOrderBy('p.takenAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $count = BurstGrouper::apply($photos);
        $this->entityManager->flush();

        return $count;
    }

    /**
     * Dieses Foto stellvertretend für seine Serie zeigen.
     */
    public function pin(Photo $photo): void
    {
        foreach ($photo->getBurst() as $member) {
            $member->setBurstPinned($member === $photo);
        }
        $this->regroupAround($photo);
    }

    /**
     * Aus der Serie lösen bzw. wieder für Serien zulassen.
     */
    public function exclude(Photo $photo, bool $excluded = true): void
    {
        $photo->setBurstExcluded($excluded);
        $photo->setBurstPinned(false);
        $this->regroupAround($photo);
    }
}
