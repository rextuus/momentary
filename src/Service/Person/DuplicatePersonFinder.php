<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\Entity\Person;
use App\Entity\PersonPairDismissal;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Service\Face\FaceRecognitionSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Findet Personen, die vermutlich derselbe Mensch sind, über Medien hinweg.
 *
 * Grundlage sind die bei der Analyse gespeicherten Rekognition-Kandidaten (`VideoFace.matchCandidates`): Ähnelt ein
 * Gesicht von A einem Gesicht von B, spricht das für dieselbe Person. Nie vorgeschlagen werden Paare, die zusammen in
 * einem Bild vorkommen, zwei bereits benannte Personen und vom Benutzer verworfene Paare.
 */
class DuplicatePersonFinder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
    ) {}

    /**
     * @return list<PersonPairSuggestion> beste zuerst
     */
    public function find(int $limit = 50): array
    {
        $minimum = $this->settingsProvider->thresholds()->candidate;
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT f.id, f.person_id, f.face_label, f.face_image_id, f.match_candidates
             FROM video_face f JOIN person p ON p.id = f.person_id
             WHERE p.status NOT IN (?, ?)', [
            PersonStatus::MERGED->value,
            PersonStatus::WASTED->value,
        ]);

        $imagesByPerson = [];
        foreach ($rows as $row) {
            if ($row['face_image_id'] !== null) {
                $imagesByPerson[(int) $row['person_id']][(int) $row['face_image_id']] = true;
            }
        }

        $dismissed = $this->dismissedKeys();
        $suggestions = [];
        foreach ($this->collectPairs($rows, $minimum) as $key => $pair) {
            [$a, $b] = array_map(intval(...), explode('-', (string) $key));
            // Zusammen in einem Bild: sicher zwei Menschen
            if (
                isset($dismissed[$key])
                || array_intersect_key($imagesByPerson[$a] ?? [], $imagesByPerson[$b] ?? []) !== []
            ) {
                continue;
            }
            $suggestion = $this->suggest($a, $b, $pair);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        usort(
            $suggestions,
            static fn(PersonPairSuggestion $x, PersonPairSuggestion $y): int => (
                [$y->similarity, $y->support] <=> [$x->similarity, $x->support]
            ),
        );

        return \array_slice($suggestions, 0, $limit);
    }

    public function dismiss(Person $one, Person $other): void
    {
        $dismissal = new PersonPairDismissal($one, $other);
        if (
            isset($this->dismissedKeys()[$dismissal->getPersonA()->getId() . '-' . $dismissal->getPersonB()->getId()])
        ) {
            return;
        }
        $this->entityManager->persist($dismissal);
        $this->entityManager->flush();
    }

    /**
     * Paare unterschiedlicher Personen aus den Rekognition-Kandidaten, Schlüssel "kleinere ID-größere ID".
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{float, int, int, int}> [beste Ähnlichkeit, Anzahl, Gesicht A, Gesicht B]
     */
    private function collectPairs(array $rows, float $minimum): array
    {
        $byLabel = [];
        foreach ($rows as $row) {
            if ($row['face_label'] !== null) {
                $byLabel[(string) $row['face_label']] = $row;
            }
        }

        $pairs = [];
        foreach ($rows as $row) {
            $candidates = json_decode((string) ($row['match_candidates'] ?? '[]'), true);
            foreach (\is_array($candidates) ? $candidates : [] as $label => $similarity) {
                $other = $byLabel[(string) $label] ?? null;
                if ($other === null || $other['person_id'] === $row['person_id'] || (float) $similarity < $minimum) {
                    continue;
                }
                [$first, $second] = (int) $row['person_id'] < (int) $other['person_id']
                    ? [$row, $other]
                    : [$other, $row];
                $key = (int) $first['person_id'] . '-' . (int) $second['person_id'];
                $count = ($pairs[$key][1] ?? 0) + 1;
                if (!isset($pairs[$key]) || (float) $similarity > $pairs[$key][0]) {
                    $pairs[$key] = [(float) $similarity, $count, (int) $first['id'], (int) $second['id']];
                } else {
                    $pairs[$key][1] = $count;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array{float, int, int, int} $pair
     */
    private function suggest(int $a, int $b, array $pair): ?PersonPairSuggestion
    {
        [$similarity, $support, $faceA, $faceB] = $pair;
        $personA = $this->entityManager->find(Person::class, $a);
        $personB = $this->entityManager->find(Person::class, $b);
        if ($personA === null || $personB === null || self::isNamed($personA) && self::isNamed($personB)) {
            return null;
        }

        // Behalten wird die benannte Person, sonst die mit mehr Gesichtern
        $keepA =
            self::isNamed($personA)
            || !self::isNamed($personB) && $personA->getVideoFaces()->count() >= $personB->getVideoFaces()->count();

        return new PersonPairSuggestion(
            $keepA ? $personA : $personB,
            $keepA ? $personB : $personA,
            $similarity,
            $support,
            $this->entityManager->find(VideoFace::class, $keepA ? $faceA : $faceB),
            $this->entityManager->find(VideoFace::class, $keepA ? $faceB : $faceA),
        );
    }

    private static function isNamed(Person $person): bool
    {
        return $person->getStatus() === PersonStatus::IDENTIFIED && !$person->hasPlaceholderName();
    }

    /**
     * @return array<string, true>
     */
    private function dismissedKeys(): array
    {
        $keys = [];
        foreach ($this->entityManager
            ->getConnection()
            ->fetchAllAssociative('SELECT person_a_id, person_b_id FROM person_pair_dismissal') as $row) {
            $keys[$row['person_a_id'] . '-' . $row['person_b_id']] = true;
        }

        return $keys;
    }
}
