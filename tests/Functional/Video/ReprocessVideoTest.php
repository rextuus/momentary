<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Enum\VideoStatus;
use App\Service\Video\Queue\ImportQueueManager;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function Zenstruck\Foundry\Persistence\refresh;

final class ReprocessVideoTest extends WebTestCase
{
    private KernelBrowser $client;
    private ImportQueueManager&MockObject $queue;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->queue = $this->createMock(ImportQueueManager::class);
        self::getContainer()->set(ImportQueueManager::class, $this->queue);
    }

    public function testOwnerRestartsFailedAnalysis(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->ownedBy($owner)->create([
            'status' => VideoStatus::ERROR,
            'sourceFile' => FileFactory::new()->videoSource('videos/9/clip.mov'),
        ]);
        $this->queue->expects(self::once())->method('processQueue')->willReturn(1);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/video/' . $video->getId());
        self::assertSelectorTextContains('.alert-danger', 'Analyse fehlgeschlagen');

        $this->client->submit($crawler->selectButton('Analyse neu starten')->form());

        self::assertResponseRedirects('/video/' . $video->getId());
        self::assertSame(VideoStatus::QUEUED, refresh($video)->getStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'neu gestartet');
    }

    public function testRunningVideoOffersNoRestart(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->ownedBy($owner)->create([
            'status' => VideoStatus::TAGGING_SCENES,
            'sourceFile' => FileFactory::new()->videoSource('videos/9/clip.mov'),
        ]);
        $this->queue->expects(self::never())->method('processQueue');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/video/' . $video->getId());

        self::assertCount(0, $crawler->filter(sprintf('form[action="/video/%d/reprocess"]', $video->getId())));
        self::assertCount(1, $crawler
            ->filter('button[disabled]')
            ->reduce(static fn($button) => str_contains($button->text(), 'Analyse neu starten')));
    }

    public function testStrangerCannotRestartAnalysis(): void
    {
        $video = VideoFactory::new()->public()->ownedBy(UserFactory::new())->create(['status' => VideoStatus::ERROR]);
        $this->queue->expects(self::never())->method('processQueue');

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('POST', '/video/' . $video->getId() . '/reprocess');

        self::assertResponseStatusCodeSame(403);
    }
}
