<?php

declare(strict_types=1);

namespace App\Enum;

enum PhotoStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Wartet',
            self::PROCESSING => 'Wird analysiert',
            self::COMPLETED => 'Fertig',
            self::ERROR => 'Fehler',
        };
    }

    public function isPending(): bool
    {
        return $this === self::QUEUED || $this === self::PROCESSING;
    }
}
