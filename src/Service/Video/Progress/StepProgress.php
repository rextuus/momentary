<?php

declare(strict_types=1);

namespace App\Service\Video\Progress;

use App\Enum\VideoStatus;

final readonly class StepProgress
{
    public const string DONE = 'done';
    public const string ACTIVE = 'active';
    public const string PENDING = 'pending';
    public const string ERROR = 'error';

    /**
     * @param string     $state    done|active|pending|error
     * @param float|null $fraction 0..1, nur für den laufenden Schritt
     * @param bool       $counted  Fortschritt aus gezählten Elementen (sonst aus der Zeit geschätzt)
     * @param string     $detail   z. B. "12 von 40 Frames" oder "läuft seit 0:42"
     * @param int|null   $seconds  tatsächliche (fertig) bzw. bisherige (laufend) Dauer
     */
    public function __construct(
        public VideoStatus $step,
        public string $state,
        public ?float $fraction,
        public bool $counted,
        public string $detail,
        public ?int $seconds,
    ) {}

    public function percent(): int
    {
        return match ($this->state) {
            self::DONE => 100,
            self::ACTIVE => (int) floor(($this->fraction ?? 0.0) * 100),
            default => 0,
        };
    }
}
