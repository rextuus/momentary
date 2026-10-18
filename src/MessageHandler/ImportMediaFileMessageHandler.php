<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\ImportMediaFileMessage;
use App\Service\Media\ImportFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportMediaFileMessageHandler
{
    public function __construct(
        private readonly ImportFolder $importFolder,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(ImportMediaFileMessage $message): void
    {
        $owner = $message->ownerId !== null ? $this->entityManager->find(User::class, $message->ownerId) : null;
        $this->importFolder->import($message->relativePath, $owner);
    }
}
