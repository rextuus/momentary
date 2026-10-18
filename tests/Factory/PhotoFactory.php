<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Photo;
use App\Entity\User;
use App\Enum\FilePurpose;
use App\Enum\PhotoStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Photo>
 */
final class PhotoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Photo::class;
    }

    public function public(): self
    {
        return $this->with(['isPublic' => true]);
    }

    public function ownedBy(User|UserFactory $owner): self
    {
        return $this->with(['owner' => $owner]);
    }

    /**
     * Fertig analysiertes Foto mit Anzeige-Fassung und Vorschau.
     */
    public function completed(): self
    {
        return $this->with([
            'status' => PhotoStatus::COMPLETED,
            'displayFile' => FileFactory::new(['purpose' => FilePurpose::PHOTO_DISPLAY]),
            'thumbnailFile' => FileFactory::new(['purpose' => FilePurpose::PHOTO_THUMBNAIL]),
        ]);
    }

    protected function defaults(): array
    {
        return [
            'title' => self::faker()->words(3, true),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
        ];
    }
}
