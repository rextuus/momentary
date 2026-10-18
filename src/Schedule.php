<?php

declare(strict_types=1);

namespace App;

use App\Message\ScanImportFolderMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Wiederkehrende Aufgaben; ausgeführt vom messenger-worker (Transport "scheduler_default").
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Import-Ordner überwachen (nur wenn IMPORT_FOLDER_WATCH=1, siehe ScanImportFolderMessageHandler)
            ->add(RecurringMessage::every('5 minutes', new ScanImportFolderMessage()));
    }
}
