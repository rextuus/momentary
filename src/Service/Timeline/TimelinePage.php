<?php

declare(strict_types=1);

namespace App\Service\Timeline;

final readonly class TimelinePage
{
    /**
     * @param list<TimelineSegment> $segments
     */
    public function __construct(
        public array $segments,
        public int $count,
        /** Cursor für die nächste (ältere) Seite, null wenn es keine gibt */
        public ?string $nextCursor,
    ) {}
}
