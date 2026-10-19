<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Medium;
use App\Entity\UserGroup;
use App\Service\Pool\Pools;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Pool-Karte auf Video- und Fotoseiten: in welchen Pools das Medium ist (über Regel, einzeln, ausgenommen) und
 * Knöpfe zum Aufnehmen bzw. Herausnehmen.
 */
#[AsTwigComponent]
final class MediumPoolsComponent
{
    public Medium $medium;

    public function __construct(
        private readonly Pools $pools,
    ) {}

    /**
     * @return list<array{pool: UserGroup, status: string}>
     */
    public function getEntries(): array
    {
        return array_map(fn(UserGroup $pool): array => [
            'pool' => $pool,
            'status' => $this->pools->status($pool, $this->medium),
        ], $this->pools->all());
    }
}
