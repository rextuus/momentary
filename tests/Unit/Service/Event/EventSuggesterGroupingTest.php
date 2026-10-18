<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\Video;
use App\Service\Event\EventSuggester;
use App\Service\Event\EventSuggestion;
use PHPUnit\Framework\TestCase;

final class EventSuggesterGroupingTest extends TestCase
{
    private static int $id = 0;

    private static function photo(string $utc, ?float $lat = null, ?float $lon = null): Photo
    {
        $photo = new Photo()
            ->setTitle('p')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setTakenAt(new \DateTimeImmutable($utc));
        $photo->setLocation($lat, $lon);
        new \ReflectionProperty(Medium::class, 'id')->setValue($photo, ++self::$id);

        return $photo;
    }

    /**
     * @param list<EventSuggestion> $groups
     *
     * @return list<int>
     */
    private static function sizes(array $groups): array
    {
        return array_map(static fn(EventSuggestion $s): int => \count($s->media), $groups);
    }

    public function testGapLongerThanThresholdStartsNewGroup(): void
    {
        $media = [
            self::photo('2026-05-20 08:00'),
            self::photo('2026-05-20 22:00'),
            self::photo('2026-05-21 09:00'), // Nacht: 11 h Lücke
            self::photo('2026-05-25 10:00'), // vier Tage später
        ];

        self::assertSame([3, 1], self::sizes(EventSuggester::group($media, 24)));
        self::assertSame(
            [1, 2, 1],
            self::sizes(EventSuggester::group($media, 12)),
            '14 h tagsüber trennen, 11 h Nacht nicht',
        );
    }

    public function testFarApartLocationsSplitEvenWithoutGap(): void
    {
        $media = [
            self::photo('2026-05-20 08:00', 52.52, 13.40), // Berlin
            self::photo('2026-05-20 09:00'), // ohne GPS: zählt zur laufenden Gruppe
            self::photo('2026-05-20 12:00', 53.55, 9.99), // Hamburg, ~255 km
            self::photo('2026-05-20 13:00', 53.56, 10.00),
        ];

        self::assertSame([2, 2], self::sizes(EventSuggester::group($media, 24)));
    }

    public function testTripAwayFromHomeStaysTogetherAcrossPlacesAndQuietDays(): void
    {
        $home = EventSuggester::homeOf([
            [52.52, 13.40],
            [52.51, 13.41],
            [52.53, 13.39],
            [52.52, 13.42],
            [52.50, 13.40],
            [39.57, 2.65],
        ]);
        self::assertNotNull($home);
        self::assertEqualsWithDelta(52.52, $home[0], 0.05, 'Berlin ist zuhause');

        $media = [
            self::photo('2026-05-18 10:00', 52.52, 13.40), // zuhause
            self::photo('2026-05-18 18:00', 52.52, 13.40),
            self::photo('2026-05-19 08:00'), // Flughafen, ohne GPS: gehört noch zum Tag zuhause
            self::photo('2026-05-19 14:00', 39.57, 2.65), // Palma
            self::photo('2026-05-20 11:00', 39.37, 3.22), // Cala d'Or: anderer Ort, gleiche Reise
            self::photo('2026-05-22 19:00', 39.35, 3.13), // zwei Tage ohne Aufnahme: Reise hält
            self::photo('2026-05-23 12:00', 52.52, 13.40), // wieder zuhause
        ];

        $groups = EventSuggester::group($media, 24, $home);

        self::assertSame([3, 3, 1], self::sizes($groups));
        self::assertSame([false, true, false], array_map(static fn(EventSuggestion $s): bool => $s->trip, $groups));
    }

    public function testNoHomeWithTooFewLocations(): void
    {
        self::assertNull(EventSuggester::homeOf([[52.52, 13.40], [39.57, 2.65]]));
    }

    public function testDistance(): void
    {
        self::assertEqualsWithDelta(
            255.0,
            EventSuggester::distanceKm(self::photo('2026-01-01', 52.52, 13.40), self::photo('2026-01-01', 53.55, 9.99)),
            5.0,
        );
    }

    public function testKeyDependsOnlyOnTheMedia(): void
    {
        $a = self::photo('2026-05-20 08:00');
        $b = self::photo('2026-05-20 09:00');

        self::assertSame(new EventSuggestion([$a, $b])->getKey(), new EventSuggestion([$b, $a])->getKey());
        self::assertNotSame(
            new EventSuggestion([$a, $b])->getKey(),
            new EventSuggestion([$a, $b, self::photo('2026-05-20 10:00')])->getKey(),
        );
    }

    public function testCountsByType(): void
    {
        $video = new Video()
            ->setTitle('v')
            ->setCreatedAt(new \DateTimeImmutable('2026-05-20 08:30'));
        $suggestion = new EventSuggestion([self::photo('2026-05-20 08:00'), $video, self::photo('2026-05-20 09:00')]);

        self::assertSame([1, 2], [$suggestion->getVideoCount(), $suggestion->getPhotoCount()]);
    }
}
