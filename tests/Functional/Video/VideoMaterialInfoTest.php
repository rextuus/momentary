<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

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
}
