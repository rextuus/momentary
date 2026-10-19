<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Medium;
use App\Entity\User;
use App\Security\VisibleMedia;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Rechte an Videos und Fotos: Admin und Eigentümer dürfen alles, sonst sieht man öffentliche Medien und die Medien
 * der Pools, in denen man Mitglied ist (VisibleMedia). VIDEO_* gibt es aus der Zeit vor dem gemeinsamen Medium-Typ und gilt weiter.
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
        private VisibleMedia $visibleMedia,
    ) {}

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

        // Öffentlich, Gruppen und Pools (Regeln, einzeln hinzugefügt, ausgenommen)
        return $this->visibleMedia->isVisible($user instanceof User ? $user : null, $video);
    }
}
