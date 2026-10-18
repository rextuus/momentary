<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Enum\VideoStatus;
use App\Service\Transcription\VideoTranscriber;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TranscribeVideoCommandTest extends KernelTestCase
{
    public function testMissingQueuesOnlyFinishedVideosWithoutTranscript(): void
    {
        $kernel = self::bootKernel();

        $withoutTranscript = VideoFactory::new()->completed()->create([
            'sourceFile' => FileFactory::new()->videoSource('videos/1/a.mov'),
        ]);
        $alreadyDone = VideoFactory::new()->completed()->create([
            'sourceFile' => FileFactory::new()->videoSource('videos/2/b.mov'),
        ]);
        $stillProcessing = VideoFactory::createOne(['status' => VideoStatus::TAGGING_SCENES]);
        $done = new VideoTranscript($alreadyDone);
        $done->start('small', 10.0);
        $done->complete('de', []);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($done);
        $entityManager->flush();

        // Im Test-Env läuft die Nachricht synchron: der Transkriber zeigt, welche Videos angekommen sind
        $transcribed = [];
        $transcriber = $this->createStub(VideoTranscriber::class);
        $transcriber
            ->method('transcribe')
            ->willReturnCallback(static function (Video $video) use (&$transcribed): VideoTranscript {
                $transcribed[] = $video->getId();

                return $video->getTranscript() ?? new VideoTranscript($video);
            });
        self::getContainer()->set(VideoTranscriber::class, $transcriber);

        $tester = new CommandTester(new Application($kernel)->find('app:video:transcribe'));
        $tester->execute(['--missing' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([$withoutTranscript->getId()], $transcribed);
        self::assertStringContainsString('1 Video(s) zur Transkription eingeplant', $tester->getDisplay());
        self::assertNotContains($stillProcessing->getId(), $transcribed);
    }
}
