<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\VideoScene;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VideoScene>
 */
final class VideoSceneFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VideoScene::class;
    }

    protected function defaults(): array
    {
        return [
            'video' => VideoFactory::new(),
            'sceneNumber' => 1,
            'startSeconds' => 0.0,
            'endSeconds' => 10.0,
            'isPublic' => false,
        ];
    }
}
