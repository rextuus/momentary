<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Holt über den Password-Grant ein echtes Access-Token für die stateless API-Firewall.
 *
 * Die OAuth2-Persistenz ist im Test-Env "in_memory", deshalb muss der Browser
 * mit disableReboot() laufen, damit Client und Token denselben Kernel teilen.
 */
trait OAuth2ClientTrait
{
    private function authenticateApiClient(KernelBrowser $browser, User $user): void
    {
        $browser->disableReboot();

        $client = new Client('test', 'test-client', 'test-secret');
        $client->setGrants(new Grant('password'));
        $client->setScopes(new Scope('email'));
        self::getContainer()->get(ClientManagerInterface::class)->save($client);

        $browser->request('POST', '/token', [
            'grant_type' => 'password',
            'client_id' => 'test-client',
            // @mago-expect lint:no-literal-password
            'client_secret' => 'test-secret',
            'username' => $user->getUserIdentifier(),
            'password' => UserFactory::DEFAULT_PASSWORD,
            'scope' => 'email',
        ]);

        self::assertResponseIsSuccessful('Token-Endpunkt hat kein Access-Token geliefert');
        $token = json_decode(
            (string) $browser->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        )['access_token'];

        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    }
}
