<?php

declare(strict_types=1);

namespace App\Tests\Integration\Photo;

use App\Entity\Photo;
use App\Entity\User;
use App\Service\Photo\PhotoBursts;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Unit\Service\Photo\PhotoFingerprintTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoBurstsTest extends KernelTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = UserFactory::createOne();
    }

    private function photo(string $time, int $scene = 1, float $sharpness = 100.0, ?User $owner = null): Photo
    {
        $photo = PhotoFactory::new()
            ->completed()
            ->ownedBy($owner ?? $this->owner)
            ->create([
                'takenAt' => new \DateTimeImmutable('2025-03-19 ' . $time, new \DateTimeZone('UTC')),
            ]);
        $photo->setImageMetrics(PhotoFingerprintTest::make($scene, $scene), $sharpness);
        $this->em()->flush();

        return $photo;
    }

    public function testGroupsSimilarPhotosTakenSecondsApartAndPicksTheSharpest(): void
    {
        $a = $this->photo('19:47:23', sharpness: 378.0);
        $b = $this->photo('19:47:26', sharpness: 1905.0);
        $c = $this->photo('19:47:28', sharpness: 1466.0);
        $later = $this->photo('19:48:00');
        $otherScene = $this->photo('19:47:30', scene: 4);

        self::assertSame(1, $this->bursts()->regroupAll());

        self::assertNull($b->getBurstCover(), 'das schärfste Foto steht für die Serie');
        self::assertSame($b, $a->getBurstCover());
        self::assertSame($b, $c->getBurstCover());
        self::assertSame([$a, $b, $c], $b->getBurst());
        self::assertNull($later->getBurstCover(), 'mehr als 10 Sekunden Abstand');
        self::assertNull($otherScene->getBurstCover(), 'anderes Motiv');
    }

    public function testFacesBeatSharpnessAndOwnersAreSeparate(): void
    {
        $sharp = $this->photo('12:00:00', sharpness: 2000.0);
        $withFace = $this->photo('12:00:02', sharpness: 500.0);
        VideoFaceFactory::createOne(['medium' => $withFace, 'video' => null]);
        $foreign = $this->photo('12:00:04', owner: UserFactory::createOne());
        $this->em()->refresh($withFace);

        $this->bursts()->regroupAll();

        self::assertSame($withFace, $sharp->getBurstCover());
        self::assertNull($foreign->getBurstCover());
        self::assertCount(0, $foreign->getBurstMembers());
    }

    public function testPinAndExclude(): void
    {
        $a = $this->photo('12:00:00', sharpness: 100.0);
        $b = $this->photo('12:00:02', sharpness: 900.0);
        $c = $this->photo('12:00:04', sharpness: 300.0);
        $bursts = $this->bursts();
        $bursts->regroupAround($a);
        self::assertSame($b, $a->getBurstCover());

        $bursts->pin($a);
        self::assertNull($a->getBurstCover());
        self::assertSame($a, $b->getBurstCover(), 'von Hand gewählt schlägt Schärfe');

        $bursts->exclude($b);
        self::assertNull($b->getBurstCover());
        self::assertCount(0, $b->getBurstMembers());
        self::assertNull($a->getBurstCover(), 'a und c liegen ohne b 4 Sekunden auseinander: weiter eine Serie');
        self::assertSame($a, $c->getBurstCover());

        $bursts->exclude($b, false);
        self::assertSame($a, $b->getBurstCover());
    }

    public function testPhotosWithoutFingerprintStayAlone(): void
    {
        $a = $this->photo('12:00:00');
        $b = $this->photo('12:00:02');
        $b->setImageMetrics(null, null);

        $this->bursts()->regroupAll();

        self::assertNull($a->getBurstCover());
        self::assertNull($b->getBurstCover());
    }

    private function bursts(): PhotoBursts
    {
        return self::getContainer()->get(PhotoBursts::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
