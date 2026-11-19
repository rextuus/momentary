<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

/**
 * Zwischenstand beim Bilden der Segmente (RecordingSegmentBuilder): Bereich im Video in Sekunden und geprüfte
 * Kamerazeit am Anfang (Ortszeit), null = kein gültiges Datum.
 */
final readonly class RecordingRange
{
    /** Spielraum beim Fortsetzen: Kamerazeit hat nur Sekunden, Bandfehler verschlucken einzelne Frames */
    private const int CONTINUATION_SLACK_SECONDS = 3;

    public function __construct(
        public float $start,
        public float $end,
        public ?\DateTimeImmutable $time,
        public bool $recStart,
    ) {}

    public function duration(): float
    {
        return $this->end - $this->start;
    }

    public function withStart(float $start): self
    {
        return new self($start, $this->end, $this->time, $this->recStart);
    }

    public function withEnd(float $end): self
    {
        return new self($this->start, $end, $this->time, $this->recStart);
    }

    /**
     * Gehört $next zur selben Aufnahme? Beide ohne Datum, oder die Kamerazeit läuft nahtlos weiter und die Kamera
     * hat keinen Aufnahmebeginn markiert.
     */
    public function isContinuedBy(self $next): bool
    {
        if ($this->time === null || $next->time === null) {
            return $this->time === null && $next->time === null;
        }
        $expected = $this->time->getTimestamp() + (int) round($this->duration());

        return !$next->recStart && abs($next->time->getTimestamp() - $expected) <= self::CONTINUATION_SLACK_SECONDS;
    }
}
