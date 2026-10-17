<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Video;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class VideoAccessVoter extends Voter
{
    public const VIEW = 'VIDEO_VIEW';
    public const EDIT = 'VIDEO_EDIT';

    public function __construct(
        private Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Video;
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

        /** @var Video $video */
        $video = $subject;

        // Owner check
        if ($user instanceof User && $video->getOwner() && $video->getOwner()->getId() === $user->getId()) {
            return true;
        }

        // Bearbeiten dürfen nur Admin und Eigentümer
        if ($attribute === self::EDIT) {
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
