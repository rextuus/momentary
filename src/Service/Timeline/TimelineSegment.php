<?php

declare(strict_types=1);

namespace App\Service\Timeline;

use App\Entity\Event;

/**
 * Aufeinanderfolgende Medien desselben Ereignisses (oder ohne Ereignis), nach Tagen gegliedert.
 */
final class TimelineSegment
{
    /** @var list<TimelineDay> */
    public array $days = [];

    public function __construct(
        public readonly ?Event $event,
        /** Monat, in dem das Segment beginnt ("2025-03"), für die Monatsüberschriften */
        public readonly string $month,
    ) {}
}
