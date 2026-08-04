<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use League\Bundle\OAuth2ServerBundle\Event\UserResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OAuth2UserResolveSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [OAuth2Events::USER_RESOLVE => 'onUserResolve'];
    }

    public function onUserResolve(UserResolveEvent $event): void
    {
        $user = $this->userRepository->findOneBy(['email' => $event->getUsername()]);
        if ($user === null) {
            return;
        }
        if (!$this->hasher->isPasswordValid($user, $event->getPassword())) {
            return;
        }
        $event->setUser($user);
    }
}
