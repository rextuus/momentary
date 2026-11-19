<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

/**
 * Ergebnis von read_dv_subcodes.py.
 */
final readonly class DvSubcodes
{
    /**
     * @param list<DvRun> $runs
     */
    public function __construct(
        public string $system,
        public float $fps,
        public int $frames,
        public array $runs,
    ) {}

    public function hasAnyDate(): bool
    {
        foreach ($this->runs as $run) {
            if ($run->cameraTime !== null) {
                return true;
            }
        }

        return false;
    }
}
