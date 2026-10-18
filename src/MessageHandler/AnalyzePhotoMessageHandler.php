<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AnalyzePhotoMessage;
use App\Repository\PhotoRepository;
use App\Service\Photo\PhotoAnalyzer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnalyzePhotoMessageHandler
{
    public function __construct(
        private readonly PhotoRepository $photoRepository,
        private readonly PhotoAnalyzer $analyzer,
    ) {}

    public function __invoke(AnalyzePhotoMessage $message): void
    {
        $photo = $this->photoRepository->find($message->photoId);
        if ($photo === null) {
            // Foto wurde inzwischen gelöscht
            return;
        }

        // Fehler landen im Foto (Status "Fehler") und lassen sich über "Neu analysieren" wiederholen
        $this->analyzer->analyze($photo);
    }
}
