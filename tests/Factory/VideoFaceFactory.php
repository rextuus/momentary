<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\VideoFace;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VideoFace>
 */
final class VideoFaceFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VideoFace::class;
    }

    protected function defaults(): array
    {
        return [
            'video' => VideoFactory::new(),
            'person' => PersonFactory::new(),
            'timestamp' => self::faker()->numberBetween(0, 600),
            'age' => self::faker()->numberBetween(1, 90),
            'gender' => 'Female',
            'emotion' => 'Happy',
        ];
    }
}
