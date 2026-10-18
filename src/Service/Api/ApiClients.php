<?php

declare(strict_types=1);

namespace App\Service\Api;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * OAuth2-Clients für Hub und App (Password Grant + Refresh Token).
 *
 * Secrets speichert das Bundle nur als Hash: Sie lassen sich beim Anlegen oder Neu-Erzeugen genau einmal anzeigen.
 */
class ApiClients
{
    /** Was Hub und App brauchen (siehe documentation/clients/API.md) */
    public const array GRANTS = ['password', 'refresh_token'];
    public const string SCOPE = 'email';

    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * @return list<ClientInterface>
     */
    public function all(): array
    {
        $clients = $this->clientManager->list(null);
        usort($clients, static fn(ClientInterface $a, ClientInterface $b): int => strcasecmp(
            $a->getName(),
            $b->getName(),
        ));

        return $clients;
    }

    public function find(string $identifier): ?ClientInterface
    {
        return $this->clientManager->find($identifier);
    }

    /**
     * @return string das Secret im Klartext (nur jetzt verfügbar)
     */
    public function create(string $name, string $identifier, #[\SensitiveParameter] ?string $secret = null): string
    {
        $name = trim($name);
        $identifier = trim($identifier);
        if ($name === '' || preg_match('/^[A-Za-z0-9._-]{3,64}$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(
                'Name fehlt oder die Client-ID ist ungültig (3–64 Zeichen: Buchstaben, Ziffern, . _ -).',
            );
        }
        if ($this->clientManager->find($identifier) !== null) {
            throw new \InvalidArgumentException(sprintf('Die Client-ID „%s“ gibt es schon.', $identifier));
        }

        $plain = $this->secret($secret);
        $client = new Client($name, $identifier, $this->passwordHasher->hash($plain));
        $client->setActive(true);
        $client->setGrants(...array_map(static fn(string $grant): Grant => new Grant($grant), self::GRANTS));
        $client->setScopes(new Scope(self::SCOPE));
        $this->clientManager->save($client);

        return $plain;
    }

    /**
     * @return string das neue Secret im Klartext; das alte gilt ab sofort nicht mehr
     */
    public function regenerateSecret(ClientInterface $client, #[\SensitiveParameter] ?string $secret = null): string
    {
        if (!$client instanceof Client) {
            throw new \LogicException('Unbekannter Client-Typ.');
        }
        $plain = $this->secret($secret);
        $client->setSecret($this->passwordHasher->hash($plain));
        $this->clientManager->save($client);

        return $plain;
    }

    public function setActive(ClientInterface $client, bool $active): void
    {
        $client->setActive($active);
        $this->clientManager->save($client);
    }

    public function remove(ClientInterface $client): void
    {
        $this->clientManager->remove($client);
    }

    private function secret(#[\SensitiveParameter] ?string $secret): string
    {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return bin2hex(random_bytes(32));
        }
        if (\strlen($secret) < 16) {
            throw new \InvalidArgumentException('Ein eigenes Secret braucht mindestens 16 Zeichen.');
        }

        return $secret;
    }
}
