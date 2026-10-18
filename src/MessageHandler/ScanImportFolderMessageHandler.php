<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ScanImportFolderMessage;
use App\Service\Media\ImportFolder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ScanImportFolderMessageHandler
{
    public function __construct(
        private readonly ImportFolder $importFolder,
    ) {}

    public function __invoke(ScanImportFolderMessage $message): void
    {
        if ($this->importFolder->isWatched()) {
            $this->importFolder->queue();
        }
    }
}
