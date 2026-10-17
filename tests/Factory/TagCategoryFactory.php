<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TagCategory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<TagCategory>
 */
final class TagCategoryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return TagCategory::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
        ];
    }
}
