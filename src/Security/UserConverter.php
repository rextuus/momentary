<?php

namespace App\Security;

use App\Repository\UserRepository;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserConverter implements UserConverterInterface
{
    public function __construct(private readonly UserRepository $userRepository) {}

    public function toLeague(UserInterface $user): \League\OAuth2\Server\Entities\UserEntityInterface
    {
        $entity = new \League\Bundle\OAuth2ServerBundle\Model\User();
        $entity->setIdentifier($user->getUserIdentifier());
        return $entity;
    }
}
