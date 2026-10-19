<?php

declare(strict_types=1);

namespace App\Service\Pool;

use App\Entity\Medium;
use App\Entity\PoolRule;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use App\Repository\MediumRepository;
use App\Security\VisibleMedia;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pools („Freigabe-Kreise“): Benutzergruppen, deren Mitglieder bestimmte Medien sehen (siehe documentation/pools.md).
 */
class Pools
{
    public const string IN_BY_RULE = 'rule';
    public const string IN_MANUALLY = 'manual';
    public const string EXCLUDED = 'excluded';
    public const string NOT_IN = 'none';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VisibleMedia $visibleMedia,
    ) {}

    /**
     * @return list<UserGroup> nach Name
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(UserGroup::class)->findBy([], ['name' => 'ASC']);
    }

    public function create(string $name, User $owner, ?string $description = null): UserGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Der Pool braucht einen Namen.');
        }
        $pool = new UserGroup()
            ->setName($name)
            ->setOwner($owner)
            ->setDescription($description);
        $this->entityManager->persist($pool);
        $this->save();

        return $pool;
    }

    public function count(UserGroup $pool): int
    {
        return (int) $this->query($pool)->select('COUNT(pool_m.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Medium> neueste Aufnahme zuerst
     */
    public function media(UserGroup $pool, int $limit = 48): array
    {
        return $this
            ->query($pool)
            ->select('pool_m')
            ->addSelect('COALESCE(pool_m.takenAt, pool_m.createdAt) AS HIDDEN pool_date')
            ->orderBy('pool_date', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Wie gehört das Medium zum Pool? (für die Pool-Karte auf Video- und Fotoseiten)
     *
     * @return self::IN_BY_RULE|self::IN_MANUALLY|self::EXCLUDED|self::NOT_IN
     */
    public function status(UserGroup $pool, Medium $medium): string
    {
        return match (true) {
            $pool->getExcludedMedia()->contains($medium) => self::EXCLUDED,
            $medium->getAllowedGroups()->contains($pool) => self::IN_MANUALLY,
            $this->matchesRule($pool, $medium) => self::IN_BY_RULE,
            default => self::NOT_IN,
        };
    }

    /**
     * Medium in den Pool aufnehmen (einzeln) bzw. eine Ausnahme aufheben.
     */
    public function add(UserGroup $pool, Medium $medium): void
    {
        $pool->unexclude($medium);
        if (!$this->matchesRule($pool, $medium)) {
            $medium->addAllowedGroup($pool);
        }
        $this->save();
    }

    /**
     * Medium aus dem Pool nehmen: Einzelfreigabe aufheben, und passt eine Regel, als Ausnahme merken.
     */
    public function remove(UserGroup $pool, Medium $medium): void
    {
        $medium->removeAllowedGroup($pool);
        if ($this->matchesRule($pool, $medium)) {
            $pool->exclude($medium);
        }
        $this->save();
    }

    public function addRule(UserGroup $pool, PoolRule $rule): void
    {
        if (!$rule->hasCondition()) {
            throw new \InvalidArgumentException(
                'Eine Regel braucht mindestens eine Bedingung (Ereignis, Tag oder Zeitraum).',
            );
        }
        if (
            $rule->getTakenFrom() !== null
            && $rule->getTakenUntil() !== null
            && $rule->getTakenFrom() > $rule->getTakenUntil()
        ) {
            throw new \InvalidArgumentException('„Von“ liegt nach „bis“.');
        }
        $pool->addRule($rule);
        $this->entityManager->persist($rule);
        $this->save();
    }

    /**
     * @param list<User> $users
     */
    public function setMembers(UserGroup $pool, array $users): void
    {
        $wanted = [];
        foreach ($users as $user) {
            $wanted[(int) $user->getId()] = $user;
        }
        foreach ($pool->getMembers()->toArray() as $member) {
            $id = (int) $member->getUser()?->getId();
            if (!isset($wanted[$id])) {
                $pool->removeMember($member);
                $this->entityManager->remove($member);
            }
            unset($wanted[$id]);
        }
        foreach ($wanted as $user) {
            $member = new UserGroupMember()->setUser($user);
            $pool->addMember($member);
            $this->entityManager->persist($member);
        }
        $this->save();
    }

    public function delete(UserGroup $pool): void
    {
        foreach ($this->entityManager
            ->getRepository(Medium::class)
            ->createQueryBuilder('m')
            ->where(':pool MEMBER OF m.allowedGroups')
            ->setParameter('pool', $pool)
            ->getQuery()
            ->getResult() as $medium) {
            $medium->removeAllowedGroup($pool);
        }
        $this->entityManager->remove($pool);
        $this->save();
    }

    public function save(): void
    {
        $this->entityManager->flush();
        // Sichtbarkeit hat sich geändert
        $this->visibleMedia->reset();
    }

    private function matchesRule(UserGroup $pool, Medium $medium): bool
    {
        if ($pool->getId() === null || $pool->getRules()->isEmpty()) {
            return false;
        }

        return (
            (int) $this->entityManager
                ->createQueryBuilder()
                ->select('COUNT(rule_m.id)')
                ->from(Medium::class, 'rule_m')
                ->from(UserGroup::class, 'rule_g')
                ->where('rule_m = :medium')
                ->andWhere('rule_g = :pool')
                ->andWhere(MediumRepository::ruleMatchCondition('rule_m', 'rule_g'))
                ->setParameter('medium', $medium)
                ->setParameter('pool', $pool)
                ->getQuery()
                ->getSingleScalarResult() > 0
        );
    }

    private function query(UserGroup $pool): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->from(Medium::class, 'pool_m')
            ->from(UserGroup::class, 'pool_g')
            ->where('pool_g = :pool')
            ->andWhere(MediumRepository::inPoolCondition('pool_m', 'pool_g'))
            ->setParameter('pool', $pool);
    }
}
