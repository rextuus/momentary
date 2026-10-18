<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Photo;

final readonly class MediaSearchResult
{
    /**
     * @param list<VideoSearchHit> $videos
     * @param list<Photo>          $photos
     * @param array<string, int>   $persons Facette: Person => Anzahl Treffer
     * @param array<string, int>   $tags    Facette: Tag => Anzahl Treffer
     * @param list<string>         $terms   Suchbegriffe zum Hervorheben
     */
    public function __construct(
        public array $videos = [],
        public array $photos = [],
        public array $persons = [],
        public array $tags = [],
        public array $terms = [],
        public ?string $error = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->videos === [] && $this->photos === [];
    }
}
