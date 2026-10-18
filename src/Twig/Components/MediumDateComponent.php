<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Event;
use App\Entity\Medium;
use App\Service\Media\CameraClock;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Aufnahmezeit eines Videos oder Fotos mit Herkunft und Korrektur-Formular.
 */
#[AsTwigComponent]
final class MediumDateComponent
{
    public Medium $medium;

    /** Wohin nach dem Speichern zurück (z. B. die Ereignisseite) */
    public ?string $back = null;

    public function __construct(
        private readonly Security $security,
        private readonly CameraClock $cameraClock,
    ) {}

    /**
     * @return list<string> Hinweise auf eine falsch gehende Kamera-Uhr
     */
    public function getWarnings(): array
    {
        return $this->cameraClock->warnings($this->medium);
    }

    /**
     * Ereignisse des Mediums, die der Benutzer als Ganzes verschieben darf.
     *
     * @return list<Event>
     */
    public function getShiftableEvents(): array
    {
        $user = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        return array_values(array_filter(
            $this->medium->getEvents()->toArray(),
            static fn(Event $event): bool => (
                $isAdmin
                || $user !== null
                && $event->getOwner()?->getUserIdentifier() === $user->getUserIdentifier()
            ),
        ));
    }

    /** Wert für das datetime-local-Feld (deutsche Zeit) */
    public function getInputValue(): string
    {
        return ($this->medium->getDate() ?? new \DateTimeImmutable())
            ->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d\TH:i');
    }
}
