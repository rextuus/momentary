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
 * Schlägt Ereignisse vor: Medien ohne Ereignis, die zeitlich (und bei GPS-Fotos räumlich) zusammenhängen.
 *
 * Eine Lücke von mehr als $gapHours ohne Aufnahme trennt zwei Gruppen, ebenso ein Ortswechsel von mehr als
 * MAX_DISTANCE_KM zwischen zwei aufeinanderfolgenden Fotos mit GPS.
 */
class EventSuggester
{
    public const int DEFAULT_GAP_HOURS = 24;
    public const array GAP_OPTIONS = [6, 12, 24, 48, 72];

    /** Kleinere Gruppen sind meist Einzelaufnahmen, kein Ereignis */
    public const int MIN_MEDIA = 3;

    private const float MAX_DISTANCE_KM = 100.0;

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
        foreach (self::group($media, $gapHours) as $group) {
            if (\count($group) < self::MIN_MEDIA) {
                continue;
            }
            $suggestion = new EventSuggestion($group);
            if (!isset($dismissed[$suggestion->getKey()])) {
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
     * @param list<Medium> $media aufsteigend nach Aufnahmezeit
     *
     * @return list<list<Medium>>
     */
    public static function group(array $media, int $gapHours): array
    {
        $groups = [];
        $current = [];
        $previous = null;
        $lastLocated = null;
        foreach ($media as $medium) {
            if ($previous !== null) {
                $gap = (int) $medium->getDate()?->getTimestamp() - (int) $previous->getDate()?->getTimestamp();
                $moved =
                    $lastLocated !== null
                    && $medium instanceof Photo
                    && $medium->hasLocation()
                    && self::distanceKm($lastLocated, $medium) > self::MAX_DISTANCE_KM;
                if ($gap > ($gapHours * 3600) || $moved) {
                    $groups[] = $current;
                    $current = [];
                    $lastLocated = null;
                }
            }
            $current[] = $medium;
            $previous = $medium;
            if ($medium instanceof Photo && $medium->hasLocation()) {
                $lastLocated = $medium;
            }
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Entfernung zweier Fotos (Haversine).
     */
    public static function distanceKm(Photo $a, Photo $b): float
    {
        $lat1 = deg2rad((float) $a->getLatitude());
        $lat2 = deg2rad((float) $b->getLatitude());
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $b->getLongitude() - (float) $a->getLongitude());
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
