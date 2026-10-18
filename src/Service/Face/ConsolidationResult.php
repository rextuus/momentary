<?php

declare(strict_types=1);

namespace App\Service\Face;

final readonly class ConsolidationResult
{
    public function __construct(
        public int $faces,
        public int $personsBefore,
        public int $personsAfter,
        public int $reassignedFaces,
        public int $removedPersons,
    ) {}
}
