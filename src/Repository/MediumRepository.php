<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\PoolRule;
use App\Entity\User;
use App\Entity\UserGroupMember;
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
     * Schränkt eine Abfrage auf Medien ein, die der Benutzer sehen darf (dieselbe Regel nutzt MediumAccessVoter über
     * VisibleMedia).
     */
    public static function restrictToVisible(QueryBuilder $qb, string $alias, ?User $user, bool $isAdmin): QueryBuilder
    {
        if ($isAdmin) {
            return $qb;
        }
        if ($user === null) {
            return $qb->andWhere(sprintf('%s.isPublic = true', $alias));
        }
        // Eigene Unterabfrage statt Bedingung auf dem äußeren Alias: verträgt sich mit Paginator und vererbten Tabellen
        $inner = 'vis_' . $alias;
        $qb->andWhere(sprintf(
            '%s.id IN (SELECT %s.id FROM %s %s WHERE %s)',
            $alias,
            $inner,
            Medium::class,
            $inner,
            (string) self::visibleCondition($inner, $user, false),
        ));
        $qb->setParameter('visibleFor', $user);

        return $qb;
    }

    /**
     * DQL-Bedingung „das Medium $alias darf $user sehen“, auch für Unterabfragen (Gesichter, Personen, Tags).
     * null: keine Einschränkung (Admin). Braucht den Parameter `visibleFor`, wenn $user gesetzt ist.
     *
     * Sichtbar ist ein Medium, wenn es öffentlich ist, dem Benutzer gehört oder zu einem Pool gehört, in dem er
     * Mitglied ist: einzeln hinzugefügt (allowedGroups) oder eine Regel passt (PoolRule: alle gesetzten Bedingungen),
     * und es ist im Pool nicht ausgenommen.
     */
    public static function visibleCondition(string $alias, ?User $user, bool $isAdmin): ?string
    {
        if ($isAdmin) {
            return null;
        }
        if ($user === null) {
            return sprintf('%s.isPublic = true', $alias);
        }

        $g = 'vis_g_' . $alias;

        return sprintf(
            '(%1$s.isPublic = true OR %1$s.owner = :visibleFor OR EXISTS ('
            . 'SELECT 1 FROM %2$s vis_pm_%1$s JOIN vis_pm_%1$s.userGroup %3$s '
            . 'WHERE vis_pm_%1$s.user = :visibleFor AND %4$s))',
            $alias,
            UserGroupMember::class,
            $g,
            self::inPoolCondition($alias, $g),
        );
    }

    /**
     * DQL-Bedingung „das Medium $medium gehört zum Pool $pool“: nicht ausgenommen und einzeln hinzugefügt oder eine
     * Regel passt (alle gesetzten Bedingungen der Regel).
     */
    public static function inPoolCondition(string $medium, string $pool): string
    {
        return sprintf(
            '%1$s NOT MEMBER OF %2$s.excludedMedia AND (%2$s MEMBER OF %1$s.allowedGroups OR %3$s)',
            $medium,
            $pool,
            self::ruleMatchCondition($medium, $pool),
        );
    }

    /**
     * DQL-Bedingung „eine Regel des Pools $pool passt auf $medium“ (ohne Ausnahmen und Einzelfreigaben).
     */
    public static function ruleMatchCondition(string $medium, string $pool): string
    {
        $r = 'vis_r_' . $medium;
        $date = sprintf('COALESCE(%1$s.takenAt, %1$s.createdAt)', $medium);
        $rule = implode(' AND ', [
            sprintf('%s.pool = %s', $r, $pool),
            sprintf(
                '(%1$s.tag IS NULL OR EXISTS (SELECT 1 FROM %2$s vis_tm_%3$s JOIN vis_tm_%3$s.tags vis_t_%3$s '
                . 'WHERE vis_tm_%3$s = %3$s AND vis_t_%3$s = %1$s.tag))',
                $r,
                Medium::class,
                $medium,
            ),
            sprintf('(%1$s.takenFrom IS NULL OR %2$s >= %1$s.takenFrom)', $r, $date),
            sprintf("(%1\$s.takenUntil IS NULL OR %2\$s < DATE_ADD(%1\$s.takenUntil, 1, 'DAY'))", $r, $date),
            sprintf(
                '(%1$s.event IS NULL OR EXISTS (SELECT 1 FROM %2$s vis_e_%3$s JOIN vis_e_%3$s.media vis_em_%3$s '
                . 'WHERE vis_e_%3$s = %1$s.event AND vis_em_%3$s = %3$s))',
                $r,
                Event::class,
                $medium,
            ),
        ]);

        return sprintf('EXISTS (SELECT 1 FROM %s %s WHERE %s)', PoolRule::class, $r, $rule);
    }
}
