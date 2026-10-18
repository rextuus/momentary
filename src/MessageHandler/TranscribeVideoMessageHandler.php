<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TranscribeVideoMessage;
use App\Repository\VideoRepository;
use App\Service\Transcription\VideoTranscriber;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TranscribeVideoMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoTranscriber $transcriber,
    ) {}

    public function __invoke(TranscribeVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->videoId);
        if ($video === null) {
            // Video wurde inzwischen gelöscht
            return;
        }

        // Fehler landen im Transkript (Status "fehlgeschlagen") statt in Messenger-Retries:
        // eine stundenlange Transkription soll nicht automatisch wiederholt werden
        $this->transcriber->transcribe($video);
    }
}
