<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\Photo;
use App\Service\Photo\PhotoSearchIndex;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Korrigiert die Aufnahmezeit, z. B. wenn die Uhr der Kamera falsch gestellt war.
 *
 * Für ein Medium wird die richtige Zeit angegeben; auf Wunsch werden alle Medien eines Ereignisses um denselben
 * Abstand verschoben (die Abstände untereinander stimmen ja meist) und der Zeitraum des Ereignisses angepasst.
 */
class RecordingTimeCorrector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly PhotoSearchIndex $photoSearchIndex,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param \DateTimeImmutable $correct richtige Aufnahmezeit des Ankers (beliebige Zeitzone)
     * @param Event|null         $event   alle Medien dieses Ereignisses mit verschieben
     *
     * @return int Anzahl geänderter Medien
     */
    public function correct(Medium $anchor, \DateTimeImmutable $correct, ?Event $event = null): int
    {
        $utc = new \DateTimeZone('UTC');
        $offset = $correct->getTimestamp() - (int) $anchor->getDate()?->getTimestamp();

        $media = [$anchor];
        if ($event !== null) {
            foreach ($event->getMedia() as $medium) {
                // Nur Medien, die der Benutzer auch bearbeiten darf
                if ($medium !== $anchor && $this->security->isGranted('MEDIUM_EDIT', $medium)) {
                    $media[] = $medium;
                }
            }
        }

        foreach ($media as $medium) {
            $date = $medium === $anchor ? $correct : $medium->getDate()?->modify(sprintf('%+d seconds', $offset));
            $medium->setRecordedAt($date?->setTimezone($utc), Medium::DATE_MANUAL);
        }
        if ($event !== null) {
            $this->fitEventPeriod($event);
        }
        $this->entityManager->flush();

        foreach ($media as $medium) {
            if ($medium instanceof Photo) {
                try {
                    $this->photoSearchIndex->index($medium);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        'Suchindex für Foto %d: %s',
                        (int) $medium->getId(),
                        $e->getMessage(),
                    ));
                }
            }
        }

        return \count($media);
    }

    /**
     * Zeitraum des Ereignisses auf erste und letzte Aufnahme (Tage in deutscher Zeit) setzen.
     */
    private function fitEventPeriod(Event $event): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $dates = array_filter(array_map(
            static fn(Medium $m): ?\DateTimeImmutable => $m->getDate(),
            $event->getMedia()->toArray(),
        ));
        if ($dates === []) {
            return;
        }

        $event->setStartDate(new \DateTimeImmutable(min($dates)->setTimezone($zone)->format('Y-m-d')));
        $event->setEndDate(new \DateTimeImmutable(max($dates)->setTimezone($zone)->format('Y-m-d')));
    }
}
