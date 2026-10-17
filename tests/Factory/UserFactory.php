<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use App\Entity\UserSettings;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    // @mago-expect lint:no-literal-password
    public const DEFAULT_PASSWORD = 'password';

    public static function class(): string
    {
        return User::class;
    }

    public function admin(): self
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }

    public function editor(): self
    {
        return $this->with(['roles' => ['ROLE_EDITOR']]);
    }

    protected function defaults(): array
    {
        // Ein einmal berechneter, billiger bcrypt-Hash reicht für Tests
        static $passwordHash = null;
        $passwordHash ??= password_hash(self::DEFAULT_PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]);

        return [
            'email' => self::faker()->unique()->safeEmail(),
            'password' => $passwordHash,
            'roles' => [],
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            if ($user->getSettings() === null) {
                $user->setSettings(new UserSettings());
            }
        });
    }
}
