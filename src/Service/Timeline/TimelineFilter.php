<?php

declare(strict_types=1);

namespace App\Service\Timeline;

final readonly class TimelineFilter
{
    public const string ALL = 'all';
    public const string VIDEOS = 'video';
    public const string PHOTOS = 'photo';

    public function __construct(
        public ?int $personId = null,
        public string $type = self::ALL,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        return array_filter(
            ['person' => $this->personId, 'type' => $this->type === self::ALL ? null : $this->type],
            static fn($value): bool => $value !== null,
        );
    }
}
