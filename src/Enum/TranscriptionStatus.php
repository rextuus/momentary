<?php

declare(strict_types=1);

namespace App\Enum;

enum TranscriptionStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';

    public function isPending(): bool
    {
        return $this === self::QUEUED || $this === self::RUNNING;
    }

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Wartet',
            self::RUNNING => 'Läuft',
            self::DONE => 'Fertig',
            self::FAILED => 'Fehlgeschlagen',
        };
    }
}
