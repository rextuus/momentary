<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\User;
use App\Repository\EventRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Ereignisse eines Videos oder Fotos, mit Auswahl zum Hinzufügen.
 */
#[AsTwigComponent]
final class MediumEventsComponent
{
    public Medium $medium;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly Security $security,
    ) {}

    /**
     * Ereignisse, denen der Benutzer das Medium hinzufügen darf (eigene bzw. als Admin alle).
     *
     * @return list<Event>
     */
    public function getSelectableEvents(): array
    {
        $user = $this->security->getUser();
        $criteria = $this->security->isGranted('ROLE_ADMIN') || !$user instanceof User ? [] : ['owner' => $user];

        return array_values(array_filter(
            $this->eventRepository->findBy($criteria, ['startDate' => 'DESC', 'id' => 'DESC']),
            fn(Event $event): bool => !$event->getMedia()->contains($this->medium),
        ));
    }
}
