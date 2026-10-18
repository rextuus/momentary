<?php

declare(strict_types=1);

namespace App\Tests\Functional\Share;

use App\Entity\ShareLink;
use App\Entity\User;
use App\Enum\FilePurpose;
use App\Service\Share\ShareLinks;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ShareLinkTest extends WebTestCase
{
    // @mago-expect lint:no-literal-password
    private const string SECRET = 'sonne';
    // @mago-expect lint:no-literal-password
    private const string OTHER_SECRET = 'mond';
    private const string WRONG = 'falsch';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('share_limiter.cache')->clear();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->files);
        parent::tearDown();
    }

    public function testOwnerSharesAnEventAndStrangersSeeOnlyWhatTheOwnerMaySee(): void
    {
        $owner = UserFactory::createOne();
        $mine = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Am Strand']);
        $foreignPrivate = PhotoFactory::new()
            ->completed()
            ->ownedBy(UserFactory::createOne())
            ->create(['title' => 'Fremd und privat']);
        VideoFaceFactory::createOne([
            'medium' => $mine,
            'video' => null,
            'person' => PersonFactory::createOne(['name' => 'Oma']),
        ]);
        $event = EventFactory::createOne(['owner' => $owner, 'title' => 'Mallorca']);
        $event->addMedium($mine);
        $event->addMedium($foreignPrivate);
        $this->em()->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/event/' . $event->getId());
        self::assertSelectorExists('#share');
        $this->client->submitForm('Link erstellen', ['label' => 'für Oma']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '/s/');
        $link = $this->link();
        self::assertSame('für Oma', $link->getLabel());
        self::assertNotNull($link->getExpiresAt(), 'Standard: 30 Tage');

        $this->logout();
        $crawler = $this->client->request('GET', '/s/' . $link->getToken());
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorTextContains('h1', 'Mallorca');
        self::assertCount(1, $crawler->filter('.share-tile'), 'private Fotos anderer Leute teilt der Link nicht mit');
        self::assertStringNotContainsString(
            'Oma',
            (string) $this->client->getResponse()->getContent(),
            'keine Personen',
        );
        self::assertStringNotContainsString('Fremd und privat', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/s/%s/m/%d', $link->getToken(), $mine->getId()));
        self::assertSelectorTextContains('h1', 'Am Strand');
        $this->client->request('GET', sprintf('/s/%s/m/%d', $link->getToken(), $foreignPrivate->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/s/%s/download/%d', $link->getToken(), $mine->getId()));
        self::assertResponseStatusCodeSame(404, 'Herunterladen nicht erlaubt');

        $this->client->request('GET', '/event/' . $event->getId());
        self::assertResponseRedirects('/login', message: 'der Link öffnet nicht das übrige Archiv');
        self::assertSame(1, $this->link()->getViewCount());
    }

    public function testSharedVideoStreamsWithoutJellyfinAndSupportsSeeking(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::createOne(['owner' => $owner, 'title' => 'Pool']);
        $video->setConvertedFile($this->storedFile(
            'videos/share-test/converted.mp4',
            str_repeat('0123456789', 100),
            FilePurpose::VIDEO_CONVERTED,
        ));
        $this->em()->flush();
        $link = static::getContainer()->get(ShareLinks::class)->create($video, $owner);

        $this->client->request('GET', '/s/' . $link->getToken());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('/s/%s/video/%d', $link->getToken(), $video->getId()), $content);
        self::assertStringNotContainsString('api_key', $content);

        $this->client->request('GET', sprintf('/s/%s/video/%d', $link->getToken(), $video->getId()), server: [
            'HTTP_RANGE' => 'bytes=10-19',
        ]);
        self::assertResponseStatusCodeSame(206);
        self::assertSame('bytes 10-19/1000', $this->client->getResponse()->headers->get('Content-Range'));
    }

    public function testPasswordProtectionWithAttemptLimit(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Geheim']);
        $link = static::getContainer()->get(ShareLinks::class)->create($photo, $owner, '7 days', self::SECRET, true);

        $crawler = $this->client->request('GET', '/s/' . $link->getToken());
        self::assertSelectorTextContains('h1', 'Geschützter Link');
        self::assertStringNotContainsString('Geheim', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', sprintf('/s/%s/download/%d', $link->getToken(), $photo->getId()));
        self::assertResponseStatusCodeSame(404, 'ohne Passwort kein Download');

        $this->client->request('GET', '/s/' . $link->getToken());
        $this->unlockWith(self::WRONG);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert-danger', 'stimmt nicht');

        $this->unlockWith(self::SECRET);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Geheim');
        self::assertSelectorExists('a[href$="/download/' . $photo->getId() . '"]');

        // Nach 10 Versuchen ist für diesen Link und diese Adresse erst einmal Schluss
        $photo = $this->em()->find(\App\Entity\Photo::class, $photo->getId());
        $owner = $this->em()->find(User::class, $owner->getId());
        self::assertNotNull($photo);
        self::assertNotNull($owner);
        $other = static::getContainer()->get(ShareLinks::class)->create($photo, $owner, '7 days', self::OTHER_SECRET);
        for ($i = 0; $i < 10; $i++) {
            $this->client->request('GET', '/s/' . $other->getToken());
            $this->unlockWith(self::WRONG);
        }
        $this->unlockWith(self::OTHER_SECRET);
        self::assertSelectorTextContains('.alert-danger', 'Zu viele Versuche');
    }

    public function testExpiredRevokedAndUnknownLinksShowNothing(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Weg']);
        $shares = static::getContainer()->get(ShareLinks::class);
        $revoked = $shares->create($photo, $owner);
        $shares->revoke($revoked);
        $expired = $shares->create($photo, $owner, '1 day');
        $this->em()->getConnection()->executeStatement('UPDATE share_link SET expires_at = ? WHERE id = ?', [
            '2020-01-01 00:00:00',
            $expired->getId(),
        ]);

        foreach ([$revoked->getToken(), $expired->getToken(), str_repeat('x', 43), 'kurz'] as $token) {
            $this->client->request('GET', '/s/' . $token);
            self::assertResponseStatusCodeSame(404, $token);
            self::assertSelectorTextContains('h1', 'nicht (mehr) gültig');
        }
    }

    public function testOnlyOwnersShareAndOnlyCreatorsRevoke(): void
    {
        $owner = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->public()->ownedBy($owner)->create();
        $event = EventFactory::createOne(['owner' => $owner]);
        $link = static::getContainer()->get(ShareLinks::class)->create($photo, $owner);

        // Formular-Tokens aus dem eigenen Foto des Fremden (gleiche Sitzung)
        $own = PhotoFactory::new()->completed()->ownedBy($stranger)->create();
        static::getContainer()->get(ShareLinks::class)->create($own, $stranger);
        $this->client->loginUser($stranger);
        $crawler = $this->client->request('GET', '/photo/' . $own->getId());
        $token = (string) $crawler->filter('form[action$="/share/new"] input[name="_token"]')->attr('value');
        $revokeToken = (string) $crawler->filter('form[action$="/revoke"] input[name="_token"]')->attr('value');

        $crawler = $this->client->request('GET', '/photo/' . $photo->getId());
        self::assertCount(0, $crawler->filter('#share'), 'öffentlich sehen heißt nicht teilen dürfen');
        foreach ([['medium', $photo->getId()], ['event', $event->getId()]] as [$type, $id]) {
            $this->client->request('POST', '/share/new', ['_token' => $token, 'target' => $type, 'target_id' => $id]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', sprintf('/share/%d/revoke', $link->getId()), ['_token' => $revokeToken]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/share');
        self::assertCount(1, $crawler->filter('tbody tr'), 'nur die eigenen Links');
    }

    public function testOverviewAndRevokeByTheCreator(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Liste']);
        $link = static::getContainer()->get(ShareLinks::class)->create($photo, $owner, 'never', null, false, 'Test');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/share');
        self::assertSelectorTextContains('table', 'Liste');
        self::assertSelectorTextContains('table', 'unbegrenzt');
        $this->client->submit($crawler->filter('form[action$="/revoke"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('table', 'widerrufen');
        self::assertFalse($this->link()->isActive());
        $this->client->request('GET', '/s/' . $link->getToken());
        self::assertResponseStatusCodeSame(404);
    }

    private function storedFile(string $relativePath, string $content, FilePurpose $purpose): \App\Entity\File
    {
        $path = static::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($relativePath);
        new Filesystem()->dumpFile($path, $content);
        $this->files[] = \dirname($path);

        return FileFactory::createOne([
            'relativePath' => $relativePath,
            'purpose' => $purpose,
            'mimeType' => 'video/mp4',
        ]);
    }

    private function unlockWith(#[\SensitiveParameter] string $secret): void
    {
        $form = $this->client->getCrawler()->selectButton('Ansehen')->form();
        $form['password'] = $secret;
        $this->client->submit($form);
    }

    private function logout(): void
    {
        $this->client->getCookieJar()->clear();
        static::getContainer()->get('security.token_storage')->setToken(null);
    }

    private function link(): ShareLink
    {
        $this->em()->clear();
        $links = $this->em()->getRepository(ShareLink::class)->findAll();
        self::assertCount(1, $links);

        return $links[0];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
