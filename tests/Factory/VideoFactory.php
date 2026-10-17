<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Video;
use App\Enum\VideoStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Video>
 */
final class VideoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Video::class;
    }

    public function public(): self
    {
        return $this->with(['isPublic' => true]);
    }

    public function private(): self
    {
        return $this->with(['isPublic' => false]);
    }

    public function ownedBy(User|UserFactory $owner): self
    {
        return $this->with(['owner' => $owner]);
    }

    public function completed(): self
    {
        return $this->with(['status' => VideoStatus::COMPLETED]);
    }

    /**
     * @param list<UserGroup> $groups
     */
    public function sharedWith(array $groups): self
    {
        return $this->afterInstantiate(static function (Video $video) use ($groups): void {
            foreach ($groups as $group) {
                $video->addAllowedGroup($group);
            }
        });
    }

    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(3),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'status' => VideoStatus::PENDING,
            'isPublic' => false,
        ];
    }
}
