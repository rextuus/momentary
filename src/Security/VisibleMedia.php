<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Medium;
use App\Entity\User;
use App\Repository\MediumRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Darf ein Benutzer ein Medium sehen? Dieselbe Regel wie MediumRepository::visibleCondition() (Pools, Gruppen,
 * öffentlich, Eigentümer); einmal je Benutzer und Anfrage als Liste der sichtbaren IDs geladen.
 */
class VisibleMedia implements ResetInterface
{
    /** @var array<int, array<int, true>> Benutzer-ID => sichtbare Medien-IDs */
    private array $visibleIds = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    public function isVisible(?User $user, Medium $medium): bool
    {
        if ($medium->isPublic() || self::isAdmin($user)) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if ($medium->getOwner()?->getId() === $user->getId()) {
            return true;
        }

        return isset($this->ids($user)[(int) $medium->getId()]);
    }

    public function reset(): void
    {
        $this->visibleIds = [];
    }

    /**
     * @return array<int, true>
     */
    private function ids(User $user): array
    {
        $key = (int) $user->getId();
        if (!isset($this->visibleIds[$key])) {
            $qb = $this->entityManager->createQueryBuilder()->select('m.id')->from(Medium::class, 'm');
            MediumRepository::restrictToVisible($qb, 'm', $user, false);
            $this->visibleIds[$key] = array_fill_keys(
                array_map(intval(...), $qb->getQuery()->getSingleColumnResult()),
                true,
            );
        }

        return $this->visibleIds[$key];
    }
}
