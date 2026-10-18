<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Medium;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Medium>
 */
class MediumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Medium::class);
    }

    /**
     * Schränkt eine Abfrage auf Medien ein, die der Benutzer sehen darf (gleiche Regeln wie MediumAccessVoter).
     */
    public static function restrictToVisible(QueryBuilder $qb, string $alias, ?User $user, bool $isAdmin): QueryBuilder
    {
        if ($isAdmin) {
            return $qb;
        }
        if ($user === null) {
            return $qb->andWhere(sprintf('%s.isPublic = true', $alias));
        }

        return $qb->andWhere(sprintf(
            '%1$s.isPublic = true OR %1$s.owner = :visibleFor OR EXISTS ('
            . 'SELECT 1 FROM App\Entity\UserGroupMember vis_m JOIN vis_m.userGroup vis_g '
            . 'WHERE vis_m.user = :visibleFor AND vis_g MEMBER OF %1$s.allowedGroups)',
            $alias,
        ))->setParameter('visibleFor', $user);
    }
}
