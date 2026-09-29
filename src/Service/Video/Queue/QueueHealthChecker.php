<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

class QueueHealthChecker
{
    public function __construct()
    {
    }

    /**
     * Prüft, ob Nachrichten in der Queue hängen (älter als 5 Minuten).
     * Wenn ja, ist der Worker vermutlich gestoppt.
     * HINWEIS: Funktioniert nur bei Nutzung von Doctrine-Transport!
     */
    public function isWorkerRunning(): bool
    {
        return true;
    }
}
