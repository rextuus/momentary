<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Medium;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Rechte an Videos und Fotos: Admin und Eigentümer dürfen alles, sonst sehen nur öffentliche Medien oder Mitglieder
 * freigegebener Gruppen etwas. VIDEO_* gibt es aus der Zeit vor dem gemeinsamen Medium-Typ und gilt weiter.
 *
 * @extends Voter<string, Medium>
 */
class MediumAccessVoter extends Voter
{
    public const VIEW = 'MEDIUM_VIEW';
    public const EDIT = 'MEDIUM_EDIT';
    public const VIDEO_VIEW = 'VIDEO_VIEW';
    public const VIDEO_EDIT = 'VIDEO_EDIT';

    public function __construct(
        private Security $security,
    ) {}

    /**
     * Darf diese Person das Medium sehen? Dieselbe Regel wie beim Abstimmen, aber für eine beliebige Person (z. B. den
     * Ersteller eines Share-Links), nicht nur für den angemeldeten Benutzer.
     */
    public static function canView(?User $user, Medium $medium): bool
    {
        if ($user === null) {
            return $medium->isPublic();
        }
        if (
            \in_array('ROLE_ADMIN', $user->getRoles(), true)
            || $medium->getOwner()?->getId() === $user->getId()
            || $medium->isPublic()
        ) {
            return true;
        }
        foreach ($medium->getAllowedGroups() as $group) {
            foreach ($group->getMembers() as $member) {
                if ($member->getUser()?->getId() === $user->getId()) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::VIDEO_VIEW, self::VIDEO_EDIT], true)
        && $subject instanceof Medium;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        #[\SensitiveParameter]
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        // Admin always has access
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $video = $subject;

        // Owner check
        if ($user instanceof User && $video->getOwner() && $video->getOwner()->getId() === $user->getId()) {
            return true;
        }

        // Bearbeiten dürfen nur Admin und Eigentümer
        if ($attribute === self::EDIT || $attribute === self::VIDEO_EDIT) {
            return false;
        }

        // Public check
        if ($video->isPublic()) {
            return true;
        }

        // Group check
        if ($user instanceof User) {
            foreach ($video->getAllowedGroups() as $group) {
                foreach ($group->getMembers() as $member) {
                    if ($member->getUser() && $member->getUser()->getId() === $user->getId()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
