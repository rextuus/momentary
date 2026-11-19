<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\RecordingSegment;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Videoseite zeigt, ob das Material interlaced ist und welche native Auflösung es hat (für eine spätere
 * KI-Aufbereitung des Originals).
 */
final class VideoMaterialInfoTest extends WebTestCase
{
    public function testInterlacedTapeShowsFieldOrderAndNativeHeight(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->completed()->ownedBy($owner)->create(['sourceFile' => FileFactory::new()]);
        $video->setFieldOrder('bb')->setSourceHeight(576);
        static::getContainer()->get('doctrine')->getManager()->flush();

        $client->loginUser($owner);
        $client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'interlaced, unteres Halbbild zuerst');
        self::assertSelectorTextContains('body', 'das Original bleibt erhalten');
        self::assertSelectorTextContains('body', 'Native Auflösung: 576 Zeilen');
    }

    public function testUncheckedVideoSaysSo(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->completed()->ownedBy($owner)->create(['sourceFile' => FileFactory::new()]);

        $client->loginUser($owner);
        $client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Halbbilder noch nicht geprüft');
    }

    public function testDvCassetteListsItsRecordings(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->completed()->ownedBy($owner)->create(['sourceFile' => FileFactory::new()]);
        $video->replaceRecordingSegments([
            new RecordingSegment(0.0, 62.48, new \DateTimeImmutable('2004-07-12 12:33:05', new \DateTimeZone('UTC'))),
            new RecordingSegment(62.48, 90.0, null),
            new RecordingSegment(90.0, 125.5, new \DateTimeImmutable('2000-01-01 00:05:00'))->setSuspicious(true),
            new RecordingSegment(125.5, 130.0, new \DateTimeImmutable('2005-12-24 17:00:00'))->setRecurringDate(true),
        ]);
        static::getContainer()->get('doctrine')->getManager()->flush();

        $client->loginUser($owner);
        $client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#recording-segments', '0:00–1:02 12.07.2004 14:33:05');
        self::assertSelectorTextContains('#recording-segments', '1:02–1:30 ohne Datum');
        self::assertSelectorTextContains('#recording-segments', 'Werksdatum');
        self::assertSelectorTextContains('#recording-segments', 'prüfen');
    }
}
