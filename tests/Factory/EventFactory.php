<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Event;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Event>
 */
final class EventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Event::class;
    }

    protected function defaults(): array
    {
        return [
            'title' => self::faker()->words(2, true),
        ];
    }
}
