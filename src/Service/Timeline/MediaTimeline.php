<?php

declare(strict_types=1);

namespace App\Service\Timeline;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Repository\MediumRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Alle sichtbaren Videos und Fotos nach Aufnahmezeit, neueste zuerst.
 *
 * Geblättert wird mit einem Cursor ("<Unix-Zeit>.<ID>" des letzten Mediums) statt mit Seitenzahlen: neu importierte
 * Medien verschieben so keine Seiten, und ein Sprung zu einem Monat ist einfach ein Cursor auf dessen Ende.
 */
class MediaTimeline
{
    public const int PAGE_SIZE = 90;

    private const string SORT_DATE = 'COALESCE(m.takenAt, m.createdAt)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function page(
        ?User $user,
        bool $isAdmin,
        TimelineFilter $filter,
        ?string $cursor,
        int $limit = self::PAGE_SIZE,
    ): TimelinePage {
        $qb = $this
            ->query($user, $isAdmin, $filter)
            ->select('m')
            ->addSelect(self::SORT_DATE . ' AS HIDDEN sortDate')
            ->orderBy('sortDate', \SortDirection::Descending)
            ->addOrderBy('m.id', \SortDirection::Descending)
            ->setMaxResults($limit + 1);

        $parsed = self::parseCursor($cursor);
        if ($parsed !== null) {
            $qb
                ->andWhere(sprintf('%1$s < :cursorDate OR (%1$s = :cursorDate AND m.id < :cursorId)', self::SORT_DATE))
                ->setParameter('cursorDate', $parsed[0])
                ->setParameter('cursorId', $parsed[1]);
        }

        /** @var list<Medium> $media */
        $media = $qb->getQuery()->getResult();
        $hasMore = \count($media) > $limit;
        $media = \array_slice($media, 0, $limit);

        $last = $media[\count($media) - 1] ?? null;
        $next = $hasMore && $last !== null
            ? self::cursor((int) $last->getDate()?->getTimestamp(), (int) $last->getId())
            : null;

        return new TimelinePage(self::segments($media), \count($media), $next);
    }

    /**
     * Monate mit Anzahl Medien, neueste zuerst, jeweils mit Cursor zum Hinspringen.
     *
     * @return list<array{year: int, month: int, count: int, cursor: string}>
     */
    public function months(?User $user, bool $isAdmin, TimelineFilter $filter): array
    {
        $dates = $this
            ->query($user, $isAdmin, $filter)
            ->select(self::SORT_DATE . ' AS sortDate')
            ->getQuery()
            ->getSingleColumnResult();

        $zone = new \DateTimeZone('Europe/Berlin');
        $counts = [];
        foreach ($dates as $date) {
            $local = new \DateTimeImmutable(
                \is_string($date) ? $date : $date->format('Y-m-d H:i:s'),
                new \DateTimeZone('UTC'),
            )->setTimezone($zone);
            $key = $local->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        krsort($counts);

        $months = [];
        foreach ($counts as $key => $count) {
            [$year, $month] = array_map(intval(...), explode('-', (string) $key));
            // Alles vor dem Beginn des Folgemonats (deutsche Zeit) = ab dem Ende dieses Monats abwärts
            $end = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $zone)->modify('+1 month');
            $months[] = [
                'year' => $year,
                'month' => $month,
                'count' => $count,
                'cursor' => self::cursor($end->getTimestamp(), PHP_INT_MAX),
            ];
        }

        return $months;
    }

    /**
     * In Segmente (gleiches Ereignis hintereinander) und darin nach Tagen (deutsche Zeit) gliedern.
     *
     * @param list<Medium> $media
     *
     * @return list<TimelineSegment>
     */
    public static function segments(array $media): array
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $segments = [];
        $segment = null;
        foreach ($media as $medium) {
            $date = ($medium->getDate() ?? new \DateTimeImmutable())->setTimezone($zone);
            $event = self::primaryEvent($medium);

            if ($segment === null || $segment->event !== $event) {
                $segment = new TimelineSegment($event, $date->format('Y-m'));
                $segments[] = $segment;
            }

            $day = $segment->days[\count($segment->days) - 1] ?? null;
            if ($day === null || $day->date->format('Y-m-d') !== $date->format('Y-m-d')) {
                $day = new TimelineDay($date->setTime(0, 0));
                $segment->days[] = $day;
            }
            $day->media[] = $medium;
        }

        return $segments;
    }

    /**
     * Gehört ein Medium zu mehreren Ereignissen, zählt das mit dem kürzesten Zeitraum (das spezifischste).
     */
    private static function primaryEvent(Medium $medium): ?Event
    {
        $best = null;
        $bestLength = PHP_INT_MAX;
        foreach ($medium->getEvents() as $event) {
            $start = $event->getStartDate();
            $length = $start === null ? PHP_INT_MAX - 1 : (int) $start->diff($event->getEndDate() ?? $start)->days;
            if ($length < $bestLength || $length === $bestLength && $event->getId() < $best?->getId()) {
                $best = $event;
                $bestLength = $length;
            }
        }

        return $best;
    }

    private function query(?User $user, bool $isAdmin, TimelineFilter $filter): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->from(Medium::class, 'm');
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);

        if ($filter->type === TimelineFilter::VIDEOS) {
            $qb->andWhere(sprintf('m INSTANCE OF %s', Video::class));
        } elseif ($filter->type === TimelineFilter::PHOTOS) {
            $qb->andWhere(sprintf('m INSTANCE OF %s', Photo::class));
        }
        if ($filter->personId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s tf WHERE tf.medium = m AND tf.person = :timelinePerson)',
                VideoFace::class,
            ))->setParameter('timelinePerson', $filter->personId);
        }

        return $qb;
    }

    public static function cursor(int $timestamp, int $id): string
    {
        return $timestamp . '.' . $id;
    }

    /**
     * @return array{\DateTimeImmutable, int}|null
     */
    private static function parseCursor(?string $cursor): ?array
    {
        if ($cursor === null || preg_match('/^(\d{1,12})\.(\d{1,19})$/', $cursor, $match) !== 1) {
            return null;
        }

        return [
            new \DateTimeImmutable('@' . $match[1])->setTimezone(new \DateTimeZone('UTC')),
            // Monatssprünge nutzen PHP_INT_MAX ("alles an diesem Zeitpunkt"); Überlauf ebenfalls als Maximum lesen
            filter_var($match[2], FILTER_VALIDATE_INT) ?: PHP_INT_MAX,
        ];
    }
}
