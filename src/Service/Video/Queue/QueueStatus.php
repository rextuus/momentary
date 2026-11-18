<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

/**
 * Zustand einer RabbitMQ-Queue laut Management-API.
 */
final readonly class QueueStatus
{
    public function __construct(
        public string $name,
        public string $worker,
        public int $messages,
        public int $consumers,
    ) {}

    /**
     * Nachrichten warten, aber kein Worker holt sie ab.
     */
    public function isStuck(): bool
    {
        return $this->messages > 0 && $this->consumers === 0;
    }
}
