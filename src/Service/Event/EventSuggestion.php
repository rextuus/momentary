<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\Photo;

/**
 * Eine Gruppe zeitlich zusammenhängender Medien ohne Ereignis.
 */
final readonly class EventSuggestion
{
    /**
     * @param list<Medium> $media nach Aufnahmezeit aufsteigend
     */
    public function __construct(
        public array $media,
        /** Fern von zuhause aufgenommen (Reise), ggf. über mehrere Orte */
        public bool $trip = false,
    ) {}

    /**
     * Stabil, solange sich die Medien der Gruppe nicht ändern; neue Medien ergeben einen neuen Vorschlag.
     */
    public function getKey(): string
    {
        $ids = array_map(static fn(Medium $m): int => (int) $m->getId(), $this->media);
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    public function getStart(): \DateTimeImmutable
    {
        return self::local($this->media[0]);
    }

    public function getEnd(): \DateTimeImmutable
    {
        return self::local($this->media[\count($this->media) - 1]);
    }

    public function getVideoCount(): int
    {
        return \count(array_filter($this->media, static fn(Medium $m): bool => $m->getType() === 'video'));
    }

    public function getPhotoCount(): int
    {
        return \count(array_filter($this->media, static fn(Medium $m): bool => $m instanceof Photo));
    }

    /**
     * Gleichmäßig über den Zeitraum verteilte Vorschaumedien.
     *
     * @return list<Medium>
     */
    public function getPreview(int $count = 5): array
    {
        $withPreview = array_values(array_filter(
            $this->media,
            static fn(Medium $m): bool => $m->getPreviewFile() !== null,
        ));
        if (\count($withPreview) <= $count) {
            return $withPreview;
        }

        $step = (\count($withPreview) - 1) / ($count - 1);

        return array_map(static fn(int $i): Medium => $withPreview[(int) round($i * $step)], range(0, $count - 1));
    }

    /**
     * Personen mit Namen, häufigste zuerst, und Zahl der noch unbenannten.
     *
     * @return array{named: list<Person>, unnamed: int}
     */
    public function getPersons(): array
    {
        $counts = [];
        $persons = [];
        foreach ($this->media as $medium) {
            foreach ($medium->getFaces() as $face) {
                $person = $face->getPerson();
                if ($person !== null && $person->getId() !== null) {
                    $persons[$person->getId()] = $person;
                    $counts[$person->getId()] = ($counts[$person->getId()] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        $named = [];
        $unnamed = 0;
        foreach (array_keys($counts) as $id) {
            if ($persons[$id]->hasPlaceholderName()) {
                $unnamed++;
            } else {
                $named[] = $persons[$id];
            }
        }

        return ['named' => $named, 'unnamed' => $unnamed];
    }

    /**
     * Häufigste Tags der Medien, für den Titelvorschlag.
     *
     * @return list<string>
     */
    public function getTopTags(int $limit = 15): array
    {
        $counts = [];
        foreach ($this->media as $medium) {
            foreach ($medium->getTags() as $tag) {
                $counts[(string) $tag->getName()] = ($counts[(string) $tag->getName()] ?? 0) + 1;
            }
        }
        arsort($counts);

        return \array_slice(array_keys($counts), 0, $limit);
    }

    private static function local(Medium $medium): \DateTimeImmutable
    {
        return ($medium->getDate() ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * Ortsnamen der Medien, häufigste zuerst.
     *
     * @return list<string>
     */
    public function getPlaces(int $limit = 3): array
    {
        $counts = [];
        foreach ($this->media as $medium) {
            if ($medium->getPlaceName() !== null) {
                $counts[$medium->getPlaceName()] = ($counts[$medium->getPlaceName()] ?? 0) + 1;
            }
        }
        arsort($counts);

        return \array_slice(array_map(strval(...), array_keys($counts)), 0, $limit);
    }
}
