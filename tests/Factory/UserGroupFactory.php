<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UserGroup>
 */
final class UserGroupFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return UserGroup::class;
    }

    /**
     * @param list<User> $users
     */
    public function withMembers(array $users): self
    {
        return $this->afterInstantiate(static function (UserGroup $group) use ($users): void {
            foreach ($users as $user) {
                $group->addMember(new UserGroupMember()->setUser($user));
            }
        });
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->words(2, true),
            'owner' => UserFactory::new(),
        ];
    }
}
