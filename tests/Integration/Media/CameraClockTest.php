<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Entity\CameraClockOffset;
use App\Entity\Medium;
use App\Service\Media\CameraClock;
use App\Service\Media\CameraOverview;
use App\Service\Media\RecordingTimeCorrector;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CameraClockTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
        self::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private static function medium(
        Medium $medium,
        string $cameraTime,
        ?string $camera,
        string $source = Medium::DATE_EXIF,
    ): Medium {
        $medium->setCamera($camera);
        $medium->setRecordedAt(self::utc($cameraTime), $source);

        return $medium;
    }

    public function testCorrectingAnEventRemembersTheOffsetForItsCameras(): void
    {
        $admin = UserFactory::new()->admin()->create();
        self::getContainer()
            ->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $photo = self::medium(PhotoFactory::createOne(), '2025-03-19 12:00', 'AKASO EK7000PRO');
        $video = self::medium(VideoFactory::createOne(), '2025-03-19 18:00', null, Medium::DATE_FILENAME);
        $event = EventFactory::createOne(['owner' => $admin]);
        $event->addMedium($photo);
        $event->addMedium($video);
        $this->em()->flush();

        $correct = self::utc('2026-05-23 12:00');
        $media = self::getContainer()->get(RecordingTimeCorrector::class)->correct($photo, $correct, $event);
        $this->clock()->remember($photo, $correct, $media, 'Mallorca');

        self::assertCount(2, $media);
        $offsets = $this->em()->getRepository(CameraClockOffset::class)->findAll();
        self::assertCount(1, $offsets);
        self::assertSame(['', 'AKASO EK7000PRO'], $offsets[0]->getCameras());
        self::assertSame(
            self::utc('2026-05-23 12:00')->getTimestamp() - self::utc('2025-03-19 12:00')->getTimestamp(),
            $offsets[0]->getOffsetSeconds(),
        );
        self::assertSame('2025-03-19 18:00', $offsets[0]->getCameraTo()->format('Y-m-d H:i'));
        self::assertSame(
            '2025-03-19 18:00',
            $video->getCameraTakenAt()?->format('Y-m-d H:i'),
            'Kamerazeit bleibt erhalten',
        );
        self::assertSame('2026-05-23 18:00', $video->getTakenAt()?->format('Y-m-d H:i'));
    }

    public function testRefusesToRememberWhenMediaAreFarApart(): void
    {
        $outlier = self::medium(PhotoFactory::createOne(), '1970-01-01 00:03', 'AKASO EK7000PRO');
        $normal = self::medium(PhotoFactory::createOne(), '2025-03-19 12:00', 'AKASO EK7000PRO');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ausreißer');
        $this->clock()->remember($outlier, self::utc('2026-05-23 12:00'), [$outlier, $normal], null);
    }

    public function testAdjustMatchesCameraAndTimeWindowOnly(): void
    {
        $this->offset(
            ['AKASO EK7000PRO', CameraClockOffset::UNKNOWN_CAMERA],
            '2025-03-18 00:00',
            '2025-03-20 00:00',
            86_400,
        );
        $clock = $this->clock();

        $akaso = self::medium(PhotoFactory::createOne(), '2025-03-22 10:00', 'AKASO EK7000PRO');
        $unknownVideo = self::medium(VideoFactory::createOne(), '2025-03-19 10:00', null, Medium::DATE_FILENAME);
        $phone = self::medium(PhotoFactory::createOne(), '2025-03-19 10:00', 'Apple iPhone 13');
        $tooLate = self::medium(PhotoFactory::createOne(), '2025-03-24 10:00', 'AKASO EK7000PRO');
        $manual = self::medium(PhotoFactory::createOne(), '2025-03-19 10:00', 'AKASO EK7000PRO', Medium::DATE_MANUAL);

        self::assertTrue($clock->adjust($akaso), 'im Spielraum von 3 Tagen');
        self::assertSame('2025-03-23 10:00', $akaso->getTakenAt()?->format('Y-m-d H:i'));
        self::assertSame(Medium::DATE_CAMERA_OFFSET, $akaso->getDateSource());
        self::assertFalse($clock->adjust($akaso), 'nicht doppelt verschieben');
        self::assertSame('2025-03-23 10:00', $akaso->getTakenAt()->format('Y-m-d H:i'));
        self::assertTrue($clock->adjust($unknownVideo), 'Videos ohne Kamerakennung');
        self::assertFalse($clock->adjust($phone), 'andere Kamera');
        self::assertFalse($clock->adjust($tooLate), 'außerhalb des Zeitfensters');
        self::assertFalse($clock->adjust($manual), 'von Hand korrigiert');
    }

    public function testApplyToExistingMedia(): void
    {
        $offset = $this->offset(['AKASO EK7000PRO'], '2025-03-19 00:00', '2025-03-19 23:00', -3600);
        $pending = self::medium(PhotoFactory::createOne(), '2025-03-19 12:00', 'AKASO EK7000PRO');
        self::medium(PhotoFactory::createOne(), '2025-03-19 12:00', 'Apple iPhone 13');
        $this->em()->flush();
        $clock = $this->clock();

        self::assertSame([$pending], $clock->pending($offset));
        self::assertSame(
            ['Für diese Kamera ist eine Abweichung von −1:00 Std. gemerkt, hier aber noch nicht angewendet.'],
            $clock->warnings($pending),
        );
        self::assertSame(1, $clock->applyToExisting($offset));
        self::assertSame('2025-03-19 11:00', $pending->getTakenAt()?->format('Y-m-d H:i'));
        self::assertSame([], $clock->pending($offset));
        self::assertSame([], $clock->warnings($pending));
    }

    public function testImplausibleDatesAndCameraOverview(): void
    {
        $reset = self::medium(PhotoFactory::createOne(), '1970-01-01 00:05', 'Billigkamera');
        $future = self::medium(VideoFactory::createOne(), '2099-01-01 10:00', null, Medium::DATE_FILENAME);
        self::medium(PhotoFactory::createOne(), '2025-03-19 12:00', 'Billigkamera');
        $this->em()->flush();
        $clock = $this->clock();

        $overview = self::getContainer()->get(CameraOverview::class);
        self::assertSame([$reset, $future], $overview->implausible());
        self::assertStringContainsString('1970', $clock->warnings($reset)[0]);
        self::assertStringContainsString('Zukunft', $clock->warnings($future)[0]);

        $billig = array_values(array_filter(
            $overview->cameras(),
            static fn(array $c): bool => $c['camera'] === 'Billigkamera',
        ))[0];
        self::assertSame(2, $billig['count']);
        self::assertSame('1970-01-01', $billig['first']?->format('Y-m-d'));
        self::assertSame('2025-03-19', $billig['last']?->format('Y-m-d'));
    }

    public function testOffsetLabel(): void
    {
        self::assertSame(
            '+430 Tage 0:05 Std.',
            new CameraClockOffset([], self::utc('now'), self::utc('now'), (430 * 86_400) + 300)->getOffsetLabel(),
        );
        self::assertSame(
            '−1 Tag 2:00 Std.',
            new CameraClockOffset([], self::utc('now'), self::utc('now'), -(86_400 + 7200))->getOffsetLabel(),
        );
    }

    /**
     * @param list<string> $cameras
     */
    private function offset(array $cameras, string $from, string $to, int $seconds): CameraClockOffset
    {
        $offset = new CameraClockOffset($cameras, self::utc($from), self::utc($to), $seconds);
        $this->em()->persist($offset);
        $this->em()->flush();

        return $offset;
    }

    private function clock(): CameraClock
    {
        return self::getContainer()->get(CameraClock::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
