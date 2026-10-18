<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Video;

final readonly class VideoSearchHit
{
    /**
     * @param list<SearchMoment> $moments
     */
    public function __construct(
        public Video $video,
        public array $moments,
    ) {}
}
