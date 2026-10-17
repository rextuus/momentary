<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

trait VoterTestTrait
{
    private static int $nextId = 1;

    /**
     * @param list<string> $roles
     */
    private static function user(array $roles = []): User
    {
        $user = new User()
            ->setEmail(sprintf('user%d@example.com', self::$nextId))
            ->setRoles($roles);

        return self::withId($user);
    }

    /**
     * @param list<User> $members
     */
    private static function group(array $members): UserGroup
    {
        $group = self::withId(new UserGroup()->setName('Gruppe'));
        foreach ($members as $member) {
            $group->addMember(new UserGroupMember()->setUser($member));
        }

        return $group;
    }

    private static function tokenFor(?User $user): TokenInterface
    {
        return $user === null ? new NullToken() : new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * Doctrine vergibt IDs erst beim Persistieren; die Voter vergleichen aber über IDs.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function withId(object $entity): object
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, self::$nextId++);

        return $entity;
    }
}
