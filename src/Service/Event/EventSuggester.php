<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\EventSuggestionDismissal;
use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\MediumRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Schlägt Ereignisse vor: Medien ohne Ereignis, die zeitlich (und räumlich) zusammenhängen.
 *
 * Eine Lücke von mehr als $gapHours ohne Aufnahme trennt zwei Gruppen. Mit GPS wird zusätzlich das Zuhause bestimmt
 * (Ort mit den meisten Aufnahmen): Was weiter als HOME_RADIUS_KM entfernt entstand, gehört zu einer Reise. Eine Reise
 * hält über mehrere Orte und über Tage ohne Aufnahme (bis TRIP_GAP_HOURS) zusammen; der Wechsel zwischen zuhause und
 * unterwegs trennt. Ohne bestimmbares Zuhause trennt ein Ortssprung von mehr als MAX_DISTANCE_KM.
 */
class EventSuggester
{
    public const int DEFAULT_GAP_HOURS = 24;
    public const array GAP_OPTIONS = [6, 12, 24, 48, 72];

    /** Kleinere Gruppen sind meist Einzelaufnahmen, kein Ereignis */
    public const int MIN_MEDIA = 3;

    private const float MAX_DISTANCE_KM = 100.0;
    private const float HOME_RADIUS_KM = 50.0;
    private const int TRIP_GAP_HOURS = 72;

    /** So viele Aufnahmen mit GPS braucht es, um ein Zuhause zu bestimmen */
    private const int MIN_LOCATED_FOR_HOME = 5;

    /** Rastergröße zum Bestimmen des Zuhauses (~11 km) */
    private const float HOME_GRID_DEGREES = 0.1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<EventSuggestion> neueste zuerst
     */
    public function suggest(?User $user, bool $isAdmin, int $gapHours = self::DEFAULT_GAP_HOURS): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->addSelect('COALESCE(m.takenAt, m.createdAt) AS HIDDEN sortDate')
            ->from(Medium::class, 'm')
            ->where('m.events IS EMPTY')
            ->orderBy('sortDate', \SortDirection::Ascending)
            ->addOrderBy('m.id', \SortDirection::Ascending);
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);

        /** @var list<Medium> $media */
        $media = $qb->getQuery()->getResult();
        $dismissed = $user !== null ? $this->dismissedKeys($user) : [];

        $suggestions = [];
        foreach (self::group($media, $gapHours, $this->home($user, $isAdmin)) as $suggestion) {
            if (\count($suggestion->media) >= self::MIN_MEDIA && !isset($dismissed[$suggestion->getKey()])) {
                $suggestions[] = $suggestion;
            }
        }

        return array_reverse($suggestions);
    }

    public function find(
        ?User $user,
        bool $isAdmin,
        string $key,
        int $gapHours = self::DEFAULT_GAP_HOURS,
    ): ?EventSuggestion {
        foreach ($this->suggest($user, $isAdmin, $gapHours) as $suggestion) {
            if ($suggestion->getKey() === $key) {
                return $suggestion;
            }
        }

        return null;
    }

    public function dismiss(User $user, string $key): void
    {
        if (isset($this->dismissedKeys($user)[$key])) {
            return;
        }
        $this->entityManager->persist(new EventSuggestionDismissal($user, $key));
        $this->entityManager->flush();
    }

    /**
     * @param list<Medium>        $media aufsteigend nach Aufnahmezeit
     * @param array{float, float}|null $home  Zuhause (Breite, Länge), falls bekannt
     *
     * @return list<EventSuggestion>
     */
    public static function group(array $media, int $gapHours, ?array $home = null): array
    {
        $groups = [];
        $current = [];
        $currentAway = null;
        $previous = null;
        $lastLocated = null;
        foreach ($media as $medium) {
            $away = $home !== null && $medium->hasLocation()
                ? self::distance((float) $medium->getLatitude(), (float) $medium->getLongitude(), $home[0], $home[1])
                > self::HOME_RADIUS_KM
                : null;

            if ($previous !== null) {
                $gap = (int) $medium->getDate()?->getTimestamp() - (int) $previous->getDate()?->getTimestamp();
                $allowedGap =
                    $currentAway === true && $away !== false ? max($gapHours, self::TRIP_GAP_HOURS) : $gapHours;
                $split = $gap > ($allowedGap * 3600);
                if ($home !== null) {
                    // Zwischen zuhause und unterwegs trennen
                    $split = $split || $away !== null && $currentAway !== null && $away !== $currentAway;
                } elseif ($lastLocated !== null && $medium->hasLocation()) {
                    $split =
                        $split
                        || self::distance(
                            (float) $lastLocated->getLatitude(),
                            (float) $lastLocated->getLongitude(),
                            (float) $medium->getLatitude(),
                            (float) $medium->getLongitude(),
                        ) > self::MAX_DISTANCE_KM;
                }
                if ($split) {
                    $groups[] = new EventSuggestion($current, $currentAway === true);
                    $current = [];
                    $currentAway = null;
                    $lastLocated = null;
                }
            }

            $current[] = $medium;
            $previous = $medium;
            $currentAway ??= $away;
            if ($medium->hasLocation()) {
                $lastLocated = $medium;
            }
        }
        if ($current !== []) {
            $groups[] = new EventSuggestion($current, $currentAway === true);
        }

        return $groups;
    }

    /**
     * Zuhause = Rasterfeld mit den meisten Aufnahmen mit GPS (über alle sichtbaren Medien, auch solche in Ereignissen).
     *
     * @return array{float, float}|null
     */
    private function home(?User $user, bool $isAdmin): ?array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m.latitude AS lat', 'm.longitude AS lon')
            ->from(Medium::class, 'm')
            ->where('m.latitude IS NOT NULL AND m.longitude IS NOT NULL');
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);

        return self::homeOf(array_values(array_map(static fn(array $row): array => [
            (float) $row['lat'],
            (float) $row['lon'],
        ], $qb->getQuery()->getArrayResult())));
    }

    /**
     * @param list<array{float, float}> $locations
     *
     * @return array{float, float}|null Mittelpunkt des häufigsten Rasterfelds
     */
    public static function homeOf(array $locations): ?array
    {
        if (\count($locations) < self::MIN_LOCATED_FOR_HOME) {
            return null;
        }

        $cells = [];
        foreach ($locations as [$lat, $lon]) {
            $key = sprintf(
                '%d:%d',
                (int) floor($lat / self::HOME_GRID_DEGREES),
                (int) floor($lon / self::HOME_GRID_DEGREES),
            );
            $cells[$key][] = [$lat, $lon];
        }
        uasort($cells, static fn(array $a, array $b): int => \count($b) <=> \count($a));
        $best = reset($cells);

        return [
            array_sum(array_column($best, 0)) / \count($best),
            array_sum(array_column($best, 1)) / \count($best),
        ];
    }

    /**
     * Entfernung zweier Fotos (Haversine).
     */
    public static function distanceKm(Photo $a, Photo $b): float
    {
        return self::distance(
            (float) $a->getLatitude(),
            (float) $a->getLongitude(),
            (float) $b->getLatitude(),
            (float) $b->getLongitude(),
        );
    }

    private static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($lon2 - $lon1);
        $h = sin($dLat / 2) ** 2 + (cos($lat1) * cos($lat2) * (sin($dLon / 2) ** 2));

        return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
    }

    /**
     * @return array<string, true>
     */
    private function dismissedKeys(User $user): array
    {
        $keys = $this->entityManager
            ->createQueryBuilder()
            ->select('d.suggestionKey')
            ->from(EventSuggestionDismissal::class, 'd')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map(strval(...), $keys), true);
    }
}
