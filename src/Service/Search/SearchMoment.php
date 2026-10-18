<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * Eine Fundstelle in einem Video: gesprochener Satz, Person oder Tag an einer bestimmten Sekunde.
 */
final readonly class SearchMoment
{
    public const string SPOKEN = 'spoken';
    public const string PERSON = 'person';
    public const string TAG = 'tag';

    public function __construct(
        public string $kind,
        public int $seconds,
        public string $text,
        /** Relativer Storage-Pfad des Szenen-Vorschaubilds, falls vorhanden */
        public ?string $thumbnailPath = null,
    ) {}

    public function getTime(): string
    {
        return sprintf('%d:%02d', intdiv($this->seconds, 60), $this->seconds % 60);
    }
}
