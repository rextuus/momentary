<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\User;
use App\Repository\MediumRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fügt einem Ereignis alle (für den Benutzer sichtbaren) Videos und Fotos aus seinem Zeitraum hinzu.
 * Grundlage ist das Aufnahmedatum, ersatzweise das Importdatum.
 */
class EventMediaCollector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return int Anzahl neu hinzugefügter Medien
     */
    public function collect(Event $event, ?User $user, bool $isAdmin): int
    {
        if ($event->getStartDate() === null) {
            return 0;
        }

        // Tage gelten in deutscher Zeit, gespeichert wird in UTC
        $zone = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        $from = new \DateTimeImmutable($event->getStartDate()->format('Y-m-d') . ' 00:00:00', $zone)->setTimezone($utc);
        $until = new \DateTimeImmutable(
            ($event->getEndDate() ?? $event->getStartDate())->format('Y-m-d') . ' 23:59:59',
            $zone,
        )->setTimezone($utc);

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(Medium::class, 'm')
            ->where('COALESCE(m.takenAt, m.createdAt) BETWEEN :from AND :until')
            ->setParameter('from', $from)
            ->setParameter('until', $until);
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);

        $added = 0;
        /** @var Medium $medium */
        foreach ($qb->getQuery()->getResult() as $medium) {
            if (!$event->getMedia()->contains($medium)) {
                $event->addMedium($medium);
                $added++;
            }
        }
        $this->entityManager->flush();

        return $added;
    }
}
