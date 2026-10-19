<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\Medium;
use App\Entity\PoolRule;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\MediumRepository;
use App\Security\VisibleMedia;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pools: Regeln (alle Bedingungen einer Regel, irgendeine Regel), einzeln hinzugefügt, ausgenommen.
 * Abfrage (MediumRepository) und Einzelprüfung (VisibleMedia, Voter) müssen übereinstimmen.
 */
final class PoolVisibilityTest extends KernelTestCase
{
    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testPoolMembersSeeExactlyThePoolMedia(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $mother = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $homeVideos = TagFactory::createOne(['name' => 'Heimvideos']);

        $media = [
            'heim1998' => VideoFactory::createOne(['owner' => $admin, 'takenAt' => self::utc('1998-06-01 12:00')]),
            'heim2004' => PhotoFactory::new()->ownedBy($admin)->create(['takenAt' => self::utc('2004-12-31 20:00')]),
            'heim2010' => VideoFactory::createOne(['owner' => $admin, 'takenAt' => self::utc('2010-05-01 12:00')]),
            'ohneTag1999' => VideoFactory::createOne(['owner' => $admin, 'takenAt' => self::utc('1999-01-01 12:00')]),
            'weihnachten' => VideoFactory::createOne(['owner' => $admin, 'takenAt' => self::utc('2001-12-24 18:00')]),
            'mallorca' => PhotoFactory::new()->ownedBy($admin)->create(['takenAt' => self::utc('2026-05-23 12:00')]),
            'mallorcaEinzeln' => PhotoFactory::new()->ownedBy($admin)->create([
                'takenAt' => self::utc('2026-05-24 12:00'),
            ]),
            'ausgenommen' => VideoFactory::createOne(['owner' => $admin, 'takenAt' => self::utc('1998-07-01 12:00')]),
            'oeffentlich' => PhotoFactory::new()->public()->ownedBy($admin)->create(),
            'eigenes' => PhotoFactory::new()->ownedBy($mother)->create(),
        ];
        foreach (['heim1998', 'heim2004', 'heim2010', 'ausgenommen'] as $key) {
            $media[$key]->addTag($homeVideos);
        }
        $christmas = EventFactory::createOne(['owner' => $admin, 'title' => 'Weihnachten 2001']);
        $christmas->addMedium($media['weihnachten']);
        $mallorca = EventFactory::createOne(['owner' => $admin, 'title' => 'Mallorca']);
        $mallorca->addMedium($media['mallorca']);
        $mallorca->addMedium($media['mallorcaEinzeln']);

        $pool = UserGroupFactory::new()->withMembers([$mother])->create(['name' => 'Familie', 'owner' => $admin]);
        $pool->addRule(
            new PoolRule($pool)
                ->setTag($homeVideos)
                ->setPeriod(null, self::utc('2004-12-31')),
        );
        $pool->addRule(new PoolRule($pool)->setEvent($christmas));
        $media['mallorcaEinzeln']->addAllowedGroup($pool);
        $pool->exclude($media['ausgenommen']);
        // Ein Pool, in dem die Mutter nicht ist, gibt ihr nichts frei
        $other = UserGroupFactory::new()->withMembers([$stranger])->create(['owner' => $admin]);
        $other->addRule(new PoolRule($other)->setEvent($mallorca));
        $this->em()->flush();

        $expected = ['heim1998', 'heim2004', 'weihnachten', 'mallorcaEinzeln', 'oeffentlich', 'eigenes'];
        self::assertEqualsCanonicalizing($expected, $this->visibleKeys($media, $mother));
        self::assertEqualsCanonicalizing(
            ['mallorca', 'mallorcaEinzeln', 'oeffentlich'],
            $this->visibleKeys($media, $stranger),
        );
        self::assertEqualsCanonicalizing(array_keys($media), $this->visibleKeys($media, $admin));
        self::assertEqualsCanonicalizing(['oeffentlich'], $this->visibleKeys($media, null));
    }

    public function testNewMediaMatchingARuleAppearAutomatically(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $mother = UserFactory::createOne();
        $event = EventFactory::createOne(['owner' => $admin]);
        $pool = UserGroupFactory::new()->withMembers([$mother])->create(['owner' => $admin]);
        $pool->addRule(new PoolRule($pool)->setEvent($event));
        $this->em()->flush();

        $later = VideoFactory::createOne(['owner' => $admin]);
        self::assertFalse(self::getContainer()->get(VisibleMedia::class)->isVisible($mother, $later));
        $event->addMedium($later);
        $this->em()->flush();
        self::getContainer()->get(VisibleMedia::class)->reset();

        self::assertTrue(self::getContainer()->get(VisibleMedia::class)->isVisible($mother, $later));
    }

    public function testRuleNeedsACondition(): void
    {
        self::assertFalse(new PoolRule(new UserGroup())->hasCondition());
        self::assertSame(
            'aufgenommen bis 31.12.2004',
            new PoolRule(new UserGroup())
                ->setPeriod(null, self::utc('2004-12-31'))
                ->describe(),
        );
    }

    /**
     * @param array<string, Medium> $media
     *
     * @return list<string>
     */
    private function visibleKeys(array $media, ?User $user): array
    {
        $isAdmin = VisibleMedia::isAdmin($user);
        $qb = $this->em()->createQueryBuilder()->select('m.id')->from(Medium::class, 'm');
        MediumRepository::restrictToVisible($qb, 'm', $user, $isAdmin);
        $ids = array_map(intval(...), $qb->getQuery()->getSingleColumnResult());

        $visibleMedia = self::getContainer()->get(VisibleMedia::class);
        $visibleMedia->reset();
        $keys = [];
        foreach ($media as $key => $medium) {
            $byQuery = \in_array($medium->getId(), $ids, true);
            self::assertSame(
                $byQuery,
                $visibleMedia->isVisible($user, $medium),
                'Abfrage und Einzelprüfung stimmen überein: ' . $key,
            );
            if ($byQuery) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
