<?php

declare(strict_types=1);

namespace App\Service\Geo;

final readonly class Place
{
    public function __construct(
        /** Ort (Stadt, Dorf, Stadtteil), falls bekannt */
        public ?string $locality,
        public ?string $country,
    ) {}

    public function getName(): ?string
    {
        $parts = array_values(array_filter([$this->locality, $this->country]));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
