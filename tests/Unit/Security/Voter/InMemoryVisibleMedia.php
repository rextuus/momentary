<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Medium;
use App\Entity\User;
use App\Security\VisibleMedia;

/**
 * Für Unit-Tests der Voter: Sichtbarkeit ohne Datenbank (öffentlich, Eigentümer, einzeln freigegebene Gruppe).
 * Die Pool-Regeln prüft tests/Integration/Security/PoolVisibilityTest gegen die Datenbank.
 */
final class InMemoryVisibleMedia extends VisibleMedia
{
    public function __construct() {}

    public function isVisible(?User $user, Medium $medium): bool
    {
        if ($medium->isPublic() || self::isAdmin($user)) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if ($medium->getOwner()?->getId() === $user->getId() && $user->getId() !== null) {
            return true;
        }
        foreach ($medium->getAllowedGroups() as $group) {
            if ($group->hasMember($user)) {
                return true;
            }
        }

        return false;
    }
}
