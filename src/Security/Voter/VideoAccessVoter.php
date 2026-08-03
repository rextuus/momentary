<?php

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

    public function __construct(
        private Security $security
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof Video;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Admin always has access
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var Video $video */
        $video = $subject;

        // Public check
        if ($video->isPublic()) {
            return true;
        }

        // Owner check
        if ($user instanceof User && $video->getOwner() === $user) {
            return true;
        }

        // Group check
        if ($user instanceof User) {
            foreach ($video->getAllowedGroups() as $group) {
                foreach ($group->getMembers() as $member) {
                    if ($member->getUser() === $user) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
