<?php

declare(strict_types=1);

namespace App\Tests\Integration\Timeline;

use App\Entity\Medium;
use App\Service\Timeline\MediaTimeline;
use App\Service\Timeline\TimelineFilter;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaTimelineTest extends KernelTestCase
{
    private static function at(string $berlin): \DateTimeImmutable
    {
        return new \DateTimeImmutable($berlin, new \DateTimeZone('Europe/Berlin'))->setTimezone(
            new \DateTimeZone('UTC'),
        );
    }

    public function testPagesThroughVisibleMediaNewestFirst(): void
    {
        self::bootKernel();
        $me = UserFactory::createOne();
        $a = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-19 13:00')]);
        $b = VideoFactory::createOne(['owner' => $me, 'takenAt' => self::at('2025-03-19 22:00')]);
        $c = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-20 01:00')]);
        // gleiche Zeit: die ID entscheidet, kein Medium geht beim Blättern verloren
        $d = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-20 01:00')]);
        PhotoFactory::new()->ownedBy(UserFactory::createOne())->create(['takenAt' => self::at('2025-03-21 10:00')]);

        $timeline = self::getContainer()->get(MediaTimeline::class);
        $ids = [];
        $cursor = null;
        do {
            $page = $timeline->page($me, false, new TimelineFilter(), $cursor, 2);
            foreach ($page->segments as $segment) {
                foreach ($segment->days as $day) {
                    $ids = [...$ids, ...array_map(static fn(Medium $m): ?int => $m->getId(), $day->media)];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertSame([$d->getId(), $c->getId(), $b->getId(), $a->getId()], $ids, 'fremde private Medien fehlen');
    }

    public function testGroupsByEventAndGermanDay(): void
    {
        self::bootKernel();
        $me = UserFactory::createOne();
        $mallorca = EventFactory::createOne([
            'title' => 'Mallorca',
            'startDate' => new \DateTimeImmutable('2025-03-19'),
            'endDate' => new \DateTimeImmutable('2025-03-20'),
        ]);
        $party = EventFactory::createOne([
            'title' => 'Geburtstag',
            'startDate' => new \DateTimeImmutable('2025-03-20'),
        ]);
        $before = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-18 12:00')]);
        $day1 = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-19 23:30')]);
        $day2 = VideoFactory::createOne(['owner' => $me, 'takenAt' => self::at('2025-03-20 00:30')]);
        $mallorca->addMedium($day1);
        $mallorca->addMedium($day2);
        // gehört zu beiden: das kürzere Ereignis gewinnt
        $party->addMedium($day2);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $segments = self::getContainer()
            ->get(MediaTimeline::class)
            ->page($me, false, new TimelineFilter(), null)
            ->segments;

        $summary = array_map(static fn($s): array => [
            $s->event?->getTitle(),
            array_map(static fn($d): string => $d->date->format('d.m.'), $s->days),
        ], $segments);
        self::assertSame(
            [
                ['Geburtstag', ['20.03.']],
                ['Mallorca', ['19.03.']],
                [null, ['18.03.']],
            ],
            $summary,
            '23:30 deutscher Zeit bleibt am 19., 00:30 ist schon der 20.',
        );
        self::assertSame($before->getId(), $segments[2]->days[0]->media[0]->getId());
    }

    public function testFiltersByPersonAndType(): void
    {
        self::bootKernel();
        $me = UserFactory::createOne();
        $oma = PersonFactory::createOne(['name' => 'Oma']);
        $photoWithOma = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-01-01 10:00')]);
        $videoWithOma = VideoFactory::createOne(['owner' => $me, 'takenAt' => self::at('2025-01-02 10:00')]);
        PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-01-03 10:00')]);
        VideoFaceFactory::createOne(['medium' => $photoWithOma, 'person' => $oma]);
        VideoFaceFactory::createOne(['medium' => $videoWithOma, 'person' => $oma]);

        $timeline = self::getContainer()->get(MediaTimeline::class);
        $ids = static fn($page): array => array_merge(...array_map(static fn($s) => array_merge(...array_map(
            static fn($d) => array_map(static fn($m) => $m->getId(), $d->media),
            $s->days,
        )), $page->segments));

        self::assertSame(
            [$videoWithOma->getId(), $photoWithOma->getId()],
            $ids($timeline->page($me, false, new TimelineFilter($oma->getId()), null)),
        );
        self::assertSame(
            [$photoWithOma->getId()],
            $ids($timeline->page($me, false, new TimelineFilter($oma->getId(), TimelineFilter::PHOTOS), null)),
        );
    }

    public function testMonthsCountMediaAndJumpToTheirEnd(): void
    {
        self::bootKernel();
        $me = UserFactory::createOne();
        // 31.03. 23:30 deutscher Zeit ist in UTC schon April-frei: zählt zum März
        $endOfMarch = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-31 23:30')]);
        PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-04-02 10:00')]);
        PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::at('2025-03-01 10:00')]);

        $timeline = self::getContainer()->get(MediaTimeline::class);
        $months = $timeline->months($me, false, new TimelineFilter());

        self::assertSame(
            [[2025, 4, 1], [2025, 3, 2]],
            array_map(static fn($m): array => [$m['year'], $m['month'], $m['count']], $months),
        );
        $march = $timeline->page($me, false, new TimelineFilter(), $months[1]['cursor']);
        self::assertSame(
            $endOfMarch->getId(),
            $march->segments[0]->days[0]->media[0]->getId(),
            'Sprung beginnt mit dem letzten Medium im März',
        );
    }
}
