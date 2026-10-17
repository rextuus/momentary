<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Jellyfin\JellyfinUploadService;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

use function Zenstruck\Foundry\Persistence\refresh;

final class JellyfinExportTest extends WebTestCase
{
    private KernelBrowser $client;
    private JellyfinUploadService&MockObject $jellyfin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->jellyfin = $this->createMock(JellyfinUploadService::class);
        self::getContainer()->set(JellyfinUploadService::class, $this->jellyfin);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(
            self::getContainer()->get(StoragePathProvider::class)->getStorageRoot() . '/videos/jellyfin-test',
        );
        parent::tearDown();
    }

    public function testOwnerExportsCompletedVideo(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->completedVideoWithSourceFile($owner);

        $this->jellyfin
            ->expects(self::once())
            ->method('uploadVideo')
            ->with(self::stringEndsWith('/videos/jellyfin-test/clip.mp4'), 'clip.mp4')
            ->willReturn('/media/uploads/clip.mp4');
        $this->jellyfin->expects(self::once())->method('findItemIdByPath')->willReturn('item-42');

        $this->client->loginUser($owner);
        $this->clickExport($video);

        self::assertResponseRedirects('/video/' . $video->getId());
        $video = refresh($video);
        self::assertSame('/media/uploads/clip.mp4', $video->getJellyfinPath());
        self::assertSame('item-42', $video->getJellyfinItemId());
        self::assertSame(VideoStatus::COMPLETED, $video->getStatus());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success, .alert', 'Export nach Jellyfin wurde gestartet');
        self::assertSelectorTextContains('body', 'Bereits in Jellyfin vorhanden');
    }

    public function testFailedUploadKeepsVideoCompletedAndRecordsError(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->completedVideoWithSourceFile($owner);
        $this->jellyfin->expects(self::once())->method('uploadVideo')->willReturn(false);

        $this->client->loginUser($owner);
        $this->clickExport($video);

        $video = refresh($video);
        // Ein fehlgeschlagener Export darf die abgeschlossene Analyse nicht als fehlerhaft markieren
        self::assertSame(VideoStatus::COMPLETED, $video->getStatus());
        self::assertNull($video->getJellyfinPath());

        $step = $video
            ->getProcessingSteps()
            ->findFirst(static fn($key, $step): bool => $step->getStep() === VideoStatus::EXPORTING_JELLYFIN);
        self::assertNotNull($step);
        self::assertStringContainsString(
            'Failed to upload video to Jellyfin server',
            (string) $step->getErrorMessage(),
        );
    }

    public function testAlreadyExportedVideoIsNotUploadedAgain(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()
            ->completed()
            ->ownedBy($owner)
            ->create(['jellyfinPath' => '/media/a.mp4', 'jellyfinItemId' => 'item-1']);
        $this->jellyfin->expects(self::never())->method('uploadVideo');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/video/' . $video->getId());
        self::assertSelectorNotExists(sprintf('form[action="/jellyfin/export/%d"]', $video->getId()));
        self::assertSelectorTextContains('body', 'Bereits in Jellyfin vorhanden');
    }

    public function testExportWithoutValidCsrfTokenIsRejected(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->completedVideoWithSourceFile($owner);
        $this->jellyfin->expects(self::never())->method('uploadVideo');

        $this->client->loginUser($owner);
        // @mago-expect lint:no-literal-password
        $this->client->request('POST', '/jellyfin/export/' . $video->getId(), ['_token' => 'falsch']);

        self::assertResponseRedirects('/video/' . $video->getId());
        self::assertNull(refresh($video)->getJellyfinPath());
    }

    private function completedVideoWithSourceFile(\App\Entity\User $owner): Video
    {
        $relativePath = 'videos/jellyfin-test/clip.mp4';
        new Filesystem()->dumpFile(
            self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($relativePath),
            'video',
        );

        return VideoFactory::new()
            ->completed()
            ->ownedBy($owner)
            ->create([
                'sourceFile' => FileFactory::new()->videoSource($relativePath),
            ]);
    }

    private function clickExport(Video $video): void
    {
        $crawler = $this->client->request('GET', '/video/' . $video->getId());
        $form = $crawler->selectButton('Zu Jellyfin exportieren')->form();

        // Der Upload-Schritt läuft im Test-Env synchron und schreibt Fortschritt für das Worker-Log
        ob_start();
        try {
            $this->client->submit($form);
        } finally {
            ob_end_clean();
        }
    }
}
