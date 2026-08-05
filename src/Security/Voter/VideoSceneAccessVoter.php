<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\VideoScene;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class VideoSceneAccessVoter extends Voter
{
    public const VIEW = 'SCENE_VIEW';

    public function __construct(
        private Security $security
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof VideoScene;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        
        // Admin always has access
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var VideoScene $scene */
        $scene = $subject;

        // Public check
        if ($scene->isPublic()) {
            return true;
        }

        // Check Scene-specific group access (Override)
        if (!$scene->getAllowedGroups()->isEmpty()) {
            if ($user instanceof User) {
                foreach ($scene->getAllowedGroups() as $group) {
                    foreach ($group->getMembers() as $member) {
                        if ($member->getUser() && $member->getUser()->getId() === $user->getId()) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        // Check Video-level access (includes public, owner, video-groups)
        if ($this->security->isGranted(VideoAccessVoter::VIEW, $scene->getVideo())) {
            return true;
        }
        
        return false;
    }
}
