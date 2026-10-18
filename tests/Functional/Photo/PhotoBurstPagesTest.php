<?php

declare(strict_types=1);

namespace App\Tests\Functional\Photo;

use App\Entity\Photo;
use App\Entity\User;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Photo\PhotoBursts;
use App\Service\Photo\PhotoSearchIndex;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Unit\Service\Photo\PhotoFingerprintTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Serienfotos: nur das beste Foto in Listen, die übrigen aufklappbar.
 */
final class PhotoBurstPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $me;

    /** @var array{0: Photo, 1: Photo, 2: Photo} */
    private array $burst;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
        static::getContainer()->set(
            AmazonRekognitionService::class,
            $this->createStub(AmazonRekognitionService::class),
        );

        $this->me = UserFactory::createOne();
        $photos = [];
        foreach (['19:47:23' => 378.0, '19:47:26' => 1905.0, '19:47:28' => 1466.0] as $time => $sharpness) {
            $photo = PhotoFactory::new()
                ->completed()
                ->ownedBy($this->me)
                ->create([
                    'title' => 'Sonne ' . $time,
                    'takenAt' => new \DateTimeImmutable('2025-03-19 ' . $time, new \DateTimeZone('UTC')),
                ]);
            $photo->setImageMetrics(PhotoFingerprintTest::make(1), $sharpness);
            $photos[] = $photo;
        }
        $this->em()->flush();
        static::getContainer()->get(PhotoBursts::class)->regroupAll();
        $this->burst = [$photos[0], $photos[1], $photos[2]];
        $this->client->loginUser($this->me);
    }

    public function testTimelineAndPhotoListShowTheBestPhotoWithTheRestFolded(): void
    {
        foreach (['/timeline', '/photo/'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.burst-toggle'), $url);
            self::assertSame('3', trim($crawler->filter('.burst-toggle')->text()), $url);
            self::assertCount(
                2,
                $crawler->filter(sprintf('[data-burst-member="%d"].d-none', $this->burst[1]->getId())),
                $url,
            );
        }

        // Bei der Suche zählt jeder Treffer einzeln
        $crawler = $this->client->request('GET', '/photo/?q=Sonne');
        self::assertCount(0, $crawler->filter('.burst-toggle'));
        self::assertCount(3, $crawler->filter('.photo-tile'));
    }

    public function testEventPageFoldsOnlyPhotosOfTheEvent(): void
    {
        $event = EventFactory::createOne(['owner' => $this->me]);
        $event->addMedium($this->burst[0]);
        $event->addMedium($this->burst[1]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/event/' . $event->getId());

        self::assertSame('2', trim($crawler->filter('#event-media .burst-toggle')->text()));
        self::assertCount(1, $crawler->filter('#event-media [data-burst-member]'));
    }

    public function testPhotoPageShowsTheSeriesAndLetsTheOwnerPickTheBest(): void
    {
        $crawler = $this->client->request('GET', '/photo/' . $this->burst[0]->getId());
        self::assertSelectorTextContains('#burst', 'Serie mit 3 Fotos');
        self::assertCount(3, $crawler->filter('#burst .burst-thumb'));

        $this->client->submitForm('Als bestes Foto verwenden');
        self::assertResponseRedirects('/photo/' . $this->burst[0]->getId() . '#burst');

        $this->em()->clear();
        $first = $this->em()->find(Photo::class, $this->burst[0]->getId());
        self::assertNotNull($first);
        self::assertTrue($first->isBurstPinned());
        self::assertNull($first->getBurstCover());
        self::assertCount(2, $first->getBurstMembers());
    }

    public function testDeletingTheBestPhotoPromotesAnother(): void
    {
        $crawler = $this->client->request('GET', '/photo/' . $this->burst[1]->getId());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());

        $this->em()->clear();
        $third = $this->em()->find(Photo::class, $this->burst[2]->getId());
        self::assertNotNull($third);
        self::assertNull($third->getBurstCover(), 'das nächstschärfere Foto steht jetzt für die Serie');
        self::assertCount(1, $third->getBurstMembers());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
