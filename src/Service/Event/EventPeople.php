<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Service\Person\PersonNameHints;

/**
 * „Wer ist dabei?“: unbenannte Personen eines Ereignisses mit Beispielgesicht und Namenshinweisen.
 */
class EventPeople
{
    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly PersonNameHints $nameHints,
    ) {}

    /**
     * @param list<Medium> $media die sichtbaren Medien des Ereignisses
     *
     * @return list<array{person: Person, count: int, face: VideoFace, hints: list<array{name: string, scenes: int}>}> häufigste zuerst
     */
    public function unnamed(array $media): array
    {
        $persons = [];
        foreach ($media as $medium) {
            foreach ($medium->getFaces() as $face) {
                $person = $face->getPerson();
                if ($person === null || $person->getId() === null || !self::needsName($person)) {
                    continue;
                }
                $entry = $persons[$person->getId()] ?? [
                    'person' => $person,
                    'count' => 0,
                    'face' => $face,
                    'hints' => [],
                ];
                $entry['count']++;
                // Größtes Gesicht als Beispiel, kleine Gesichter im Hintergrund erkennt man schlecht
                if (self::area($face) > self::area($entry['face'])) {
                    $entry['face'] = $face;
                }
                $persons[$person->getId()] = $entry;
            }
        }
        usort($persons, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        foreach ($persons as &$entry) {
            $entry['hints'] = $this->nameHints->forPerson($entry['person']);
        }

        return $persons;
    }

    /**
     * Namen benannter Personen, für die Auswahlliste beim Benennen.
     *
     * @return list<string>
     */
    public function knownNames(): array
    {
        $names = [];
        foreach ($this->personRepository->findBy(['status' => PersonStatus::IDENTIFIED], [
            'name' => 'ASC',
        ]) as $person) {
            if (!$person->hasPlaceholderName()) {
                $names[] = (string) $person->getName();
            }
        }

        return array_values(array_unique($names));
    }

    private static function needsName(Person $person): bool
    {
        return \in_array(
            $person->getStatus(),
            [PersonStatus::NEW, PersonStatus::IDENTIFIED, PersonStatus::TO_REASSIGN],
            true,
        ) && $person->hasPlaceholderName();
    }

    private static function area(VideoFace $face): float
    {
        $box = $face->getBoundingBox() ?? [];

        return (float) ($box['Width'] ?? 0) * (float) ($box['Height'] ?? 0);
    }
}
