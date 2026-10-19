<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Ereignisse, die der Benutzer sieht: eigene und solche mit mindestens einem sichtbaren Medium (Pools).
     *
     * @return list<Event> neueste zuerst
     */
    public function findVisible(?User $user, bool $isAdmin): array
    {
        $qb = $this
            ->createQueryBuilder('e')
            ->orderBy('e.startDate', \SortDirection::Descending)
            ->addOrderBy('e.id', \SortDirection::Descending);
        $condition = MediumRepository::visibleCondition('ev_m', $user, $isAdmin);
        if ($condition !== null) {
            $visibleMedium = sprintf(
                'EXISTS (SELECT 1 FROM %s ev_e JOIN ev_e.media ev_m WHERE ev_e = e AND %s)',
                Event::class,
                $condition,
            );
            $qb->andWhere($user !== null ? sprintf('e.owner = :visibleFor OR %s', $visibleMedium) : $visibleMedium);
            if ($user !== null) {
                $qb->setParameter('visibleFor', $user);
            }
        }

        return $qb->getQuery()->getResult();
    }
}
