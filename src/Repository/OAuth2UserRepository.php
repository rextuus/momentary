<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\Bundle\OAuth2ServerBundle\Model\User as OAuth2User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OAuth2UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function getUserEntityByUserCredentials(
        string $username,
        string $password,
        string $grantType,
        ClientEntityInterface $clientEntity
    ): ?UserEntityInterface {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $username]);
        if ($user === null) {
            return null;
        }
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return null;
        }
        $entity = new OAuth2User();
        $entity->setIdentifier($user->getUserIdentifier());
        return $entity;
    }
}
