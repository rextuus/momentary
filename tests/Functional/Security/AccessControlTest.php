<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\VideoChapterFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function Zenstruck\Foundry\Persistence\persist;
use function Zenstruck\Foundry\Persistence\refresh;

final class AccessControlTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function testUserCanLogInWithCorrectCredentials(): void
    {
        UserFactory::createOne(['email' => 'anna@example.com']);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'anna@example.com',
            '_password' => UserFactory::DEFAULT_PASSWORD,
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        // Familienmitglieder (ohne Bearbeiter-Rolle) landen vom Dashboard auf der Zeitachse
        self::assertResponseRedirects('/timeline');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        UserFactory::createOne(['email' => 'anna@example.com']);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'anna@example.com',
            // @mago-expect lint:no-literal-password
            '_password' => 'falsch',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        yield 'Pools' => ['/admin/pools'];
        yield 'Benutzer' => ['/admin/users'];
        yield 'Video-Admin' => ['/admin/video/'];
        yield 'Import-Ordner' => ['/admin/import'];
        yield 'Queue' => ['/admin/queue'];
        yield 'FTP-Dateimanager' => ['/admin/files/ftp'];
        yield 'Quick Resolve' => ['/admin/quick-resolve'];
        yield 'Tinder Resolve' => ['/admin/tinder-resolve'];
        yield 'Identitäten' => ['/admin/resolve-identities'];
        yield 'Personenliste' => ['/admin/simple-person-list'];
        yield 'Tag-Verwaltung' => ['/tagging/tagging'];
    }

    #[DataProvider('adminPages')]
    public function testRegularUserCannotOpenAdminPages(string $url): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminPages')]
    public function testAdminCanOpenAdminPages(string $url): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('adminPages')]
    public function testEditorCannotOpenAdminPages(string $url): void
    {
        $this->client->loginUser(UserFactory::new()->editor()->create());
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotRunPersonCleanup(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('POST', '/person/cleanup');

        self::assertResponseStatusCodeSame(403);
    }

    public function testProcessingStepDetailsAreAdminOnly(): void
    {
        $step = persist(VideoProcessingStep::class, [
            'video' => VideoFactory::new()->public(),
            'step' => VideoStatus::CONVERTING,
        ]);
        $url = '/video/admin/video/processing-step/' . $step->getId();

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testAdminCanViewAnyPrivateVideo(): void
    {
        $video = VideoFactory::new()->private()->ownedBy(UserFactory::new())->create();

        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
    }

    public function testOwnerCanViewOwnPrivateVideo(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->private()->ownedBy($owner)->create();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
    }

    public function testGroupMemberCanViewSharedVideo(): void
    {
        $member = UserFactory::createOne();
        $group = UserGroupFactory::new()->withMembers([$member])->create();
        $video = VideoFactory::new()->private()->ownedBy(UserFactory::new())->sharedWith([$group])->create();

        $this->client->loginUser($member);
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
    }

    public function testStrangerCannotViewPrivateVideo(): void
    {
        $video = VideoFactory::new()->private()->ownedBy(UserFactory::new())->create();

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testStrangerCannotViewTimelineOrChapterOfPrivateVideo(): void
    {
        $video = VideoFactory::new()->private()->ownedBy(UserFactory::new())->create();
        $chapter = VideoChapterFactory::createOne(['video' => $video]);

        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/video/' . $video->getId() . '/timeline');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/video/chapter/' . $chapter->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnyUserCanViewPublicVideo(): void
    {
        $video = VideoFactory::new()->public()->ownedBy(UserFactory::new())->create();

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertResponseIsSuccessful();
    }

    public function testStrangerCannotDeletePublicVideo(): void
    {
        $video = VideoFactory::new()->public()->ownedBy(UserFactory::new())->create();
        $videoId = $video->getId();

        // Die Berechtigung wird vor dem CSRF-Token geprüft, ein Token ist daher nicht nötig
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('POST', '/video/' . $videoId . '/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::getContainer()->get(VideoRepository::class)->find($videoId));
    }

    public function testGroupMemberCannotDeleteSharedVideo(): void
    {
        $member = UserFactory::createOne();
        $group = UserGroupFactory::new()->withMembers([$member])->create();
        $video = VideoFactory::new()->private()->ownedBy(UserFactory::new())->sharedWith([$group])->create();

        $this->client->loginUser($member);
        $this->client->request('POST', '/video/' . $video->getId() . '/delete');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanDeleteOwnVideo(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->private()->ownedBy($owner)->create();
        $videoId = $video->getId();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/video/');
        $form = $crawler->filter(sprintf('form[action="/video/%d/delete"]', $videoId))->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/video/');
        self::assertNull(self::getContainer()->get(VideoRepository::class)->find($videoId));
    }

    public function testDeleteButtonIsOnlyShownToOwnerOrAdmin(): void
    {
        $owner = UserFactory::createOne();
        $own = VideoFactory::new()->public()->ownedBy($owner)->create();
        $foreign = VideoFactory::new()->public()->ownedBy(UserFactory::new())->create();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/video/');

        self::assertCount(1, $crawler->filter(sprintf('form[action="/video/%d/delete"]', $own->getId())));
        self::assertCount(0, $crawler->filter(sprintf('form[action="/video/%d/delete"]', $foreign->getId())));
    }

    public function testStrangerCannotChangeSceneVisibility(): void
    {
        $video = VideoFactory::new()->public()->ownedBy(UserFactory::new())->create();
        $scene = VideoSceneFactory::createOne(['video' => $video, 'isPublic' => false]);
        $chapter = VideoChapterFactory::createOne(['video' => $video]);

        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', '/video/' . $video->getId() . '/update-scenes-public', ['isPublic' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/video/chapter/' . $chapter->getId() . '/update-scenes-public', [
            'isPublic' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertFalse(refresh($scene)->isPublic());
    }

    public function testOwnerCanMakeAllScenesPublic(): void
    {
        $owner = UserFactory::createOne();
        $video = VideoFactory::new()->private()->ownedBy($owner)->create();
        $scene = VideoSceneFactory::createOne(['video' => $video, 'isPublic' => false]);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/video/' . $video->getId());
        $this->client->submit($crawler->selectButton('Alle Szenen öffentlich')->form());

        self::assertResponseRedirects('/video/' . $video->getId());
        self::assertTrue(refresh($scene)->isPublic());
    }

    public function testStrangerCannotTriggerJellyfinExportOrAnalysisReset(): void
    {
        $video = VideoFactory::new()->public()->completed()->ownedBy(UserFactory::new())->create();

        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', '/jellyfin/export/' . $video->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/video/' . $video->getId() . '/reprocess');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/video/' . $video->getId() . '/extract-thumbnail');
        self::assertResponseStatusCodeSame(403);
    }

    public function testApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/known-faces');

        self::assertResponseStatusCodeSame(401);
    }
}
