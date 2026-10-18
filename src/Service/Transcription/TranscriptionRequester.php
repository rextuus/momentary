<?php

declare(strict_types=1);

namespace App\Service\Transcription;

use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Enum\VideoStatus;
use App\Message\TranscribeVideoMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stellt Videos in die Transkriptions-Queue (Button auf der Videoseite, Command).
 */
class TranscriptionRequester
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Grund, warum das Video gerade nicht transkribiert werden kann, oder null.
     */
    public function whyNot(Video $video): ?string
    {
        if ($video->getStatus() !== VideoStatus::COMPLETED) {
            return 'Die Transkription ist erst möglich, wenn die Analyse des Videos abgeschlossen ist.';
        }
        if ($video->getConvertedFile() === null && $video->getSourceFile() === null) {
            return 'Das Video hat keine Videodatei mehr.';
        }
        if ($video->getTranscript()?->getStatus()->isPending()) {
            return 'Die Transkription ist bereits eingeplant oder läuft.';
        }

        return null;
    }

    public function request(Video $video): bool
    {
        if ($this->whyNot($video) !== null) {
            return false;
        }

        $transcript = $video->getTranscript() ?? new VideoTranscript($video);
        $transcript->queue();
        $this->entityManager->persist($transcript);
        $this->entityManager->flush();

        $this->bus->dispatch(new TranscribeVideoMessage((int) $video->getId()));

        return true;
    }
}
