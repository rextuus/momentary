<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiClientsTest extends WebTestCase
{
    // @mago-expect lint:no-literal-password
    private const string OWN_SECRET = 'hub-secret-aus-der-env-1234';
    // @mago-expect lint:no-literal-password
    private const string SHORT_SECRET = 'kurz';

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->admin = UserFactory::new()->admin()->create();
        $this->client->loginUser($this->admin);
    }

    private function token(string $clientId, #[\SensitiveParameter] string $secret): int
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'username' => $this->admin->getEmail(),
            'password' => UserFactory::DEFAULT_PASSWORD,
            'scope' => 'email',
        ]);

        return $this->client->getResponse()->getStatusCode();
    }

    private function secretShown(): string
    {
        return (string) $this->client
            ->getCrawler()
            ->filter('.border-success input[aria-label="Client-Secret"]')
            ->attr('value');
    }

    public function testCreateWithOwnSecretShowsItOnceAndItWorksForLogin(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        self::assertSelectorTextContains('.card-body', 'Noch kein Client');
        self::assertSame(
            'momentary-hub',
            $crawler->filter('#client-identifier')->attr('value'),
            'für den Hub vorbelegt',
        );

        $form = $crawler->selectButton('Anlegen')->form();
        $form['secret'] = self::OWN_SECRET;
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSame(self::OWN_SECRET, $this->secretShown());
        self::assertSelectorTextContains('pre.env-block', 'MOMENTARY_OAUTH2_CLIENT_ID=momentary-hub');

        $this->client->request('GET', '/admin/settings/api-clients');
        self::assertStringNotContainsString(
            self::OWN_SECRET,
            (string) $this->client->getResponse()->getContent(),
            'nur einmal sichtbar',
        );

        self::assertSame(200, $this->token('momentary-hub', self::OWN_SECRET));
        self::assertSame(401, $this->token('momentary-hub', 'falsch'));
    }

    public function testRegenerateDeactivateAndDelete(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler->selectButton('Anlegen')->form());
        $first = $this->secretShown();
        self::assertSame(64, \strlen($first), 'zufällig erzeugt');

        $this->client->submit($this->client->getCrawler()->selectButton('Erzeugen')->form());
        $second = $this->secretShown();
        self::assertNotSame($first, $second);
        self::assertSame(401, $this->token('momentary-hub', $first), 'altes Secret gilt nicht mehr');
        self::assertSame(200, $this->token('momentary-hub', $second));

        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler->selectButton('Deaktivieren')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.list-group', 'deaktiviert');
        self::assertSame(401, $this->token('momentary-hub', $second));

        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler->filter('button[value="delete"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.card-body', 'Noch kein Client');
    }

    public function testRejectsDuplicatesShortSecretsAndNonAdmins(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler->selectButton('Anlegen')->form());

        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler
            ->selectButton('Anlegen')
            ->form(['name' => 'Doppelt', 'identifier' => 'momentary-hub']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'gibt es schon');

        $crawler = $this->client->request('GET', '/admin/settings/api-clients');
        $this->client->submit($crawler
            ->selectButton('Anlegen')
            ->form(['name' => 'App', 'identifier' => 'momentary-app', 'secret' => self::SHORT_SECRET]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'mindestens 16 Zeichen');

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/admin/settings/api-clients');
        self::assertResponseStatusCodeSame(403);
    }
}
