<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\File;
use App\Enum\FilePurpose;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<File>
 */
final class FileFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return File::class;
    }

    public function videoSource(string $relativePath = 'videos/source.mp4'): self
    {
        return $this->with([
            'relativePath' => $relativePath,
            'mimeType' => 'video/mp4',
            'purpose' => FilePurpose::VIDEO_SOURCE,
        ]);
    }

    public function videoThumbnail(string $relativePath = 'images/videos/thumbnail.jpg'): self
    {
        return $this->with([
            'relativePath' => $relativePath,
            'mimeType' => 'image/jpeg',
            'purpose' => FilePurpose::THUMBNAIL_VIDEO,
        ]);
    }

    protected function defaults(): array
    {
        return [
            'relativePath' => 'images/' . self::faker()->unique()->uuid() . '.jpg',
            'mimeType' => 'image/jpeg',
            'purpose' => FilePurpose::THUMBNAIL_SCENE,
        ];
    }
}
