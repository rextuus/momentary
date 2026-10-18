<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Service\Share\ShareLinks;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * „Teilen“-Karte auf Ereignis-, Video- und Fotoseiten: Links anlegen, kopieren, zurückziehen.
 */
#[AsTwigComponent]
final class SharePanelComponent
{
    public Event|Medium $target;

    /** Wohin nach dem Anlegen/Zurückziehen (die aktuelle Seite) */
    public string $back = '';

    public function __construct(
        private readonly ShareLinks $shares,
        private readonly Security $security,
    ) {}

    public function getTargetType(): string
    {
        return $this->target instanceof Event ? 'event' : 'medium';
    }

    /**
     * @return list<ShareLink> aktive Links zuerst
     */
    public function getLinks(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }
        $links = $this->shares->list($user, $this->target);
        usort(
            $links,
            static fn(ShareLink $a, ShareLink $b): int => (
                [$b->isActive(), $b->getId()] <=> [$a->isActive(), $a->getId()]
            ),
        );

        return $links;
    }

    /**
     * @return array<string, string>
     */
    public function getExpiryOptions(): array
    {
        return ShareLinks::EXPIRY;
    }

    public function getDefaultExpiry(): string
    {
        return ShareLinks::DEFAULT_EXPIRY;
    }
}
