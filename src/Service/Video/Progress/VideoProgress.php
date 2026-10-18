<?php

declare(strict_types=1);

namespace App\Service\Video\Progress;

final readonly class VideoProgress
{
    /**
     * @param list<StepProgress> $steps
     * @param int|null           $remainingSeconds geschätzte Restzeit, null wenn nichts läuft
     */
    public function __construct(
        public array $steps,
        public int $percent,
        public ?StepProgress $current,
        public ?int $remainingSeconds,
    ) {}

    public function remainingText(): ?string
    {
        return $this->remainingSeconds === null ? null : self::formatSeconds($this->remainingSeconds);
    }

    public static function formatSeconds(int $seconds): string
    {
        if ($seconds >= 3600) {
            return sprintf('%d:%02d h', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
