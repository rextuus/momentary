<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\VideoChapter;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VideoChapter>
 */
final class VideoChapterFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VideoChapter::class;
    }

    protected function defaults(): array
    {
        return [
            'video' => VideoFactory::new(),
            'title' => self::faker()->words(3, true),
            'startSeconds' => 0.0,
            'endSeconds' => 60.0,
        ];
    }
}
