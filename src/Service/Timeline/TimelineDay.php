<?php

declare(strict_types=1);

namespace App\Service\Timeline;

use App\Entity\Medium;

final class TimelineDay
{
    /** @var list<Medium> */
    public array $media = [];

    public function __construct(
        /** Tag in deutscher Zeit */
        public readonly \DateTimeImmutable $date,
    ) {}
}
